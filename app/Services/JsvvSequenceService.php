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
    ) {
        parent::__construct();
    }

    public function plan(array $items, array $options = []): array
    {
        $response = $this->client->planJsvvSequence($items, $options);
        $data = $response['json']['data'] ?? $response['json'] ?? [];

        return DB::transaction(function () use ($data, $items, $options): array {
            $sequence = JsvvSequence::create([
                'items' => $items,
                'options' => $options,
                'priority' => Arr::get($options, 'priority'),
                'status' => 'planned',
            ]);

            foreach ($items as $index => $item) {
                JsvvSequenceItem::create([
                    'sequence_id' => $sequence->id,
                    'position' => $index,
                    'category' => $item['category'] ?? 'verbal',
                    'slot' => $item['slot'],
                    'voice' => $item['voice'] ?? null,
                    'repeat' => (int) max(1, $item['repeat'] ?? 1),
                    'metadata' => $item,
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
        try {
            $this->setActiveSequenceLock($sequence->id);
            $this->preemptActiveSession();

            $items = $sequence->sequenceItems()->orderBy('position')->get();

            $this->orchestrator->start([
                'source' => 'jsvv',
                'route' => Arr::get($sequence->options, 'route', []),
                'zones' => Arr::get($sequence->options, 'zones', []),
                'options' => ['priority' => $sequence->priority],
            ]);

            foreach ($items as $item) {
                $this->refreshActiveSequenceLock($sequence->id);
                $category = $item->category;
                $slot = $item->slot;
                $repeat = (int) max(1, $item->repeat);

                for ($i = 0; $i < $repeat; $i++) {
                    if ($category === 'siren') {
                        $this->client->triggerJsvvFrame('SIREN', [(string) $slot], false, true);
                    } else {
                        $voice = $item->voice ?? 'male';
                        $this->client->triggerJsvvFrame('VERBAL', [(string) $slot, $voice], false, true);
                    }
                }
            }

            $this->stopIfCurrentSourceIsJsvv('jsvv_sequence_completed');

            $sequence->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
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
        } finally {
            $this->clearActiveSequenceLock();
        }
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
}
