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
use App\Services\RF\RfBus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Log as ActivityLog;
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
    private const DTRX_SAMPLE_MAP = [
        '1' => [
            'sample_codes' => [1],
            'indexes' => [1, 2],
            'label' => 'Siréna - Všeobecná výstraha',
        ],
        '2' => [
            'sample_codes' => [2],
            'indexes' => [3, 4],
            'label' => 'Siréna - Zkouška sirén',
        ],
        '4' => [
            'sample_codes' => [3, 4],
            'indexes' => [5, 6],
            'label' => 'Siréna - Požární poplach',
        ],
        '8' => [
            'sample_codes' => [5],
            'indexes' => [7],
            'label' => 'Gong 1',
        ],
        '9' => [
            'sample_codes' => [6],
            'indexes' => [8],
            'label' => 'Gong 2',
        ],
        'A' => [
            'sample_codes' => [7],
            'indexes' => [9],
            'label' => 'VI 1',
        ],
        'B' => [
            'sample_codes' => [8],
            'indexes' => [10],
            'label' => 'VI 2',
        ],
        'C' => [
            'sample_codes' => [9],
            'indexes' => [11],
            'label' => 'VI 3',
        ],
        'D' => [
            'sample_codes' => [10],
            'indexes' => [12],
            'label' => 'VI 4',
        ],
        'E' => [
            'sample_codes' => [11],
            'indexes' => [13],
            'label' => 'VI 5',
        ],
        'F' => [
            'sample_codes' => [12],
            'indexes' => [14],
            'label' => 'VI 6',
        ],
        'G' => [
            'sample_codes' => [13],
            'indexes' => [15],
            'label' => 'VI 7',
        ],
        'P' => [
            'sample_codes' => [14],
            'indexes' => [16],
            'label' => 'VI 8 - kraj',
        ],
        'Q' => [
            'sample_codes' => [15],
            'indexes' => [17],
            'label' => 'VI 9 - kraj',
        ],
        'R' => [
            'sample_codes' => [16],
            'indexes' => [18],
            'label' => 'VI 10 - kraj',
        ],
        'S' => [
            'sample_codes' => [17],
            'indexes' => [19],
            'label' => 'VI 11 - kraj',
        ],
        'T' => [
            'sample_codes' => [18],
            'indexes' => [20],
            'label' => 'VI 12 - kraj',
        ],
        'U' => [
            'sample_codes' => [19],
            'indexes' => [21],
            'label' => 'VI 13',
        ],
        'V' => [
            'sample_codes' => [20],
            'indexes' => [22],
            'label' => 'VI 14',
        ],
        'X' => [
            'sample_codes' => [21],
            'indexes' => [23],
            'label' => 'VI 15',
        ],
        'Y' => [
            'sample_codes' => [22],
            'indexes' => [24],
            'label' => 'VI 16',
        ],
    ];
    private array $symbolSlotCache = [];

    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly PlaylistAudioPlayer $audioPlayer = new PlaylistAudioPlayer(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
        private readonly EmailNotificationService $emailService = new EmailNotificationService(),
        private readonly RfBus $rfBus = new RfBus(),
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

    public function stopAll(string $reason = 'manual_stop'): array
    {
        $lock = Cache::lock(self::RUNNER_LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (!$lock->get()) {
            return [
                'status' => 'busy',
                'message' => 'Sekvencer je aktuálně zaneprázdněn.',
            ];
        }

        $cancelled = [];
        $stopped = null;

        try {
            DB::transaction(function () use (&$cancelled, &$stopped, $reason): void {
                $running = JsvvSequence::query()
                    ->where('status', 'running')
                    ->lockForUpdate()
                    ->first();

                if ($running !== null) {
                    $running->update([
                        'status' => 'cancelled',
                        'failed_at' => now(),
                        'error_message' => $reason,
                    ]);
                    $stopped = $running->id;
                }

                $queuedIds = JsvvSequence::query()
                    ->where('status', 'queued')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($queuedIds->isNotEmpty()) {
                    JsvvSequence::query()
                        ->whereIn('id', $queuedIds->all())
                        ->update([
                            'status' => 'cancelled',
                            'failed_at' => now(),
                            'error_message' => $reason,
                        ]);
                    $cancelled = $queuedIds->all();
                }
            });
        } finally {
            $lock->release();
        }

        $this->clearActiveSequenceLock();

        try {
            $this->stopIfCurrentSourceIsJsvv('jsvv_manual_stop');
        } catch (Throwable $exception) {
            Log::warning('Unable to stop active JSVV session via orchestrator.', [
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->withModbusLock(function (): void {
                $this->client->stopStream();
            });
        } catch (Throwable $exception) {
            Log::warning('Unable to stop JSVV stream via Modbus client.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'status' => 'stopped',
            'stopped' => $stopped,
            'cancelled' => $cancelled,
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

    public function dispatchImmediateSequence(array $steps, array $options = []): array
    {
        $sequenceString = $this->buildSequenceString($steps);
        return $this->dispatchImmediateSequenceString($sequenceString, $options);
    }

    public function dispatchImmediateSequenceString(string $sequence, array $options = []): array
    {
        $normalized = $this->normalizeSequenceInput($sequence);
        if ($normalized === '') {
            throw new InvalidArgumentException('Sekvence musí obsahovat alespoň jeden symbol.');
        }

        return $this->sendImmediateSequenceCommand($normalized, $options);
    }

    public function sendImmediateStop(array $options = []): array
    {
        $priority = $this->normalizePriorityValue(Arr::get($options, 'priority'));
        if ($priority < 0) {
            $priority = 0;
        }

        $remote = $this->normalizeOptionalInt(Arr::get($options, 'remote'));
        $targets = $this->normalizeTargetsOption(Arr::get($options, 'targets'));
        $repeat = $this->normalizeOptionalInt(Arr::get($options, 'repeat'));
        $repeatDelay = $this->normalizeOptionalFloat(
            Arr::get($options, 'repeatDelay', Arr::get($options, 'repeat_delay'))
        );

        $response = $this->client->stopJsvvSequenceCommand(
            $priority,
            $remote,
            $targets,
            $repeat,
            $repeatDelay,
        );

        if (($response['success'] ?? false) === false) {
            throw new RuntimeException($response['json']['message'] ?? 'Příkaz k ukončení JSVV se nepodařil.');
        }

        return [
            'status' => 'stopped',
            'priority' => $priority,
            'targets' => $targets,
            'remote' => $remote,
            'repeat' => $repeat,
            'repeat_delay' => $repeatDelay,
            'response' => $response['json'] ?? null,
        ];
    }

    private function sendImmediateSequenceCommand(string $sequenceString, array $options = []): array
    {
        $priority = $this->normalizePriorityValue(Arr::get($options, 'priority'));
        if ($priority <= 0) {
            $priority = null;
        }

        $remote = $this->normalizeOptionalInt(Arr::get($options, 'remote'));
        $targets = $this->normalizeTargetsOption(Arr::get($options, 'targets'));
        $repeat = $this->normalizeOptionalInt(Arr::get($options, 'repeat'));
        $repeatDelay = $this->normalizeOptionalFloat(
            Arr::get($options, 'repeatDelay', Arr::get($options, 'repeat_delay'))
        );

        $response = $this->client->sendJsvvSequenceCommand(
            $sequenceString,
            $priority,
            $remote,
            $targets,
            $repeat,
            $repeatDelay,
        );

        if (($response['success'] ?? false) === false) {
            throw new RuntimeException($response['json']['message'] ?? 'JSVV sekvenci se nepodařilo odeslat.');
        }

        return [
            'status' => 'running',
            'sequence' => $sequenceString,
            'priority' => $priority,
            'targets' => $targets,
            'remote' => $remote,
            'repeat' => $repeat,
            'repeat_delay' => $repeatDelay,
            'response' => $response['json'] ?? null,
        ];
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
        $remoteDtrxConfig = null;
        $remoteCommandPayload = null;
        $remoteCommandsWritten = false;
        $modbusUnitId = null;

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
                $modbusUnitIdOption = Arr::get($options, 'modbusUnitId');
                if (is_numeric($modbusUnitIdOption)) {
                    $modbusUnitId = (int) $modbusUnitIdOption;
                }

                $remoteDtrxConfig = $this->resolveRemoteDtrxConfig();
                if ($remoteDtrxConfig !== null) {
                    $remoteCommandPayload = $this->buildRemoteDtrxCommandPayload($items, $remoteDtrxConfig, $sequence->priority);
                }

                $startResponse = $this->rfBus->pushRequest('jsvv', function () use (&$remoteCommandsWritten, $remoteCommandPayload, $route, $zones, $modbusUnitId): array {
                    return $this->withModbusLock(function () use (&$remoteCommandsWritten, $remoteCommandPayload, $route, $zones, $modbusUnitId): array {
                        if ($remoteCommandPayload !== null) {
                            $this->programRemoteDtrxCommands($remoteCommandPayload, $modbusUnitId);
                            $remoteCommandsWritten = true;
                        }

                        return $this->client->startStream(
                            $route !== [] ? $route : null,
                            $zones !== [] ? $zones : null,
                            null,
                            $route !== [],
                            $modbusUnitId
                        );
                    });
                });

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

                $stopResponse = $this->rfBus->pushRequest('stop', function () use ($modbusUnitId): array {
                    return $this->withModbusLock(function () use ($modbusUnitId): array {
                        return $this->client->stopStream(null, $modbusUnitId);
                    });
                });
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
                    $this->rfBus->pushRequest('stop', function () use ($modbusUnitId): array {
                        return $this->withModbusLock(function () use ($modbusUnitId): array {
                            return $this->client->stopStream(null, $modbusUnitId);
                        });
                    });
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
                    $this->rfBus->pushRequest('stop', function () use ($modbusUnitId): array {
                        return $this->withModbusLock(function () use ($modbusUnitId): array {
                            return $this->client->stopStream(null, $modbusUnitId);
                        });
                    });
                } catch (Throwable $stopException) {
                    Log::warning('Failed to stop remote JSVV stream in finally block', [
                        'sequence_id' => $sequence->id,
                        'error' => $stopException->getMessage(),
                    ]);
                }
            }
            if (
                !$useLocalStream
                && $remoteDtrxConfig !== null
                && ($remoteDtrxConfig['reset_after'] ?? false)
                && $remoteCommandPayload !== null
                && $remoteCommandsWritten
            ) {
                try {
                    $this->rfBus->pushRequest('jsvv', function () use ($remoteCommandPayload, $modbusUnitId): void {
                        $this->withModbusLock(function () use ($remoteCommandPayload, $modbusUnitId): void {
                            $this->resetRemoteDtrxCommands($remoteCommandPayload, $modbusUnitId);
                        });
                    });
                } catch (Throwable $resetException) {
                    Log::warning('Failed to reset remote DTRX registers after JSVV sequence', [
                        'sequence_id' => $sequence->id,
                        'error' => $resetException->getMessage(),
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

            $originalSymbol = $this->resolveOriginalSymbol($item, $rawItem);
            if ($originalSymbol !== null) {
                $item['symbol'] = $originalSymbol;
            }

            $voice = $this->normalizeVoice($rawItem['voice'] ?? null, $category);
            if ($voice !== null) {
                $item['voice'] = $voice;
            }

            $dtrxMapping = $this->resolveDtrxMapping($item['symbol'] ?? null);
            if ($dtrxMapping !== null) {
                $item['dtrx'] = $dtrxMapping;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function resolveDtrxMapping(mixed $symbol): ?array
    {
        if ($symbol === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $symbol));
        if ($normalized === '') {
            return null;
        }

        $normalized = ltrim($normalized, ' ');
        $normalized = ltrim($normalized, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        if (!array_key_exists($normalized, self::DTRX_SAMPLE_MAP)) {
            return null;
        }

        $mapping = self::DTRX_SAMPLE_MAP[$normalized];
        $sampleCodes = array_map('intval', $mapping['sample_codes'] ?? []);
        $indexes = array_map('intval', $mapping['indexes'] ?? []);
        $primary = $sampleCodes[0] ?? null;

        return [
            'symbol' => $normalized,
            'sample_codes' => $sampleCodes,
            'primary_sample_code' => $primary,
            'indexes' => $indexes,
            'description' => $mapping['label'] ?? null,
        ];
    }

    private function resolveRemoteDtrxConfig(): ?array
    {
        $config = config('jsvv.remote_trigger.dtrx');
        if (!is_array($config)) {
            return null;
        }

        $enabled = $this->normalizeBooleanConfig(Arr::get($config, 'enabled', false));
        if (!$enabled) {
            return null;
        }

        $baseRaw = Arr::get($config, 'register_base');
        if ($baseRaw === null || $baseRaw === '') {
            throw new RuntimeException(
                'JSVV remote trigger DTRX is enabled but JSVV_REMOTE_DTRX_BASE is not configured.'
            );
        }
        $base = $this->parseNumericValue($baseRaw, 'JSVV remote trigger DTRX base address');
        if ($base < 0) {
            throw new RuntimeException('JSVV remote trigger DTRX base address must be non-negative.');
        }

        $count = Arr::get($config, 'register_count', 24);
        $count = (int) $count;
        if ($count <= 0) {
            throw new RuntimeException('JSVV remote trigger DTRX register count must be greater than zero.');
        }

        $stride = Arr::get($config, 'register_stride', 1);
        $stride = (int) $stride;
        if ($stride <= 0) {
            throw new RuntimeException('JSVV remote trigger DTRX register stride must be greater than zero.');
        }
        if ($stride !== 1) {
            throw new RuntimeException('JSVV remote trigger DTRX currently supports register stride 1 only.');
        }

        $clearValue = $this->parseNumericValue(Arr::get($config, 'clear_value', 0), 'JSVV remote trigger DTRX clear value');
        $resetAfter = $this->normalizeBooleanConfig(Arr::get($config, 'reset_after', true));

        return [
            'register_base' => $base,
            'register_count' => $count,
            'register_stride' => $stride,
            'clear_value' => $clearValue,
            'reset_after' => $resetAfter,
        ];
    }

    /**
     * @param iterable<int, JsvvSequenceItem> $items
     */
    private function buildRemoteDtrxCommandPayload(iterable $items, array $config, ?string $priority): array
    {
        $commandStartRegister = $this->parseNumericValue($config['command_register_start'] ?? 11, 'JSVV remote trigger DTRX command register start');
        $commandCount = max(1, $this->parseNumericValue($config['command_register_count'] ?? 4, 'JSVV remote trigger DTRX command register count'));
        $priorityRegister = $this->parseNumericValue($config['priority_register'] ?? 10, 'JSVV remote trigger DTRX priority register');

        $commandOffset = $commandStartRegister - 1;
        $priorityOffset = $priorityRegister - 1;
        if ($commandOffset < 0 || $priorityOffset < 0) {
            throw new RuntimeException('JSVV remote trigger DTRX register numbers must be positive integers.');
        }

        $registerBase = $this->parseNumericValue($config['register_base'] ?? null, 'JSVV remote trigger DTRX base address');
        $commandsBaseAddress = $registerBase + $commandOffset;
        $priorityAddress = $registerBase + $priorityOffset;
        $clearValue = $this->parseNumericValue($config['clear_value'] ?? 0, 'JSVV remote trigger DTRX clear value');
        $priorityClear = $this->parseNumericValue($config['priority_clear_value'] ?? 0, 'JSVV remote trigger DTRX priority clear value');

        $commands = array_fill(0, $commandCount, $clearValue);
        $filledSlots = 0;

        foreach ($items as $item) {
            if (!$item instanceof JsvvSequenceItem) {
                continue;
            }

            $metadata = $item->metadata ?? [];
            if (!is_array($metadata)) {
                $metadata = (array) $metadata;
            }

            $dtrx = Arr::get($metadata, 'dtrx');
            if (!is_array($dtrx)) {
                $symbol = Arr::get($metadata, 'symbol', $item->slot);
                $dtrx = $this->resolveDtrxMapping($symbol);
            }

            if (!is_array($dtrx)) {
                Log::warning('DTRX mapping missing for JSVV item – skipping remote command slot', [
                    'sequence_item_id' => $item->id,
                    'slot' => $item->slot,
                ]);
                continue;
            }

            $sampleCodes = $dtrx['sample_codes'] ?? [];
            $primaryCode = $dtrx['primary_sample_code'] ?? null;
            if ($primaryCode === null && is_array($sampleCodes) && $sampleCodes !== []) {
                $primaryCode = reset($sampleCodes);
            }

            if ($primaryCode === null) {
                Log::warning('Unable to resolve primary DTRX sample code for item – skipping remote command slot', [
                    'sequence_item_id' => $item->id,
                    'slot' => $item->slot,
                    'mapping' => $dtrx,
                ]);
                continue;
            }

            $commands[$filledSlots] = (int) $primaryCode;
            $filledSlots++;
            if ($filledSlots >= $commandCount) {
                break;
            }
        }

        if ($filledSlots === 0) {
            throw new RuntimeException('Unable to build remote DTRX commands – no sequence items provided usable mapping.');
        }

        $priorityValue = $this->normalizePriorityValue($priority);

        return [
            'commands_address' => $commandsBaseAddress,
            'commands_values' => $commands,
            'commands_count' => $commandCount,
            'priority_address' => $priorityAddress,
            'priority_value' => $priorityValue,
            'clear_commands' => array_fill(0, $commandCount, $clearValue),
            'clear_priority' => $priorityClear,
        ];
    }

    private function programRemoteDtrxCommands(array $payload, ?int $unitId = null): void
    {
        $commandsAddress = $payload['commands_address'] ?? null;
        $commandValues = $payload['commands_values'] ?? [];
        $priorityAddress = $payload['priority_address'] ?? null;
        $priorityValue = $payload['priority_value'] ?? null;

        if (!is_int($commandsAddress) || !is_int($priorityAddress)) {
            throw new RuntimeException('Invalid remote DTRX payload – missing command or priority address.');
        }

        if ($commandValues === []) {
            throw new RuntimeException('Remote DTRX command payload is empty.');
        }

        $commandValues = array_map(static fn ($value) => (int) $value, $commandValues);
        $response = $this->client->writeRegisters($commandsAddress, $commandValues, $unitId);
        if (($response['success'] ?? true) === false) {
            throw new RuntimeException($response['json']['message'] ?? 'Failed to program DTRX command registers.');
        }

        if ($priorityValue !== null) {
            $priorityResponse = $this->client->writeRegister($priorityAddress, (int) $priorityValue, $unitId);
            if (($priorityResponse['success'] ?? true) === false) {
                throw new RuntimeException($priorityResponse['json']['message'] ?? 'Failed to program DTRX priority register.');
            }
        }

        Log::debug('JSVV remote DTRX commands programmed', [
            'commands_address' => sprintf('0x%04X', $commandsAddress),
            'commands' => $commandValues,
            'priority_address' => sprintf('0x%04X', $priorityAddress),
            'priority_value' => $priorityValue,
            'unit_id' => $unitId,
        ]);
    }

    private function resetRemoteDtrxCommands(array $payload, ?int $unitId = null): void
    {
        $commandsAddress = $payload['commands_address'] ?? null;
        $clearCommands = $payload['clear_commands'] ?? [];
        $priorityAddress = $payload['priority_address'] ?? null;
        $clearPriority = $payload['clear_priority'] ?? 0;

        if (!is_int($commandsAddress) || !is_int($priorityAddress)) {
            return;
        }

        if ($clearCommands !== []) {
            $clearCommands = array_map(static fn ($value) => (int) $value, $clearCommands);
            $this->client->writeRegisters($commandsAddress, $clearCommands, $unitId);
        }

        $this->client->writeRegister($priorityAddress, (int) $clearPriority, $unitId);
    }

    private function normalizeBooleanConfig(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }

            return $default;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        return $default;
    }

    private function parseNumericValue(mixed $value, string $context): int
    {
        if ($value === null) {
            throw new RuntimeException($context . ' is not defined.');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new RuntimeException($context . ' is not defined.');
            }

            if (str_starts_with($trimmed, '0x') || str_starts_with($trimmed, '0X')) {
                return intval($trimmed, 0);
            }

            if (!is_numeric($trimmed)) {
                throw new RuntimeException($context . ' must be numeric.');
            }

            return (int) $trimmed;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new RuntimeException($context . ' must be numeric.');
    }

    private function normalizePriorityValue(?string $priority): int
    {
        return match (strtoupper((string) $priority)) {
            'P1' => 1,
            'P2' => 2,
            'P3' => 3,
            default => 0,
        };
    }

    private function buildSequenceString(array $steps): string
    {
        if ($steps === []) {
            throw new InvalidArgumentException('Sekvence musí obsahovat alespoň jeden krok.');
        }

        $symbols = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $rawSymbol = $step['symbol'] ?? $step['slot'] ?? null;
            if ($rawSymbol === null) {
                throw new InvalidArgumentException('Sekvence obsahuje krok bez symbolu.');
            }

            $normalized = $this->normalizeSequenceSymbol($rawSymbol);
            if ($normalized === '') {
                throw new InvalidArgumentException('Sekvence obsahuje prázdný symbol.');
            }

            $repeat = (int) max(1, $step['repeat'] ?? 1);
            for ($i = 0; $i < $repeat; $i++) {
                $symbols[] = $normalized;
            }
        }

        if ($symbols === []) {
            throw new InvalidArgumentException('Sekvence musí obsahovat alespoň jeden platný symbol.');
        }

        return implode(',', $symbols);
    }

    private function normalizeSequenceSymbol(mixed $symbol): string
    {
        if ($symbol === null) {
            return '';
        }

        if (is_numeric($symbol)) {
            return strtoupper(dechex((int) $symbol));
        }

        if (is_string($symbol)) {
            $trimmed = strtoupper(trim($symbol));
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function normalizeSequenceInput(string $sequence): string
    {
        $trimmed = strtoupper(trim($sequence));
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', '', $trimmed);
        return is_string($normalized) ? $normalized : $trimmed;
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (str_starts_with($trimmed, '0x') || str_starts_with($trimmed, '0X')) {
                return (int) hexdec(substr($trimmed, 2));
            }
            if (!is_numeric($trimmed)) {
                return null;
            }
            return (int) $trimmed;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (!is_numeric($trimmed)) {
                return null;
            }
            return (float) $trimmed;
        }

        return null;
    }

    private function normalizeTargetsOption(mixed $targets): ?array
    {
        if ($targets === null) {
            return null;
        }

        if (!is_array($targets)) {
            $targets = [$targets];
        }

        $normalized = [];
        foreach ($targets as $target) {
            $value = $this->normalizeOptionalInt($target);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return $normalized === [] ? null : array_values(array_unique($normalized));
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

        if (!isset($normalized['audioOutputId']) || !is_string($normalized['audioOutputId']) || $normalized['audioOutputId'] === '') {
            $normalized['audioOutputId'] = 'lineout';
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
            $requestMetadata = Arr::get($metadata, 'request');
            $requestMetadata = is_array($requestMetadata) ? $requestMetadata : [];

            $normalizedItem = [
                'symbol' => Arr::get($metadata, 'symbol'),
                'slot' => $item->slot,
                'category' => $item->category,
            ];

            $preferredSymbol = $this->resolveOriginalSymbol($normalizedItem, $requestMetadata);

            $fallback = $this->resolveFallbackAudio(
                $normalizedItem,
                $requestMetadata,
                $preferredSymbol
            );
            if ($fallback !== null) {
                $metadata = array_merge($metadata, $fallback);
                if (isset($fallback['symbol'])) {
                    if (!isset($metadata['request']) || !is_array($metadata['request'])) {
                        $metadata['request'] = $requestMetadata;
                    }
                    $metadata['symbol'] = $fallback['symbol'];
                    $metadata['request']['symbol'] = $fallback['symbol'];
                }
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

        $preferredSymbol = $this->resolveOriginalSymbol($normalizedItem, $originalRequest);

        $requestPayload = $originalRequest ?: $normalizedItem;
        if (!is_array($requestPayload)) {
            $requestPayload = $normalizedItem;
        }
        if ($preferredSymbol !== null) {
            $requestPayload['symbol'] = $preferredSymbol;
            $metadata['symbol'] = $preferredSymbol;
        }

        $metadata['request'] = $requestPayload;

        $symbolForMapping = $metadata['symbol'] ?? ($normalizedItem['symbol'] ?? null);
        $dtrxMapping = $this->resolveDtrxMapping($symbolForMapping);
        if ($dtrxMapping !== null) {
            $metadata['dtrx'] = $dtrxMapping;
        } elseif (array_key_exists('dtrx', $metadata)) {
            unset($metadata['dtrx']);
        }

        if (!$this->hasUsableAudioPath($metadata)) {
            $fallback = $this->resolveFallbackAudio($normalizedItem, $originalRequest, $preferredSymbol);
            if ($fallback !== null) {
                $metadata = array_merge($metadata, $fallback);
                if (isset($fallback['symbol']) && $fallback['symbol'] !== null) {
                    $metadata['symbol'] = $fallback['symbol'];
                    $metadata['request']['symbol'] = $fallback['symbol'];
                }
            }
        }

        $finalSymbol = $metadata['symbol'] ?? ($normalizedItem['symbol'] ?? null);
        $finalMapping = $this->resolveDtrxMapping($finalSymbol);
        if ($finalMapping !== null) {
            $metadata['dtrx'] = $finalMapping;
        } elseif (array_key_exists('dtrx', $metadata)) {
            unset($metadata['dtrx']);
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

    private function resolveFallbackAudio(array $normalizedItem, array $originalRequest = [], ?string $resolvedOriginalSymbol = null): ?array
    {
        $symbol = $resolvedOriginalSymbol;
        if (!is_string($symbol) || $symbol === '') {
            $candidates = [
                Arr::get($originalRequest, 'symbol'),
                Arr::get($originalRequest, 'slot'),
                Arr::get($normalizedItem, 'symbol'),
                Arr::get($normalizedItem, 'slot'),
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate)) {
                    $candidate = strtoupper(trim($candidate));
                    if ($candidate !== '') {
                        $symbol = $candidate;
                        break;
                    }
                } elseif (is_numeric($candidate)) {
                    $symbol = (string) ((int) $candidate);
                    break;
                }
            }
        }

        if (!is_string($symbol) || $symbol === '') {
            return null;
        }

        $lookupSymbol = $symbol;

        /** @var JsvvAudio|null $audio */
        $audio = JsvvAudio::query()->with('file')->find($lookupSymbol);
        if ($audio === null && !ctype_digit($lookupSymbol)) {
            try {
                $numeric = $this->resolveSlotValue($lookupSymbol, 0, $lookupSymbol);
                $audio = JsvvAudio::query()->with('file')->find((string) $numeric);
            } catch (\Throwable) {
                $audio = null;
            }
        }

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

    private function resolveOriginalSymbol(array $normalizedItem, array $originalRequest = []): ?string
    {
        $candidates = [
            Arr::get($originalRequest, 'symbol'),
            Arr::get($originalRequest, 'slot'),
            Arr::get($normalizedItem, 'symbol'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = strtoupper(trim($candidate));
            if ($normalized === '') {
                continue;
            }
            if (!ctype_digit($normalized)) {
                return $normalized;
            }
        }

        return null;
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

        ActivityLog::create([
            'type' => 'jsvv',
            'title' => $this->sequenceLogTitle($event, $sequence),
            'description' => $this->sequenceLogDescription($event, $sequence, $playbackMode),
            'data' => $payload,
        ]);
    }

    private function sequenceLogTitle(string $event, JsvvSequence $sequence): string
    {
        return match ($event) {
            'started' => 'JSVV sekvence spuštěna',
            'completed' => 'JSVV sekvence dokončena',
            'failed' => 'JSVV sekvence selhala',
            default => 'JSVV sekvence ' . $event,
        } . ' #' . $sequence->id;
    }

    private function sequenceLogDescription(string $event, JsvvSequence $sequence, string $playbackMode): string
    {
        $priority = $sequence->priority ?? 'neuvedeno';
        return match ($event) {
            'started' => sprintf('Sekvence (priorita %s) byla spuštěna. Režim přehrávání: %s.', $priority, $playbackMode),
            'completed' => sprintf('Sekvence (priorita %s) doběhla do konce. Režim přehrávání: %s.', $priority, $playbackMode),
            'failed' => sprintf('Sekvence (priorita %s) byla přerušena nebo selhala. Režim přehrávání: %s.', $priority, $playbackMode),
            default => sprintf('Sekvence (priorita %s) změnila stav na %s. Režim: %s.', $priority, $event, $playbackMode),
        };
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
