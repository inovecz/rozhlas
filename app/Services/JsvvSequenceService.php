<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessRecordingPlaylist;
use App\Jobs\RunJsvvSequence;
use App\Libraries\PythonClient;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\BroadcastSession;
use App\Models\JsvvAudio;
use App\Models\LocationGroup;
use App\Models\JsvvEvent;
use App\Models\JsvvSequence;
use App\Models\JsvvSequenceItem;
use App\Models\StreamTelemetryEntry;
use App\Services\EmailNotificationService;
use App\Services\PlaylistAudioPlayer;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use App\Services\StreamOrchestrator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class JsvvSequenceService extends Service
{
    private const RUNNER_LOCK_KEY = 'jsvv:sequence:runner';
    private const ACTIVE_LOCK_KEY = 'jsvv:sequence:active';
    private const MODBUS_LOCK_KEY = 'modbus:serial';
    private const LOCK_TTL_SECONDS = 300;
    private array $symbolSlotCache = [];

    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly PlaylistAudioPlayer $audioPlayer = new PlaylistAudioPlayer(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
        private readonly EmailNotificationService $emailService = new EmailNotificationService(),
    ) {
        parent::__construct();
    }

    public function plan(array $items, array $options = []): array
    {
        $normalizedItems = $this->normalizeSequenceItems($items);
        $normalizedOptions = $this->normalizeSequenceOptions($options);
        $normalizedOptions = $this->applySettingsDefaults($normalizedOptions);

        [$normalizedItems, $normalizedOptions, $response] = $this->planWithAssetFallback($normalizedItems, $normalizedOptions);

        if ($normalizedItems === []) {
            throw new InvalidArgumentException('Sekvence neobsahuje žádné přehratelné položky.');
        }

        $storageItems = $this->stripInternalKeysFromItems($normalizedItems);

        $data = $response['json']['data'] ?? $response['json'] ?? [];
        $resolvedSequence = Arr::get($data, 'sequence', []);

        return DB::transaction(function () use ($data, $normalizedItems, $normalizedOptions, $resolvedSequence, $items, $storageItems): array {
            $sequence = JsvvSequence::create([
                'items' => $storageItems,
                'options' => $normalizedOptions,
                'priority' => Arr::get($normalizedOptions, 'priority'),
                'status' => 'planned',
            ]);

            $totalEstimatedDuration = 0.0;

            foreach ($normalizedItems as $index => $item) {
                $cleanItem = $this->stripInternalKeys($item);
                $resolved = $resolvedSequence[$index] ?? null;
                if ($resolved === null) {
                    Log::warning('JSVV sequence planning resolved metadata missing', [
                        'sequence_id' => $sequence->id,
                        'index' => $index,
                        'item' => $item,
                    ]);
                }
                $originalRequest = $item['__source'] ?? ($items[$index] ?? $cleanItem);
                if (!is_array($originalRequest)) {
                    $originalRequest = (array) $originalRequest;
                }
                $metadata = $this->buildSequenceItemMetadata($cleanItem, $resolved, $originalRequest);
                $repeat = (int) max(1, $cleanItem['repeat'] ?? 1);
                $perItemDuration = $this->detectItemDurationSeconds($cleanItem['category'] ?? 'verbal', $metadata);
                if ($perItemDuration !== null) {
                    $metadata['duration_seconds'] = $perItemDuration;
                }
                if ($perItemDuration !== null) {
                    $totalEstimatedDuration += $perItemDuration * $repeat;
                }

                JsvvSequenceItem::create([
                    'sequence_id' => $sequence->id,
                    'position' => $index,
                    'category' => $cleanItem['category'] ?? 'verbal',
                    'slot' => $cleanItem['slot'],
                    'voice' => $cleanItem['voice'] ?? null,
                    'repeat' => (int) max(1, $cleanItem['repeat'] ?? 1),
                    'metadata' => $metadata,
                ]);
            }

            $holdSeconds = (float) Arr::get($data, 'holdSeconds', Arr::get($normalizedOptions, 'holdSeconds', 0));
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

        $options = $sequence->options ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        $options['priority'] = $sequence->priority;

        $route = Arr::get($sequence->options, 'route', []);
        $zones = Arr::get($sequence->options, 'zones', []);
        $locations = Arr::get($sequence->options, 'locations', []);

        if (!is_array($route)) {
            $route = [];
        }
        if (!is_array($zones)) {
            $zones = [];
        }
        if (!is_array($locations)) {
            $locations = [];
        }

        if ($useLocalStream) {
            $options['plannedDuration'] = $estimatedDuration;
            $options['jsvv_sequence_id'] = $sequence->id;
        }

        $remoteStreamStarted = false;
        $remoteStartMeta = null;

        try {
            $this->setActiveSequenceLock($sequence->id);
            $this->preemptActiveSession();

            $items = $sequence->sequenceItems()->orderBy('position')->get();

            if ($useLocalStream) {
                $session = $this->orchestrator->start([
                    'source' => 'jsvv',
                    'route' => $route,
                    'zones' => $zones,
                    'locations' => $locations,
                    'options' => $options,
                ]);
                $sessionId = is_array($session) ? Arr::get($session, 'id') : null;
            } else {
                $startResponse = $this->withModbusLock(fn (): array => $this->client->startStream(
                    $route !== [] ? $route : null,
                    $zones !== [] ? $zones : null,
                    null,
                    $route !== []
                ));

                if (($startResponse['success'] ?? false) === false) {
                    throw new RuntimeException($startResponse['json']['message'] ?? 'Modbus start-stream failed');
                }

                $remoteStartMeta = Arr::get($startResponse, 'json.data', Arr::get($startResponse, 'json'));
                $remoteStreamStarted = true;
                $this->refreshActiveSequenceLock($sequence->id);
            }

            $startEventExtra = [
                'playback_mode' => $useLocalStream ? 'local_stream' : 'remote_trigger',
                'estimated_duration_seconds' => $estimatedDuration,
                'route' => $route,
                'zones' => $zones,
            ];
            if (!$useLocalStream && $remoteStartMeta !== null) {
                $startEventExtra['remote_start'] = $remoteStartMeta;
            }

            $this->logSequenceEvent($sequence, 'started', $startEventExtra, $sessionId);

            $this->notifyJsvvSms($sequence);
            $this->notifyJsvvEmail($sequence);

            $actualDuration = 0.0;

            if ($useLocalStream) {
                foreach ($items as $item) {
                    $this->refreshActiveSequenceLock($sequence->id);
                    $actualDuration += $this->playLocalSequenceItem($item);
                }

                $actualDuration += $this->applyLocalGapDelay($sequence);
                $this->stopIfCurrentSourceIsJsvv('jsvv_sequence_completed');

                if ($actualDuration <= 0.0) {
                    $actualDuration = max(0.0, microtime(true) - $sequenceStart);
                }
            } else {
                $this->waitForRemotePlayback($sequence, $estimatedDuration);
                $actualDuration = max(0.0, microtime(true) - $sequenceStart);

                $stopResponse = $this->withModbusLock(fn (): array => $this->client->stopStream());
                if (($stopResponse['success'] ?? true) === false) {
                    Log::warning('JSVV remote stop-stream reported error', [
                        'sequence_id' => $sequence->id,
                        'response' => Arr::get($stopResponse, 'json', []),
                    ]);
                }
                $remoteStreamStarted = false;
            }

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
            if (!$useLocalStream && $remoteStreamStarted) {
                try {
                    $this->withModbusLock(fn (): array => $this->client->stopStream());
                } catch (Throwable $stopException) {
                    Log::warning('Failed to stop remote JSVV stream after exception', [
                        'sequence_id' => $sequence->id,
                        'error' => $stopException->getMessage(),
                    ]);
                }
                $remoteStreamStarted = false;
            }

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
            if (!$useLocalStream && $remoteStreamStarted) {
                try {
                    $this->withModbusLock(fn (): array => $this->client->stopStream());
                } catch (Throwable $stopException) {
                    Log::warning('Failed to stop remote JSVV stream in finally block', [
                        'sequence_id' => $sequence->id,
                        'error' => $stopException->getMessage(),
                    ]);
                }
            }
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

    /**
     * Execute callback with exclusive Modbus access.
     *
     * @template TReturn
     * @param callable():TReturn $callback
     * @return TReturn
     */
    private function withModbusLock(callable $callback)
    {
        $lock = Cache::lock(self::MODBUS_LOCK_KEY, 10);

        try {
            return $lock->block(10, static fn () => $callback());
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Unable to acquire Modbus lock', 0, $exception);
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

    private function normalizeSequenceItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Sekvence musí obsahovat alespoň jednu položku.');
        }

        $normalized = [];
        foreach ($items as $index => $rawItem) {
            if (!is_array($rawItem)) {
                throw new InvalidArgumentException(sprintf('Sekvence obsahuje neplatnou položku na pozici %d.', $index + 1));
            }
            $slotInput = $rawItem['slot'] ?? $rawItem['symbol'] ?? null;
            if ($slotInput === null || $slotInput === '') {
                throw new InvalidArgumentException(sprintf('Položce %d chybí symbol.', $index + 1));
            }

            $symbolString = strtoupper(trim((string) $slotInput));
            if ($symbolString === '') {
                throw new InvalidArgumentException(sprintf('Položce %d chybí symbol.', $index + 1));
            }

            $category = $this->normalizeCategory($rawItem['category'] ?? null);
            $slot = $this->resolveSlotValue($slotInput, $index, $symbolString);
            $repeat = (int) max(1, $rawItem['repeat'] ?? 1);

            $normalizedSymbol = isset($rawItem['symbol'])
                ? strtoupper(trim((string) $rawItem['symbol']))
                : $symbolString;

            $item = [
                'slot' => $slot,
                'category' => $category,
                'repeat' => $repeat,
                'symbol' => $normalizedSymbol,
                '__source' => $rawItem,
            ];

            $voice = $this->normalizeVoice($rawItem['voice'] ?? null, $category);
            if ($voice !== null) {
                $item['voice'] = $voice;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function normalizeSequenceOptions(array $options): array
    {
        $normalized = Arr::except($options, ['items']);

        if (isset($normalized['priority']) && is_string($normalized['priority'])) {
            $normalized['priority'] = strtoupper($normalized['priority']);
        }

        if (isset($normalized['zones']) && is_array($normalized['zones'])) {
            $normalized['zones'] = array_values($normalized['zones']);
        }

        if (isset($normalized['locations'])) {
            $locations = $normalized['locations'];
            if (!is_array($locations)) {
                $locations = [$locations];
            }
            $normalized['locations'] = array_values(array_unique(array_filter(
                array_map(static fn($value) => is_numeric($value) ? (int) $value : null, $locations),
                static fn($value) => $value !== null
            )));
        }

        if (isset($normalized['holdSeconds']) && !is_numeric($normalized['holdSeconds'])) {
            unset($normalized['holdSeconds']);
        }

        $audioInput = $normalized['audioInputId'] ?? $normalized['audio_input_id'] ?? null;
        if (is_string($audioInput) && $audioInput !== '') {
            $normalized['audioInputId'] = $audioInput;
        } elseif (isset($normalized['audioInputId']) && !is_string($normalized['audioInputId'])) {
            unset($normalized['audioInputId']);
        }
        if (isset($normalized['audio_input_id'])) {
            unset($normalized['audio_input_id']);
        }

        $audioOutput = $normalized['audioOutputId'] ?? $normalized['audio_output_id'] ?? null;
        if (is_string($audioOutput) && $audioOutput !== '') {
            $normalized['audioOutputId'] = $audioOutput;
        } elseif (isset($normalized['audioOutputId']) && !is_string($normalized['audioOutputId'])) {
            unset($normalized['audioOutputId']);
        }
        if (isset($normalized['audio_output_id'])) {
            unset($normalized['audio_output_id']);
        }

        $playbackSource = $normalized['playbackSource'] ?? $normalized['playback_source'] ?? null;
        if (is_string($playbackSource) && $playbackSource !== '') {
            $normalized['playbackSource'] = strtolower($playbackSource);
        }
        if (isset($normalized['playback_source'])) {
            unset($normalized['playback_source']);
        }
        if (isset($normalized['playbackSource']) && $normalized['playbackSource'] === '') {
            unset($normalized['playbackSource']);
        }
        if (isset($normalized['playbackSource']) && in_array($normalized['playbackSource'], ['fm', 'fm_radio', 'radio'], true)) {
            if (!isset($normalized['audioInputId']) || $normalized['audioInputId'] === '') {
                $normalized['audioInputId'] = 'fm';
            }
        }

        $frequencyCandidates = [
            ['value' => $normalized['frequency_hz'] ?? null, 'unit' => 'hz'],
            ['value' => $normalized['frequencyHz'] ?? null, 'unit' => 'hz'],
            ['value' => $normalized['frequency_mhz'] ?? null, 'unit' => 'mhz'],
            ['value' => $normalized['frequencyMhz'] ?? null, 'unit' => 'mhz'],
            ['value' => $normalized['frequency'] ?? null, 'unit' => 'auto'],
        ];

        $frequency = null;
        foreach ($frequencyCandidates as $candidate) {
            $value = $candidate['value'];
            if ($value === null || $value === '') {
                continue;
            }
            $normalizedFrequency = $this->normalizeFrequencyValue($value, $candidate['unit']);
            if ($normalizedFrequency !== null) {
                $frequency = $normalizedFrequency;
                break;
            }
        }

        unset($normalized['frequencyHz'], $normalized['frequencyMhz']);

        if ($frequency !== null) {
            $normalized['frequency'] = $frequency['mhz'];
            $normalized['frequency_mhz'] = $frequency['mhz'];
            $normalized['frequency_hz'] = $frequency['hz'];
            if (!isset($normalized['audioInputId']) || $normalized['audioInputId'] === '') {
                $normalized['audioInputId'] = 'fm';
            }
        } else {
            unset($normalized['frequency_hz'], $normalized['frequency_mhz']);
        }

        return $normalized;
    }

    private function planWithAssetFallback(array $items, array $options): array
    {
        $skipped = [];
        $attempts = 0;
        $response = null;

        while (true) {
            $plannerItems = $this->stripInternalKeysFromItems($items);
            $response = $this->client->planJsvvSequence($plannerItems, $options);
            $data = $response['json']['data'] ?? $response['json'] ?? [];
            if (($data['status'] ?? null) !== 'error') {
                break;
            }
            $slot = $this->parseMissingSlotFromMessage($data['message'] ?? null);
            if ($slot === null) {
                break;
            }

            $removedItems = array_filter($items, static function (array $item) use ($slot): bool {
                return (int) ($item['slot'] ?? 0) === $slot;
            });
            $filtered = array_values(array_filter($items, static function (array $item) use ($slot): bool {
                return (int) ($item['slot'] ?? 0) !== $slot;
            }));

            if (count($filtered) === count($items)) {
                break;
            }

            foreach ($removedItems as $removed) {
                $skipped[] = [
                    'slot' => $slot,
                    'symbol' => $removed['symbol'] ?? ($removed['__source']['symbol'] ?? null),
                    'reason' => $data['message'] ?? 'Missing asset',
                ];
            }

            Log::warning('JSVV sequence item skipped due to missing audio asset', [
                'slot' => $slot,
                'message' => $data['message'] ?? null,
            ]);

            $items = $filtered;
            if ($items === []) {
                break;
            }

            $attempts++;
            if ($attempts >= 10) {
                break;
            }
        }

        if ($skipped !== []) {
            $options['skipped_assets'] = $skipped;
        }

        return [$items, $options, $response ?? ['json' => []]];
    }

    private function parseMissingSlotFromMessage(?string $message): ?int
    {
        if (!is_string($message) || $message === '') {
            return null;
        }
        if (preg_match('/slot\s+(\d+)/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }

    private function stripInternalKeys(array $item): array
    {
        unset($item['__source']);
        return $item;
    }

    private function stripInternalKeysFromItems(array $items): array
    {
        return array_map(fn(array $item): array => $this->stripInternalKeys($item), $items);
    }

    private function resolveSlotValue(mixed $slot, int $index, ?string $normalizedSymbol = null): int
    {
        if (is_int($slot)) {
            if ($slot <= 0) {
                throw new InvalidArgumentException(sprintf('Slot u položky %d musí být kladné číslo.', $index + 1));
            }
            return $slot;
        }

        if (is_numeric($slot)) {
            $value = (int) $slot;
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('Slot u položky %d musí být kladné číslo.', $index + 1));
            }
            return $value;
        }

        $symbol = $normalizedSymbol ?? strtoupper(trim((string) $slot));
        if ($symbol === '') {
            throw new InvalidArgumentException(sprintf('Položce %d chybí symbol.', $index + 1));
        }

        if (preg_match('/^\d+$/', $symbol) === 1) {
            $value = (int) $symbol;
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('Slot u položky %d musí být kladné číslo.', $index + 1));
            }
            return $value;
        }

        if (isset($this->symbolSlotCache[$symbol])) {
            return $this->symbolSlotCache[$symbol];
        }

        /** @var JsvvAudio|null $audio */
        $audio = JsvvAudio::query()->with('file')->find($symbol);
        $slotValue = $audio === null ? null : $this->extractSlotFromAudio($audio);

        if ($slotValue === null || $slotValue <= 0) {
            throw new InvalidArgumentException(sprintf('Symbol %s nelze převést na číselný slot (položka #%d).', $symbol, $index + 1));
        }

        $this->symbolSlotCache[$symbol] = $slotValue;

        return $slotValue;
    }

    private function extractSlotFromAudio(JsvvAudio $audio): ?int
    {
        $file = $audio->file;
        if ($file !== null) {
            $metadata = $file->getMetadata();
            if (is_array($metadata) && isset($metadata['slot']) && is_numeric($metadata['slot'])) {
                return (int) $metadata['slot'];
            }

            foreach ([$file->getName(), $file->getFilename()] as $candidate) {
                if (is_string($candidate) && preg_match('/(\d+)/', $candidate, $matches) === 1) {
                    return (int) $matches[1];
                }
            }
        }

        $name = $audio->getName();
        if (is_string($name) && preg_match('/(\d+)/', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function normalizeCategory(mixed $category): string
    {
        $value = is_string($category) ? strtolower($category) : '';
        return $value === 'siren' ? 'siren' : 'verbal';
    }

    /**
     * Normalise FM frequency input into Hz/MHz pair.
     *
     * @param mixed $value
     * @param string $unit
     * @return array{hz: float, mhz: float}|null
     */
    private function normalizeFrequencyValue(mixed $value, string $unit = 'auto'): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $unitHint = strtolower($unit);
        $numericValue = null;

        if (is_string($value)) {
            $candidate = trim($value);
            if ($candidate === '') {
                return null;
            }

            $lower = strtolower($candidate);
            if (str_contains($lower, 'mhz')) {
                $unitHint = 'mhz';
            } elseif (str_contains($lower, 'hz')) {
                $unitHint = 'hz';
            }

            $sanitized = preg_replace('/[^0-9.,+-]/', '', $candidate);
            if ($sanitized === null) {
                return null;
            }
            $sanitized = str_replace(',', '.', $sanitized);
            if ($sanitized === '' || !is_numeric($sanitized)) {
                return null;
            }
            $numericValue = (float) $sanitized;
        } elseif (is_numeric($value)) {
            $numericValue = (float) $value;
        }

        if ($numericValue === null || !is_finite($numericValue) || $numericValue <= 0) {
            return null;
        }

        if ($unitHint === 'mhz' || ($unitHint === 'auto' && $numericValue < 2000)) {
            $mhz = $numericValue;
            $hz = $mhz * 1_000_000;
        } else {
            $hz = $numericValue;
            $mhz = $hz / 1_000_000;
        }

        return [
            'hz' => $hz,
            'mhz' => $mhz,
        ];
    }

    private function normalizeVoice(mixed $voice, string $category): ?string
    {
        if ($category === 'siren') {
            return null;
        }

        if ($voice === null || $voice === '') {
            return null;
        }

        $normalized = strtolower((string) $voice);
        return $normalized !== '' ? $normalized : null;
    }

    private function playLocalSequenceItem(JsvvSequenceItem $item): float
    {
        $metadata = $item->metadata ?? [];
        $path = Arr::get($metadata, 'path');

        if (!$this->hasUsableAudioPath($metadata)) {
            $fallbackSymbol = Arr::get($metadata, 'symbol') ?? (string) $item->slot;
            $fallback = $this->resolveFallbackAudio([
                'symbol' => $fallbackSymbol,
            ]);
            if ($fallback !== null) {
                $metadata = array_merge($metadata, $fallback);
                $item->update(['metadata' => array_merge($item->metadata ?? [], $fallback)]);
                $path = Arr::get($metadata, 'path');
            }
        }

        if (!is_string($path) || trim($path) === '' || !is_file($path)) {
            Log::warning('JSVV local playback skipped due to missing asset path', [
                'sequence_item_id' => $item->id,
                'slot' => $item->slot,
                'path' => $path,
            ]);
            throw new RuntimeException('Chybí audio soubor pro JSVV sekvenci (slot ' . ($item->slot ?? '?') . ').');
        }

        $repeat = (int) max(1, $item->repeat);
        $totalDuration = 0.0;
        $durationHint = $this->ensureItemDuration($item);

        $playerOverrides = Arr::get($metadata, 'player');
        $metadataPayload = ['absolute_path' => $path];
        if (is_array($playerOverrides) && $playerOverrides !== []) {
            $metadataPayload['player'] = $playerOverrides;
        }

        $sequenceId = $item->sequence_id ? (string) $item->sequence_id : null;

        for ($index = 0; $index < $repeat; $index++) {
            $playlistItem = new BroadcastPlaylistItem([
                'recording_id' => sprintf('jsvv-%s', $item->slot ?? 'asset'),
                'metadata' => $metadataPayload,
                'duration_seconds' => $durationHint,
                'gap_ms' => 0,
            ]);

            $result = $this->audioPlayer->play($playlistItem);

            if (!$result->success) {
                Log::warning('JSVV local playback failed', [
                    'sequence_item_id' => $item->id,
                    'slot' => $item->slot,
                    'status' => $result->status,
                    'context' => $result->context,
                ]);

                throw new RuntimeException('JSVV lokální přehrávání selhalo: ' . $result->status);
            }

            $totalDuration += (float) ($result->context['duration_seconds'] ?? $durationHint ?? 0.0);

            if ($sequenceId !== null) {
                $this->refreshActiveSequenceLock($sequenceId);
            }
        }

        return $totalDuration;
    }

    private function applyLocalGapDelay(JsvvSequence $sequence): float
    {
        $options = $sequence->options ?? [];
        $holdSeconds = 0.0;
        if (is_array($options)) {
            $holdSecondsValue = Arr::get($options, 'holdSeconds', Arr::get($options, 'hold_seconds'));
            if (is_numeric($holdSecondsValue)) {
                $holdSeconds = (float) $holdSecondsValue;
            }
        }

        $gapSeconds = (float) config('jsvv.sequence.local_gap_seconds', 0.0);
        $delay = max(0.0, $holdSeconds) + max(0.0, $gapSeconds);

        if ($delay > 0) {
            usleep((int) round($delay * 1_000_000));
        }

        return $delay;
    }

    private function buildSequenceItemMetadata(array $normalizedItem, ?array $resolvedItem, array $originalRequest = []): array
    {
        $metadata = [];
        if (is_array($resolvedItem)) {
            $metadata = $resolvedItem;
        }

        foreach ($normalizedItem as $key => $value) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] === null) {
                $metadata[$key] = $value;
            }
        }

        $metadata['request'] = $originalRequest ?: $normalizedItem;

        if (!$this->hasUsableAudioPath($metadata)) {
            $fallback = $this->resolveFallbackAudio($normalizedItem, $originalRequest);
            if ($fallback !== null) {
                $metadata = array_merge($metadata, $fallback);
                if (isset($fallback['symbol']) && $fallback['symbol'] !== null) {
                    $metadata['symbol'] = $fallback['symbol'];
                }
            }
        }

        return $metadata;
    }

    private function hasUsableAudioPath(array $metadata): bool
    {
        $candidates = [
            Arr::get($metadata, 'path'),
            Arr::get($metadata, 'absolute_path'),
            Arr::get($metadata, 'file_path'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if ($candidate === '') {
                continue;
            }
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function resolveFallbackAudio(array $normalizedItem, array $originalRequest = []): ?array
    {
        $symbol = Arr::get($normalizedItem, 'symbol');
        $symbol = is_string($symbol) ? strtoupper(trim($symbol)) : null;

        if ($symbol === null || $symbol === '' || ctype_digit($symbol)) {
            $originalSymbol = Arr::get($originalRequest, 'symbol');
            if (!is_string($originalSymbol) || $originalSymbol === '') {
                $originalSymbol = Arr::get($originalRequest, 'slot');
            }
            if (is_string($originalSymbol)) {
                $originalSymbol = strtoupper(trim($originalSymbol));
                if ($originalSymbol !== '' && !ctype_digit($originalSymbol)) {
                    $symbol = $originalSymbol;
                }
            }
        }

        if (!is_string($symbol) || $symbol === '') {
            return null;
        }

        /** @var JsvvAudio|null $audio */
        $audio = JsvvAudio::query()->with('file')->find($symbol);
        $file = $audio?->file;
        if ($file === null) {
            return null;
        }

        try {
            $absolutePath = Storage::path($file->getStoragePath());
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve fallback audio path for JSVV item', [
                'symbol' => $symbol,
                'file_id' => $file->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!is_string($absolutePath) || $absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $payload = [
            'path' => $absolutePath,
            'absolute_path' => $absolutePath,
            'symbol' => $symbol,
        ];

        $duration = Arr::get($file->getMetadata() ?? [], 'duration');
        if (is_numeric($duration) && (float) $duration > 0) {
            $payload['duration_seconds'] = (float) $duration;
        }

        return $payload;
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
        $recipients = $this->normalizeRecipientList($settings->smsContacts ?? []);
        if (!$settings->allowSms || $recipients === []) {
            return;
        }

        $template = $settings->smsMessage ?: 'Byl spuštěn poplach JSVV (priorita {priority}) v {time}.';
        $replacements = [
            '{priority}' => $sequence->priority ?? 'neuvedeno',
            '{time}' => now()->format('H:i'),
            '{date}' => now()->format('d.m.Y'),
        ];

        $message = $this->renderTemplate($template, $replacements);
        $this->smsService->send($recipients, $message);
    }

    private function notifyJsvvEmail(JsvvSequence $sequence): void
    {
        $settings = app(JsvvSettings::class);
        $recipients = $this->normalizeRecipientList($settings->emailContacts ?? []);
        if (!$settings->allowEmail || $recipients === []) {
            return;
        }

        $replacements = [
            '{priority}' => $sequence->priority ?? 'neuvedeno',
            '{time}' => now()->format('H:i'),
            '{date}' => now()->format('d.m.Y'),
        ];

        $subjectTemplate = $settings->emailSubject ?: 'Poplach JSVV ({priority})';
        $bodyTemplate = $settings->emailMessage ?: 'Byl spuštěn poplach JSVV (priorita {priority}) v {time} dne {date}.';

        $subject = $this->renderTemplate($subjectTemplate, $replacements);
        $body = $this->renderTemplate($bodyTemplate, $replacements);

        $this->emailService->send($recipients, $subject, $body);
    }

    private function normalizeRecipientList(array|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            $parts = preg_split('/[;,]+/', (string) $item) ?: [];
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '' && !in_array($trimmed, $normalized, true)) {
                    $normalized[] = $trimmed;
                }
            }
        }

        return $normalized;
    }

    private function renderTemplate(string $template, array $replacements): string
    {
        if ($template === '') {
            return '';
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function applySettingsDefaults(array $options): array
    {
        $locations = $options['locations'] ?? [];
        if (!is_array($locations)) {
            $locations = [$locations];
        }
        $locations = array_filter(
            array_map(static fn($value) => is_numeric($value) ? (int) $value : null, $locations),
            static fn($value) => $value !== null
        );

        $settings = app(JsvvSettings::class);
        $locationGroupId = $settings->locationGroupId;
        if ($locationGroupId !== null) {
            $locations[] = (int) $locationGroupId;
        } elseif ($locations === []) {
            $locations = LocationGroup::query()
                ->pluck('id')
                ->map(static fn($id) => (int) $id)
                ->all();
        }

        $options['locations'] = array_values(array_unique($locations));

        return $options;
    }
}
