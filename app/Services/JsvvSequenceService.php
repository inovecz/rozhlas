<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessRecordingPlaylist;
use App\Jobs\RunJsvvSequence;
use App\Libraries\PythonClient;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastSession;
use App\Models\JsvvEvent;
use App\Models\JsvvSequence;
use App\Models\JsvvSequenceItem;
use App\Models\StreamTelemetryEntry;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use App\Services\StreamOrchestrator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class JsvvSequenceService extends Service
{
    private const RUNNER_LOCK_KEY = 'jsvv:sequence:runner';
    private const ACTIVE_LOCK_KEY = 'jsvv:sequence:active';
    private const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
    ) {
        parent::__construct();
    }

    public function plan(array $items, array $options = []): array
    {
        $response = $this->client->planJsvvSequence($items, $options);
        $data = $response['json']['data'] ?? $response['json'] ?? [];
        $resolvedSequence = Arr::get($data, 'sequence', []);

        return DB::transaction(function () use ($data, $items, $options, $resolvedSequence): array {
            $sequence = JsvvSequence::create([
                'items' => $items,
                'options' => $options,
                'priority' => Arr::get($options, 'priority'),
                'status' => 'planned',
            ]);

            $totalEstimatedDuration = 0.0;

            foreach ($items as $index => $item) {
                $resolved = $resolvedSequence[$index] ?? null;
                if ($resolved === null) {
                    Log::warning('JSVV sequence planning resolved metadata missing', [
                        'sequence_id' => $sequence->id,
                        'index' => $index,
                        'item' => $item,
                    ]);
                }
                $metadata = $this->buildSequenceItemMetadata($item, $resolved);
                $repeat = (int) max(1, $item['repeat'] ?? 1);
                $perItemDuration = $this->detectItemDurationSeconds($item['category'] ?? 'verbal', $metadata);
                if ($perItemDuration !== null) {
                    $metadata['duration_seconds'] = $perItemDuration;
                }
                if ($perItemDuration !== null) {
                    $totalEstimatedDuration += $perItemDuration * $repeat;
                }

                JsvvSequenceItem::create([
                    'sequence_id' => $sequence->id,
                    'position' => $index,
                    'category' => $item['category'] ?? 'verbal',
                    'slot' => $item['slot'],
                    'voice' => $item['voice'] ?? null,
                    'repeat' => (int) max(1, $item['repeat'] ?? 1),
                    'metadata' => $metadata,
                ]);
            }

            $holdSeconds = (float) Arr::get($data, 'holdSeconds', 0);
            if ($holdSeconds > 0) {
                $totalEstimatedDuration += $holdSeconds;
            }

            if ($totalEstimatedDuration > 0) {
                $sequence->update([
                    'estimated_duration_seconds' => $totalEstimatedDuration,
                ]);
            }

            return $sequence->fresh()->toArray() + ['plan' => $data];
        });
    }

    public function trigger(string $sequenceId): array
    {
        $sequence = JsvvSequence::query()->find($sequenceId);
        if ($sequence === null) {
            return [
                'status' => 'not_found',
                'id' => $sequenceId,
            ];
        }

        if (!in_array($sequence->status, ['planned', 'queued'], true)) {
            return $sequence->toArray();
        }

        if ($sequence->status === 'planned') {
            $sequence->update([
                'status' => 'queued',
                'queued_at' => now(),
            ]);
        }

        RunJsvvSequence::dispatch();

        return $sequence->fresh()->toArray() + [
            'queue_position' => $this->calculateQueuePosition($sequence->id),
        ];
    }

    public function listSequences(): array
    {
        return JsvvSequence::query()->with('sequenceItems')->orderByDesc('created_at')->limit(100)->get()->toArray();
    }

    public function getAssets(?string $category = null, ?int $slot = null, ?string $voice = null): array
    {
        $response = $this->client->listJsvvAssets(
            slot: $slot,
            voice: $voice,
            includePaths: false,
            category: $category,
        );

        return $response['json']['data']['assets']
            ?? $response['json']['assets']
            ?? $response['json']
            ?? [];
    }

    public function recordEvent(array $payload): void
    {
        JsvvEvent::create([
            'command' => Arr::get($payload, 'command'),
            'mid' => Arr::get($payload, 'mid'),
            'priority' => Arr::get($payload, 'priority'),
            'duplicate' => (bool) Arr::get($payload, 'duplicate', false),
            'payload' => $payload,
        ]);
    }

    public function processQueuedSequences(): void
    {
        $lock = Cache::lock(self::RUNNER_LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (!$lock->get()) {
            return;
        }

        try {
            while (true) {
                $sequence = $this->claimNextSequence();
                if ($sequence === null) {
                    break;
                }
                $this->runSequence($sequence);
            }
        } finally {
            $lock->release();
        }
    }

    private function claimNextSequence(): ?JsvvSequence
    {
        return DB::transaction(function (): ?JsvvSequence {
            /** @var JsvvSequence|null $sequence */
            $sequence = JsvvSequence::query()
                ->where('status', 'queued')
                ->orderByRaw($this->buildPriorityOrderClause())
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                return null;
            }

            $sequence->update([
                'status' => 'running',
                'triggered_at' => now(),
                'error_message' => null,
                'failed_at' => null,
            ]);

            return $sequence->fresh();
        });
    }

    private function runSequence(JsvvSequence $sequence): void
    {
        $sequenceStart = microtime(true);
        $useLocalStream = $this->useLocalStreamPlayback();
        $estimatedDuration = $this->getSequenceEstimatedDuration($sequence);
        $sessionId = null;

        try {
            $this->setActiveSequenceLock($sequence->id);
            if ($useLocalStream) {
                $this->preemptActiveSession();
            }

            $items = $sequence->sequenceItems()->orderBy('position')->get();

            if ($useLocalStream) {
                $session = $this->orchestrator->start([
                    'source' => 'jsvv',
                    'route' => Arr::get($sequence->options, 'route', []),
                    'zones' => Arr::get($sequence->options, 'zones', []),
                    'options' => ['priority' => $sequence->priority],
                ]);
                $sessionId = is_array($session) ? Arr::get($session, 'id') : null;
            }

            $this->logSequenceEvent($sequence, 'started', [
                'playback_mode' => $useLocalStream ? 'local_stream' : 'remote_trigger',
                'estimated_duration_seconds' => $estimatedDuration,
            ], $sessionId);

            $this->notifyJsvvSms($sequence);

            $sendFramesToUnits = !$useLocalStream;
            foreach ($items as $item) {
                $this->refreshActiveSequenceLock($sequence->id);
                $category = $item->category;
                $slot = $item->slot;
                $repeat = (int) max(1, $item->repeat);

                for ($i = 0; $i < $repeat; $i++) {
                    if ($category === 'siren') {
                        $this->client->triggerJsvvFrame('SIREN', [(string) $slot], $sendFramesToUnits, true);
                    } else {
                        $voice = $item->voice ?? 'male';
                        $this->client->triggerJsvvFrame('VERBAL', [(string) $slot, $voice], $sendFramesToUnits, true);
                    }
                }
            }

            if (!$useLocalStream) {
                $this->waitForRemotePlayback($sequence, $estimatedDuration);
            }

            if ($useLocalStream) {
                $this->stopIfCurrentSourceIsJsvv('jsvv_sequence_completed');
            }

            $actualDuration = max(0.0, microtime(true) - $sequenceStart);

            $sequence->update([
                'status' => 'completed',
                'completed_at' => now(),
                'actual_duration_seconds' => $actualDuration,
            ]);

            $this->logSequenceEvent($sequence->fresh(), 'completed', [
                'playback_mode' => $useLocalStream ? 'local_stream' : 'remote_trigger',
                'estimated_duration_seconds' => $estimatedDuration,
                'actual_duration_seconds' => $actualDuration,
            ], $sessionId);
        } catch (Throwable $throwable) {
            Log::error('JSVV sequence failed', [
                'sequence_id' => $sequence->id,
                'exception' => $throwable,
            ]);

            $this->stopIfCurrentSourceIsJsvv('jsvv_sequence_failed');

            $sequence->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $throwable->getMessage(),
            ]);

            $this->logSequenceEvent($sequence->fresh(), 'failed', [
                'error_message' => $throwable->getMessage(),
                'estimated_duration_seconds' => $estimatedDuration,
            ], $sessionId);
        } finally {
            $this->clearActiveSequenceLock();
        }
    }

    private function useLocalStreamPlayback(): bool
    {
        return config('jsvv.sequence.playback_mode', 'local_stream') === 'local_stream';
    }

    private function preemptActiveSession(): void
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null || $session->source === 'jsvv') {
            return;
        }

        if ($session->source === 'recorded_playlist') {
            $this->requeueInterruptedPlaylists();
        }

        $this->orchestrator->stop('preempted_by_jsvv');
    }

    private function requeueInterruptedPlaylists(): void
    {
        $playlists = BroadcastPlaylist::query()
            ->where('status', 'running')
            ->get();

        foreach ($playlists as $playlist) {
            $playlist->update([
                'status' => 'queued',
                'started_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
            ]);

            Bus::dispatch(new ProcessRecordingPlaylist($playlist->id))->delay(now()->addSeconds(10));
        }
    }

    private function stopIfCurrentSourceIsJsvv(string $reason): void
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session !== null && $session->source === 'jsvv') {
            $this->orchestrator->stop($reason);
        }
    }

    private function setActiveSequenceLock(string $sequenceId): void
    {
        Cache::put(self::ACTIVE_LOCK_KEY, $sequenceId, now()->addSeconds(self::LOCK_TTL_SECONDS));
    }

    private function refreshActiveSequenceLock(string $sequenceId): void
    {
        $this->setActiveSequenceLock($sequenceId);
    }

    private function clearActiveSequenceLock(): void
    {
        Cache::forget(self::ACTIVE_LOCK_KEY);
    }

    private function buildPriorityOrderClause(): string
    {
        return "CASE priority "
            . "WHEN 'P1' THEN 1 "
            . "WHEN 'P2' THEN 2 "
            . "WHEN 'P3' THEN 3 "
            . "ELSE 10 END";
    }

    private function calculateQueuePosition(string $sequenceId): int
    {
        $sequence = JsvvSequence::query()->find($sequenceId);
        if ($sequence === null || $sequence->status !== 'queued') {
            return 0;
        }

        $queuedAt = $sequence->queued_at ?? $sequence->created_at ?? now();

        return JsvvSequence::query()
            ->where('status', 'queued')
            ->where(function (Builder $query) use ($sequence, $queuedAt): void {
                $query->whereRaw($this->buildPriorityOrderClause() . ' < ' . $this->priorityRank($sequence->priority ?? null))
                    ->orWhere(function (Builder $sub) use ($sequence, $queuedAt): void {
                        $sub->where('priority', $sequence->priority)
                            ->where(function (Builder $nested) use ($queuedAt): void {
                                $nested->where('queued_at', '<', $queuedAt)
                                    ->orWhereNull('queued_at');
                            });
                    });
            })
            ->count() + 1;
    }

    private function priorityRank(?string $priority): int
    {
        return match ($priority) {
            'P1' => 1,
            'P2' => 2,
            'P3' => 3,
            default => 10,
        };
    }

    private function buildSequenceItemMetadata(array $requestItem, ?array $resolvedItem): array
    {
        $metadata = [];
        if (is_array($resolvedItem)) {
            $metadata = $resolvedItem;
        }

        foreach ($requestItem as $key => $value) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] === null) {
                $metadata[$key] = $value;
            }
        }

        $metadata['request'] = $requestItem;

        return $metadata;
    }

    private function detectItemDurationSeconds(string $category, array $metadata): ?float
    {
        $durationKeys = [
            'duration_seconds',
            'durationSeconds',
            'duration',
            'length',
        ];

        foreach ($durationKeys as $key) {
            $value = Arr::get($metadata, $key);
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        $requestDuration = Arr::get($metadata, 'request.duration');
        if (is_numeric($requestDuration) && (float) $requestDuration > 0) {
            return (float) $requestDuration;
        }

        $path = Arr::get($metadata, 'path');
        if (is_string($path)) {
            $durationFromPath = $this->resolveAudioDurationSeconds($path);
            if ($durationFromPath !== null) {
                return $durationFromPath;
            }
        }

        $defaults = config('jsvv.sequence.default_durations', []);
        $fallback = (float) ($defaults['fallback'] ?? 10.0);

        return match (strtolower($category)) {
            'siren' => (float) ($defaults['siren'] ?? $fallback),
            'verbal' => (float) ($defaults['verbal'] ?? $fallback),
            default => $fallback,
        };
    }

    private function resolveAudioDurationSeconds(?string $path): ?float
    {
        if ($path === null || $path === '' || !is_file($path)) {
            return null;
        }

        $cacheTtl = (int) config('jsvv.sequence.duration_cache_ttl', 86400);
        $cacheKey = 'jsvv:audio_duration:' . sha1($path . '|' . (string) filemtime($path));

        return Cache::remember(
            $cacheKey,
            $cacheTtl > 0 ? $cacheTtl : null,
            static function () use ($path): ?float {
                try {
                    return (float) max(1, audio_duration($path));
                } catch (\Throwable $exception) {
                    Log::warning('Unable to determine audio duration for JSVV asset', [
                        'path' => $path,
                        'message' => $exception->getMessage(),
                    ]);
                    return null;
                }
            }
        );
    }

    private function ensureItemDuration(JsvvSequenceItem $item): float
    {
        $metadata = $item->metadata ?? [];
        $duration = Arr::get($metadata, 'duration_seconds');
        if (!is_numeric($duration) || (float) $duration <= 0) {
            $duration = $this->detectItemDurationSeconds($item->category, $metadata);
            $metadata['duration_seconds'] = $duration;
            $item->update(['metadata' => $metadata]);
        }

        return (float) $duration;
    }

    private function getSequenceEstimatedDuration(JsvvSequence $sequence): float
    {
        $existing = $sequence->estimated_duration_seconds;
        if (is_numeric($existing) && (float) $existing > 0) {
            return (float) $existing;
        }

        $sequence->loadMissing('sequenceItems');
        $total = 0.0;

        foreach ($sequence->sequenceItems as $item) {
            $duration = $this->ensureItemDuration($item);
            $repeat = max(1, (int) $item->repeat);
            $total += $duration * $repeat;
        }

        $holdSeconds = (float) Arr::get($sequence->options ?? [], 'holdSeconds', 0);
        if ($holdSeconds > 0) {
            $total += $holdSeconds;
        }

        if ($total <= 0) {
            $defaults = config('jsvv.sequence.default_durations', []);
            $total = (float) ($defaults['fallback'] ?? 10.0);
        }

        $sequence->update([
            'estimated_duration_seconds' => $total,
        ]);

        return $total;
    }

    private function waitForRemotePlayback(JsvvSequence $sequence, float $durationSeconds): void
    {
        $duration = max(1.0, $durationSeconds);
        $start = microtime(true);

        while (true) {
            $elapsed = microtime(true) - $start;
            if ($elapsed >= $duration) {
                break;
            }
            $remaining = $duration - $elapsed;
            $interval = (float) max(0.5, min(5.0, $remaining));
            usleep((int) round($interval * 1_000_000));
            $this->refreshActiveSequenceLock($sequence->id);
        }
    }

    private function logSequenceEvent(JsvvSequence $sequence, string $event, array $extra = [], ?string $sessionId = null): void
    {
        $playbackMode = $extra['playback_mode'] ?? ($this->useLocalStreamPlayback() ? 'local_stream' : 'remote_trigger');

        $payload = array_merge([
            'sequence_id' => $sequence->id,
            'priority' => $sequence->priority,
            'status' => $sequence->status,
            'playback_mode' => $playbackMode,
        ], $extra);

        StreamTelemetryEntry::create([
            'type' => 'jsvv_sequence_' . $event,
            'session_id' => is_string($sessionId) ? $sessionId : null,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);

        Log::info('JSVV sequence ' . $event, $payload);
    }

    private function notifyJsvvSms(JsvvSequence $sequence): void
    {
        $settings = app(JsvvSettings::class);
        if (!$settings->allowSms || empty($settings->smsContacts)) {
            return;
        }

        $template = $settings->smsMessage ?: 'Byl spuštěn poplach JSVV (priorita {priority}) v {time}.';
        $replacements = [
            '{priority}' => $sequence->priority ?? 'neuvedeno',
            '{time}' => now()->format('H:i'),
            '{date}' => now()->format('d.m.Y'),
        ];

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $this->smsService->send($settings->smsContacts, $message);
    }
}
