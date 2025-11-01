<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Jobs\ProcessRecordingPlaylist;
use App\Libraries\PythonClient;
use App\Models\ControlChannelCommand;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\BroadcastSession;
use App\Models\Log as ActivityLog;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\Schedule;
use App\Models\StreamTelemetryEntry;
use App\Services\Mixer\AudioRoutingService;
use App\Services\Mixer\MixerController;
use App\Services\Mixer\PulseLoopbackManager;
use App\Services\ControlChannelService;
use App\Services\FmRadioService;
use App\Services\VolumeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StreamOrchestrator extends Service
{
    private const MAX_DEST_ZONES = 5;
    private const JSVV_ACTIVE_LOCK_KEY = 'jsvv:sequence:active';
    private const MODBUS_LOCK_KEY = 'modbus:serial';

    private ?ControlChannelService $controlChannel;

    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly MixerController $mixer = new MixerController(),
        private readonly AudioRoutingService $audioRouting = new AudioRoutingService(),
        private readonly PulseLoopbackManager $loopbackManager = new PulseLoopbackManager(),
        private readonly FmRadioService $fmRadio = new FmRadioService(),
        ?ControlChannelService $controlChannel = null,
    ) {
        parent::__construct();
        $this->controlChannel = $this->resolveControlChannel($controlChannel);
    }

    public function start(array $payload): array
    {
        $manualRoute = $this->normalizeNumericArray(Arr::get($payload, 'route', []));
        $originalLocationGroupIds = $this->normalizeNumericArray(
            Arr::get($payload, 'locations', Arr::get($payload, 'zones', [])),
        );
        [$locationGroupIds, $includeOrphanNests] = $this->prepareLocationGroupSelection($originalLocationGroupIds);
        $originalNestIds = $this->normalizeNumericArray(Arr::get($payload, 'nests', []));
        $nestIds = $includeOrphanNests ? $this->mergeUnassignedNestIds($originalNestIds) : $originalNestIds;
        $options = Arr::get($payload, 'options', []);
        $source = (string) Arr::get($payload, 'source', 'unknown');

        $targets = $this->resolveTargets($locationGroupIds, $nestIds);
        $route = $this->resolveRoute($manualRoute, $targets);
        $zones = $targets['zones'];
        $augmentedOptions = $this->augmentOptions($options, $manualRoute, $originalLocationGroupIds, $originalNestIds, $targets);
        $augmentedOptions = $this->applyFrequencyOption($augmentedOptions);
        $modbusUnitId = Arr::get($augmentedOptions, 'modbusUnitId');

        if ($zones === []) {
            throw new InvalidArgumentException('Destination zones must be defined before starting broadcast.');
        }
        if ($source !== 'jsvv' && Cache::has(self::JSVV_ACTIVE_LOCK_KEY)) {
            throw new BroadcastLockedException('JSVV sequence is currently running');
        }

        $controlReason = sprintf('Live broadcast start (source=%s)', $source);
        $resumeCommand = $this->sendControlChannelCommand('resume', $controlReason);
        $this->assertControlChannelSuccess($resumeCommand, 'resume', $controlReason);

        $logContext = [
            'route' => $route,
            'zones' => $zones,
            'options' => $augmentedOptions,
            'requested_route' => $manualRoute,
            'location_group_ids' => $locationGroupIds,
            'nest_ids' => $nestIds,
        ];

        $logAction = 'start';
        $logSessionId = null;
        $sessionResult = [];
        $response = null;
        $streamStarted = false;
        $resumeTelemetryRecorded = false;

        $active = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        try {
            if ($active !== null) {
                $logAction = 'update';

                $this->mixer->activatePreset($source, [
                    'options' => $augmentedOptions,
                    'route' => $route,
                    'zones' => $zones,
                    'session_id' => $active->id,
                ]);

                $this->applyAudioRouting($augmentedOptions, $source, $active->id);
                $this->applySourceVolume($source);

                $response = $this->withModbusLock(fn (): array => $this->client->startStream(
                    $route !== [] ? $route : null,
                    $zones,
                    null,
                    false,
                    $modbusUnitId !== null ? (int) $modbusUnitId : null
                ));
                $streamStarted = true;

                $this->verifyTransmitterState(true, 'start_update', [
                    'session_id' => $active->id,
                    'source' => $source,
                ]);

                $active->update([
                    'source' => $source,
                    'route' => $route,
                    'zones' => $zones,
                    'options' => $augmentedOptions,
                    'python_response' => $response,
                ]);

                $this->recordTelemetry([
                    'type' => 'stream_updated',
                    'session_id' => $active->id,
                    'payload' => [
                        'source' => $source,
                        'route' => $route,
                        'zones' => $zones,
                    ],
                ]);

                $this->recordControlChannelTelemetry('resume', $resumeCommand, $active->id);
                $resumeTelemetryRecorded = true;

                $active = $active->fresh();
                if ($active !== null) {
                    $this->handleEmbeddedPlaylist($active, $augmentedOptions, $manualRoute, $locationGroupIds, $nestIds);
                    $active = $active->fresh();
                }

                $logSessionId = $active?->id;
                $sessionResult = $active?->toArray() ?? [];
            } else {
                $this->mixer->activatePreset($source, [
                    'options' => $augmentedOptions,
                    'route' => $route,
                    'zones' => $zones,
                ]);

                $this->applyAudioRouting($augmentedOptions, $source, null);
                $this->applySourceVolume($source);

                $response = $this->withModbusLock(fn (): array => $this->client->startStream(
                    $route !== [] ? $route : null,
                    $zones,
                    null,
                    false,
                    $modbusUnitId !== null ? (int) $modbusUnitId : null
                ));
                $streamStarted = true;

                $this->verifyTransmitterState(true, 'start_new', [
                    'source' => $source,
                ]);

                $session = BroadcastSession::create([
                    'source' => $source,
                    'route' => $route,
                    'zones' => $zones,
                    'options' => $augmentedOptions,
                    'status' => 'running',
                    'started_at' => now(),
                    'python_response' => $response,
                ]);

                $this->recordTelemetry([
                    'type' => 'stream_started',
                    'session_id' => $session->id,
                    'payload' => [
                        'source' => $session->source,
                        'route' => $route,
                        'zones' => $zones,
                    ],
                ]);

                $this->recordControlChannelTelemetry('resume', $resumeCommand, $session->id);
                $resumeTelemetryRecorded = true;

                $session = $session->fresh();
                if ($session !== null) {
                    $this->handleEmbeddedPlaylist($session, $augmentedOptions, $manualRoute, $locationGroupIds, $nestIds);
                    $session = $session->fresh();
                }

                $logSessionId = $session?->id;
                $sessionResult = $session?->toArray() ?? [];
            }

            $this->logBroadcastEvent($logAction, 'success', $source, $logSessionId, $logContext + [
                'modbus_response' => $response,
            ]);

            return $sessionResult;
        } catch (\Throwable $exception) {
            if ($streamStarted) {
                try {
                    $this->withModbusLock(fn (): array => $this->client->stopStream(
                        null,
                        $modbusUnitId !== null ? (int) $modbusUnitId : null
                    ));
                } catch (\Throwable $stopException) {
                    Log::warning('Failed to rollback Modbus stream after unsuccessful start.', [
                        'error' => $stopException->getMessage(),
                        'source' => $source,
                    ]);
                }
            }

            try {
                $this->mixer->reset([
                    'session_id' => $logSessionId,
                    'reason' => 'start_failed',
                ]);
            } catch (\Throwable $resetException) {
                Log::debug('Unable to reset mixer after failed start.', [
                    'error' => $resetException->getMessage(),
                ]);
            }

            $this->loopbackManager->clear();

            if (!$resumeTelemetryRecorded && $resumeCommand !== null) {
                $this->recordControlChannelTelemetry('resume', $resumeCommand);
            }

            try {
                $pauseCommand = $this->sendControlChannelCommand('pause', sprintf('Live broadcast rollback (source=%s)', $source));
                $this->assertControlChannelSuccess($pauseCommand, 'pause', 'live_broadcast_start_failed');
                $this->recordControlChannelTelemetry('pause', $pauseCommand, $logSessionId);
            } catch (\Throwable $pauseException) {
                Log::warning('Unable to revert control channel state after failed live broadcast start.', [
                    'error' => $pauseException->getMessage(),
                    'source' => $source,
                ]);
            }

            $this->logBroadcastEvent($logAction, 'failed', $source, $logSessionId, $logContext + [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function stop(?string $reason = null): array
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null) {
            return [
                'status' => 'idle',
                'message' => 'No active session',
            ];
        }

        $controlReason = sprintf('Live broadcast stop (%s)', $reason ?? 'manual');
        $sessionOptions = is_array($session->options) ? $session->options : [];
        $modbusUnitId = Arr::get($sessionOptions, 'modbusUnitId');
        $logContext = [
            'reason' => $reason,
            'modbus_unit_id' => $modbusUnitId,
        ];
        $response = null;

        try {
            $response = $this->withModbusLock(fn (): array => $this->client->stopStream(
                null,
                $modbusUnitId !== null ? (int) $modbusUnitId : null
            ));

            $this->verifyTransmitterState(false, 'stop', [
                'session_id' => $session->id,
                'source' => $session->source,
            ]);

            $session->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => $reason,
                'python_response' => $response,
            ]);
            $session = $session->fresh() ?? $session;
            $this->cancelEmbeddedPlaylist($session);

            $this->mixer->reset([
                'session_id' => $session->id,
                'reason' => $reason,
            ]);

            $this->loopbackManager->clear();

            $this->recordTelemetry([
                'type' => 'stream_stopped',
                'session_id' => $session->id,
                'payload' => [
                    'reason' => $reason,
                ],
            ]);

            $pauseCommand = $this->sendControlChannelCommand('pause', $controlReason);
            $this->assertControlChannelSuccess($pauseCommand, 'pause', $controlReason);
            $this->recordControlChannelTelemetry('pause', $pauseCommand, $session->id);

            $result = $session->fresh()->toArray();

            $this->logBroadcastEvent('stop', 'success', $session->source, $session->id, $logContext + [
                'modbus_response' => $response,
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->loopbackManager->clear();

            try {
                $pauseCommand = $this->sendControlChannelCommand('pause', sprintf('Live broadcast stop rollback (source=%s)', $session->source));
                $this->assertControlChannelSuccess($pauseCommand, 'pause', 'live_broadcast_stop_failed');
                $this->recordControlChannelTelemetry('pause', $pauseCommand, $session->id);
            } catch (\Throwable $pauseException) {
                Log::warning('Unable to revert control channel state after failed live broadcast stop.', [
                    'error' => $pauseException->getMessage(),
                    'session_id' => $session->id,
                ]);
            }

            $this->logBroadcastEvent('stop', 'failed', $session->source, $session->id, $logContext + [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function clearLivePlaylistState(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $session = BroadcastSession::query()->find($sessionId);
        if ($session === null) {
            return;
        }

        $this->cancelEmbeddedPlaylist($session);
    }

    public function getStatusDetails(): array
    {
        $session = BroadcastSession::query()->latest('created_at')->first();
        $status = $this->client->getStatusRegisters();
        $device = $this->client->getDeviceInfo();

        $details = [
            'session' => $session?->toArray(),
            'status' => $status,
            'device' => $device,
        ];

        if ($details['session'] !== null) {
            /** @var array<string, mixed> $sessionArray */
            $sessionArray = $details['session'];
            $selection = Arr::get($sessionArray, 'options._selection', []);
            $resolved = Arr::get($sessionArray, 'options._resolved', [
                'route' => $sessionArray['route'] ?? [],
                'zones' => $sessionArray['zones'] ?? [],
            ]);
            $labels = Arr::get($sessionArray, 'options._labels', []);

            $sessionArray['locations'] = Arr::get($selection, 'locations', []);
            $sessionArray['nests'] = Arr::get($selection, 'nests', []);
            $sessionArray['requestedRoute'] = Arr::get($selection, 'route', $sessionArray['route'] ?? []);
            $sessionArray['applied'] = $resolved;
            $sessionArray['labels'] = $labels;

            $details['session'] = $sessionArray;
        }

        $nextSchedule = Schedule::query()
            ->whereNull('processed_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $details['next_schedule'] = $nextSchedule ? [
            'id' => $nextSchedule->getId(),
            'title' => $nextSchedule->getTitle(),
            'scheduled_at' => $nextSchedule->getScheduledAt()?->toIso8601String(),
        ] : null;

        $details['control_channel'] = $this->controlChannelSummary();

        return $details;
    }

    public function listSources(): array
    {
        try {
            /** @var \App\Services\Mixer\AudioDeviceService $deviceService */
            $deviceService = app(\App\Services\Mixer\AudioDeviceService::class);
            $devices = $deviceService->listDevices();
        } catch (\Throwable $exception) {
            Log::warning('Unable to detect audio devices for source list.', [
                'exception' => $exception->getMessage(),
            ]);
            $devices = [];
        }

        $pulseSources = collect($devices['pulse']['sources'] ?? [])
            ->filter(fn ($source) => is_array($source));

        $physicalCapture = collect($devices['capture_devices'] ?? [])
            ->filter(fn ($device) => is_array($device) && !isset($device['error']));

        $hasPhysicalCapture = $physicalCapture->isNotEmpty();
        $hasPulseMonitor = $pulseSources->contains(function ($source) {
            $name = $source['name'] ?? '';
            return is_string($name) && str_contains($name, '.monitor');
        });
        $hasPulseMicrophone = $pulseSources->contains(function ($source) {
            $name = $source['name'] ?? '';
            return is_string($name) && !str_contains($name, '.monitor');
        });

        $hasCapturePath = $hasPhysicalCapture || $hasPulseMicrophone;
        $hasSystemPlaybackTap = $hasPulseMonitor;

        $sources = [
            $this->makeSourceDefinition(
                'microphone',
                'Mikrofon',
                $hasCapturePath,
                $hasCapturePath ? null : 'Nebyl nalezen žádný mikrofon nebo jiný vstup.'
            ),
            ['id' => 'central_file', 'label' => 'Soubor v ústředně', 'available' => true],
            $this->makeSourceDefinition(
                'pc_webrtc',
                'Vstup z PC (WebRTC)',
                $hasSystemPlaybackTap,
                $hasSystemPlaybackTap ? null : 'Není dostupný systémový zvukový výstup (monitor).'
            ),
            $this->makeSourceDefinition(
                'system_audio',
                'Systémový zvuk',
                $hasSystemPlaybackTap,
                $hasSystemPlaybackTap ? null : 'Není dostupný systémový zvukový výstup (monitor).'
            ),
            ['id' => 'fm_radio', 'label' => 'FM Rádio', 'available' => true],
            $this->makeSourceDefinition(
                'jsvv_remote_voice',
                'JSVV – Vzdálený hlas',
                $hasSystemPlaybackTap,
                $hasSystemPlaybackTap ? null : 'Není dostupný systémový zvukový výstup (monitor).'
            ),
            $this->makeSourceDefinition(
                'jsvv_local_voice',
                'JSVV – Místní mikrofon',
                $hasCapturePath,
                $hasCapturePath ? null : 'Nebyl nalezen žádný mikrofon nebo jiný vstup.'
            ),
            $this->makeSourceDefinition(
                'jsvv_external_primary',
                'JSVV – Externí audio (primární)',
                $hasSystemPlaybackTap,
                $hasSystemPlaybackTap ? null : 'Není dostupný systémový zvukový výstup (monitor).'
            ),
            $this->makeSourceDefinition(
                'jsvv_external_secondary',
                'JSVV – Externí audio (sekundární)',
                $hasSystemPlaybackTap,
                $hasSystemPlaybackTap ? null : 'Není dostupný systémový zvukový výstup (monitor).'
            ),
            $this->makeSourceDefinition(
                'control_box',
                'Control box',
                $hasCapturePath,
                $hasCapturePath ? null : 'Nebyl nalezen žádný audio vstup pro Control box.'
            ),
        ];

        for ($index = 2; $index <= 9; $index++) {
            $sources[] = $this->makeSourceDefinition(
                'input_' . $index,
                'Vstup ' . $index,
                $hasCapturePath,
                $hasCapturePath ? null : 'Nebyl nalezen žádný audio vstup.'
            );
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSourceDefinition(string $id, string $label, bool $available, ?string $reason = null): array
    {
        $definition = [
            'id' => $id,
            'label' => $label,
            'available' => $available,
        ];

        if ($reason !== null) {
            $definition['unavailable_reason'] = $reason;
        }

        return $definition;
    }

    public function enqueuePlaylist(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $items = Arr::get($payload, 'recordings', []);
            $manualRoute = $this->normalizeNumericArray(Arr::get($payload, 'route', []));
            $originalLocationGroupIds = $this->normalizeNumericArray(
                Arr::get($payload, 'locations', Arr::get($payload, 'zones', [])),
            );
            [$locationGroupIds, $includeOrphanNests] = $this->prepareLocationGroupSelection($originalLocationGroupIds);
            $originalNestIds = $this->normalizeNumericArray(Arr::get($payload, 'nests', []));
            $nestIds = $includeOrphanNests ? $this->mergeUnassignedNestIds($originalNestIds) : $originalNestIds;
            $options = Arr::get($payload, 'options', []);

            $targets = $this->resolveTargets($locationGroupIds, $nestIds);

            $playlist = BroadcastPlaylist::create([
                'route' => $manualRoute,
                'zones' => $targets['zones'],
                'options' => $this->augmentOptions($options, $manualRoute, $originalLocationGroupIds, $originalNestIds, $targets),
                'status' => 'queued',
            ]);

            foreach ($items as $index => $item) {
                BroadcastPlaylistItem::create([
                    'playlist_id' => $playlist->id,
                    'position' => $index,
                    'recording_id' => (string) Arr::get($item, 'id'),
                    'duration_seconds' => Arr::get($item, 'durationSeconds'),
                    'gain' => Arr::get($item, 'gain'),
                    'gap_ms' => Arr::get($item, 'gapMs'),
                    'metadata' => $item,
                ]);
            }

            Bus::dispatch(new ProcessRecordingPlaylist($playlist->id));

            $this->recordTelemetry([
                'type' => 'playlist_queued',
                'playlist_id' => $playlist->id,
                'payload' => [
                    'count' => count($items),
                    'route' => $manualRoute,
                    'zones' => $targets['zones'],
                ],
            ]);

            return $playlist->load('items')->toArray();
        });
    }

    public function cancelPlaylist(string $playlistId): array
    {
        $playlist = BroadcastPlaylist::query()->with('items')->find($playlistId);
        if ($playlist === null) {
            return [
                'status' => 'not_found',
                'playlistId' => $playlistId,
            ];
        }

        $playlist->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->recordTelemetry([
            'type' => 'playlist_cancelled',
            'playlist_id' => $playlist->id,
            'payload' => [],
        ]);

        return $playlist->fresh()->toArray();
    }

    public function getPlaylist(string $playlistId): ?array
    {
        return BroadcastPlaylist::query()->with('items')->find($playlistId)?->toArray();
    }

    public function updatePlaylist(string $playlistId, array $attributes): void
    {
        BroadcastPlaylist::query()->whereKey($playlistId)->update($attributes + ['updated_at' => now()]);
    }

    public function recordTelemetry(array $entry): void
    {
        StreamTelemetryEntry::create([
            'type' => $entry['type'],
            'session_id' => $entry['session_id'] ?? null,
            'playlist_id' => $entry['playlist_id'] ?? null,
            'payload' => $entry['payload'] ?? [],
            'recorded_at' => now(),
        ]);
    }

    public function telemetry(?string $since = null): array
    {
        return StreamTelemetryEntry::query()
            ->when($since, static fn ($query) => $query->where('recorded_at', '>=', $since))
            ->latest('recorded_at')
            ->limit(500)
            ->get()
            ->toArray();
    }

    public function getResponse(): JsonResponse
    {
        return response()->json($this->getStatusDetails());
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

    private function resolveControlChannel(?ControlChannelService $service): ?ControlChannelService
    {
        if ($service !== null) {
            return $service;
        }

        try {
            return app(ControlChannelService::class);
        } catch (Throwable $exception) {
            Log::warning('Control channel service is not available', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sendControlChannelCommand(string $action, string $reason): ?ControlChannelCommand
    {
        if ($this->controlChannel === null) {
            return null;
        }

        try {
            return match ($action) {
                'resume' => $this->controlChannel->resume(reason: $reason),
                'pause' => $this->controlChannel->pause(reason: $reason),
                'stop' => $this->controlChannel->stop(reason: $reason),
                'status' => $this->controlChannel->status(reason: $reason),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::error('Control channel command failed', [
                'action' => $action,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);

            $this->recordTelemetry([
                'type' => 'control_channel_exception',
                'payload' => [
                    'action' => $action,
                    'reason' => $reason,
                    'error' => $exception->getMessage(),
                ],
            ]);

            return null;
        }
    }

    private function assertControlChannelSuccess(?ControlChannelCommand $command, string $action, string $reason): void
    {
        if ($command === null) {
            return;
        }

        if ($command->result === 'SKIPPED') {
            return;
        }

        if ($command->result !== 'OK') {
            $payload = $command->payload ?? [];
            $errorDetail = Arr::get($payload, 'error')
                ?? Arr::get($payload, 'response.details.error')
                ?? Arr::get($payload, 'details.error');

            throw new RuntimeException(sprintf(
                'Control channel %s failed (%s): %s',
                $action,
                $reason,
                $errorDetail !== null ? $errorDetail : $command->result
            ));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function verifyTransmitterState(bool $shouldRun, string $stage, array $context = []): void
    {
        try {
            $status = $this->withModbusLock(fn (): array => $this->client->getStatusRegisters());
        } catch (\Throwable $exception) {
            Log::warning('Unable to read transmitter status for verification.', [
                'stage' => $stage,
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nepodařilo se ověřit stav vysílání (čtení Modbus registrů selhalo).', 0, $exception);
        }

        if (Arr::get($status, 'json.data.skipped', false) === true) {
            Log::debug('Transmitter verification skipped because Modbus is not configured.', [
                'stage' => $stage,
                'context' => $context,
            ]);
            return;
        }

        if (($status['success'] ?? false) === false) {
            Log::warning('Transmitter status command returned an error.', [
                'stage' => $stage,
                'context' => $context,
                'status' => $status,
            ]);

            throw new RuntimeException('Nepodařilo se ověřit stav vysílání: Modbus odpověděl chybou.');
        }

        $txControl = Arr::get($status, 'json.data.registers.txControl', Arr::get($status, 'json.data.registers.tx_control'));
        if (!is_numeric($txControl)) {
            Log::warning('Transmitter status missing txControl register.', [
                'stage' => $stage,
                'context' => $context,
                'status' => $status,
            ]);

            throw new RuntimeException('Zařízení neposkytlo platný stav vysílání.');
        }

        $expected = $shouldRun ? 2 : 1;
        if ((int) $txControl !== $expected) {
            Log::warning('Transmitter state does not match expected value.', [
                'stage' => $stage,
                'context' => $context,
                'tx_control' => $txControl,
                'expected' => $expected,
            ]);

            throw new RuntimeException($shouldRun
                ? 'Zařízení nepotvrdilo spuštění vysílání.'
                : 'Zařízení nepotvrdilo zastavení vysílání.'
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logBroadcastEvent(string $action, string $result, string $source, ?string $sessionId, array $context = []): void
    {
        $actionLabel = match ($action) {
            'start' => 'spuštění',
            'update' => 'aktualizace',
            'stop' => 'zastavení',
            default => $action,
        };

        $description = $result === 'success'
            ? sprintf('Akce %s vysílání (zdroj "%s") proběhla úspěšně.', $actionLabel, $source)
            : sprintf('Akce %s vysílání (zdroj "%s") selhala.', $actionLabel, $source);

        if ($result !== 'success' && isset($context['error']) && is_string($context['error'])) {
            $description .= ' ' . $context['error'];
        }

        $data = array_merge([
            'action' => $action,
            'result' => $result,
            'source' => $source,
            'session_id' => $sessionId,
        ], $context);

        try {
            ActivityLog::create([
                'type' => 'broadcast',
                'title' => 'Živé vysílání',
                'description' => $description,
                'data' => $data,
            ]);
        } catch (\Throwable $exception) {
            Log::debug('Unable to record broadcast activity log.', [
                'action' => $action,
                'result' => $result,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function recordControlChannelTelemetry(string $action, ?ControlChannelCommand $command, int|string|null $sessionId = null): void
    {
        if ($command === null) {
            return;
        }

        $payload = [
            'command_id' => $command->id,
            'result' => $command->result,
            'state_before' => $command->state_before,
            'state_after' => $command->state_after,
            'reason' => $command->reason,
            'issued_at' => $command->issued_at?->toIso8601String(),
            'details' => $command->payload,
        ];

        $entry = [
            'type' => 'control_channel_' . $action,
            'payload' => $payload,
        ];

        if ($sessionId !== null) {
            if (is_string($sessionId) && !ctype_digit($sessionId)) {
                $sessionId = null;
            } elseif ($sessionId !== null) {
                $sessionId = (int) $sessionId;
            }
        }

        if ($sessionId !== null) {
            $entry['session_id'] = $sessionId;
        }

        $this->recordTelemetry($entry);
    }

    private function controlChannelSummary(): ?array
    {
        $command = $this->sendControlChannelCommand('status', 'live_broadcast_status');
        if ($command === null) {
            return null;
        }

        $payload = $command->payload ?? [];
        $response = Arr::get($payload, 'response');
        $response = is_array($response) ? $response : [];

        return [
            'result' => $command->result,
            'state' => $response['state'] ?? $command->state_after ?? $command->state_before,
            'stateBefore' => $command->state_before,
            'stateAfter' => $command->state_after,
            'details' => $response['details'] ?? Arr::get($payload, 'details', $payload),
            'latencyMs' => $response['latencyMs'] ?? Arr::get($payload, 'latency_ms'),
            'issuedAt' => $command->issued_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<int, mixed>|mixed $values
     * @return array<int, int>
     */
    private function normalizeNumericArray(mixed $values): array
    {
        $values = Arr::wrap($values);
        $result = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $result[] = (int) $value;
            }
        }

        $result = array_values(array_unique($result));

        return $result;
    }

    /**
     * Expand special selections (e.g. Control Tab "General") and flag whether orphan nests should be included.
     *
     * @param array<int, int> $locationGroupIds
     * @return array{0: array<int, int>, 1: bool}
     */
    private function prepareLocationGroupSelection(array $locationGroupIds): array
    {
        if ($locationGroupIds === []) {
            return [$locationGroupIds, false];
        }

        $defaultGroupId = (int) config('control_tab.default_location_group_id', 0);
        if ($defaultGroupId > 0 && in_array($defaultGroupId, $locationGroupIds, true)) {
            $allGroupIds = LocationGroup::query()->pluck('id')->map(static fn ($id) => (int) $id)->all();
            if ($allGroupIds !== []) {
                return [array_values(array_unique($allGroupIds)), true];
            }
            return [$locationGroupIds, true];
        }

        return [$locationGroupIds, false];
    }

    /**
     * Merge unassigned nest IDs into the selection.
     *
     * @param array<int, int> $nestIds
     * @return array<int, int>
     */
    private function mergeUnassignedNestIds(array $nestIds): array
    {
        $orphanIds = Location::query()
            ->whereNull('location_group_id')
            ->where('type', 'NEST')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($orphanIds === []) {
            return $nestIds;
        }

        return array_values(array_unique(array_merge($nestIds, $orphanIds)));
    }

    /**
     * Resolve requested locations and nests into Modbus zone addresses.
     *
     * @param array<int, int> $locationGroupIds
     * @param array<int, int> $nestIds
     * @return array{
     *     zones: array<int, int>,
     *     locationAddresses: array<int, int>,
     *     nestAddresses: array<int, int>,
     *     groupSummaries: array<int, array{id: int, name: string}>,
     *     nestSummaries: array<int, array{id: int, name: string, modbus_address: int}>,
     *     missingGroups: array<int, int>,
     *     missingLocationIds: array<int, int>,
     *     missingNests: array<int, int>
     * }
     */
    private function resolveTargets(array $locationGroupIds, array $nestIds): array
    {
        /** @var Collection<int, LocationGroup> $groups */
        $groups = $locationGroupIds !== []
            ? LocationGroup::query()->whereIn('id', $locationGroupIds)->get(['id', 'name', 'modbus_group_address'])
            : collect();

        $groupSummaries = $groups
            ->map(static fn (LocationGroup $group) => [
                'id' => (int) $group->id,
                'name' => $group->getName(),
                'modbus_group_address' => $group->getModbusGroupAddress(),
            ])
            ->values()
            ->all();

        $resolvedGroupIds = $groups->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $missingGroups = array_values(array_diff($locationGroupIds, $resolvedGroupIds));

        $groupAddressMap = $groups
            ->filter(static fn (LocationGroup $group) => $group->getModbusGroupAddress() !== null)
            ->mapWithKeys(static fn (LocationGroup $group) => [
                (int) $group->id => (int) $group->getModbusGroupAddress(),
            ])
            ->all();
        $groupAddresses = array_values(array_unique(array_values($groupAddressMap)));

        $groupsWithoutAddress = $groups
            ->filter(static fn (LocationGroup $group) => $group->getModbusGroupAddress() === null)
            ->map(static fn (LocationGroup $group) => (int) $group->id)
            ->all();

        /** @var Collection<int, Location> $groupLocations */
        $groupLocations = $locationGroupIds !== []
            ? Location::query()
                ->whereIn('location_group_id', $locationGroupIds)
                ->where('type', 'NEST')
                ->get(['id', 'name', 'modbus_address', 'location_group_id'])
            : collect();

        /** @var Collection<int, Location> $nestRecords */
        $nestRecords = $nestIds !== []
            ? Location::query()
                ->whereIn('id', $nestIds)
                ->where('type', 'NEST')
                ->get(['id', 'name', 'modbus_address', 'location_group_id'])
            : collect();

        $locationAddresses = $groupLocations
            ->filter(static function (Location $location) use ($groupAddressMap) {
                $groupId = $location->location_group_id !== null ? (int) $location->location_group_id : null;
                if ($groupId === null || !array_key_exists($groupId, $groupAddressMap)) {
                    return $location->getModbusAddress() !== null;
                }

                // Group has a shared address; we can rely on it instead of individual ones.
                return false;
            })
            ->map(static fn (Location $location) => (int) $location->getModbusAddress())
            ->unique()
            ->values()
            ->all();
        sort($locationAddresses);

        $nestSummaries = $nestRecords
            ->filter(static fn (Location $location) => $location->getModbusAddress() !== null)
            ->unique('id')
            ->map(static fn (Location $location) => [
                'id' => (int) $location->getId(),
                'name' => $location->getName(),
                'modbus_address' => (int) $location->getModbusAddress(),
                'location_group_id' => $location->location_group_id !== null
                    ? (int) $location->location_group_id
                    : null,
            ])
            ->values()
            ->all();

        $nestAddresses = collect($nestSummaries)
            ->filter(static function (array $summary) use ($groupAddressMap, $locationGroupIds) {
                if ($summary['location_group_id'] === null) {
                    return true;
                }
                if (!in_array($summary['location_group_id'], $locationGroupIds, true)) {
                    return true;
                }

                return !array_key_exists($summary['location_group_id'], $groupAddressMap);
            })
            ->pluck('modbus_address')
            ->map(static fn ($address) => (int) $address)
            ->unique()
            ->values()
            ->all();
        sort($nestAddresses);

        $missingLocationIds = $groupLocations
            ->filter(static fn (Location $location) => $location->getModbusAddress() === null)
            ->filter(static function (Location $location) use ($groupAddressMap) {
                $groupId = $location->location_group_id !== null ? (int) $location->location_group_id : null;
                if ($groupId === null) {
                    return true;
                }

                return !array_key_exists($groupId, $groupAddressMap);
            })
            ->map(static fn (Location $location) => (int) $location->getId())
            ->unique()
            ->values()
            ->all();

        $resolvedNestIds = $nestRecords
            ->filter(static fn (Location $location) => $location->getModbusAddress() !== null)
            ->map(static fn (Location $location) => (int) $location->getId())
            ->all();

        $missingNestIds = array_values(
            array_unique(
                array_merge(
                    array_diff($nestIds, $resolvedNestIds),
                    $nestRecords
                        ->filter(static fn (Location $location) => $location->getModbusAddress() === null)
                        ->map(static fn (Location $location) => (int) $location->getId())
                        ->all(),
                ),
            ),
        );

        if ($missingGroups !== []) {
            Log::warning('Some location groups were not found while resolving broadcast targets.', [
                'location_group_ids' => $missingGroups,
            ]);
        }

        if ($missingLocationIds !== []) {
            Log::warning('Some locations are missing a Modbus address.', [
                'location_ids' => $missingLocationIds,
            ]);
        }

        if ($missingNestIds !== []) {
            Log::warning('Some selected nests are missing or lack a Modbus address.', [
                'nest_ids' => $missingNestIds,
            ]);
        }

        $zones = array_values(array_unique(array_merge($groupAddresses, $locationAddresses, $nestAddresses)));
        $zoneOverflow = [];

        if (count($zones) > self::MAX_DEST_ZONES) {
            $zoneOverflow = array_slice($zones, self::MAX_DEST_ZONES);
            $zones = array_slice($zones, 0, self::MAX_DEST_ZONES);

            Log::warning('Resolved destination zones exceed hardware capacity; truncating to supported range.', [
                'max_zones' => self::MAX_DEST_ZONES,
                'applied' => $zones,
                'overflow' => $zoneOverflow,
            ]);
        }

        return [
            'zones' => $zones,
            'locationAddresses' => $locationAddresses,
            'nestAddresses' => $nestAddresses,
            'groupSummaries' => $groupSummaries,
            'nestSummaries' => $nestSummaries,
            'missingGroups' => $missingGroups,
            'missingLocationIds' => $missingLocationIds,
            'missingNests' => $missingNestIds,
            'zoneOverflow' => $zoneOverflow,
            'groupAddresses' => $groupAddresses,
            'groupsWithoutAddress' => $groupsWithoutAddress,
        ];
    }

    /**
     * Apply audio routing commands for the selected input/output devices.
     *
     * @param array<string, mixed> $options
     */
    private function applyAudioRouting(array $options, string $source, int|string|null $sessionId = null): void
    {
        $inputId = Arr::get($options, 'audioInputId');
        if ($inputId === null) {
            $inputId = Arr::get($options, 'audio_input_id');
        }

        $outputId = Arr::get($options, 'audioOutputId');
        if ($outputId === null) {
            $outputId = Arr::get($options, 'audio_output_id');
        }

        if (!is_string($inputId) && !is_string($outputId)) {
            return;
        }

        if (is_string($sessionId) && !ctype_digit($sessionId)) {
            $sessionId = null;
        } elseif ($sessionId !== null) {
            $sessionId = (int) $sessionId;
        }

        $context = array_filter([
            'source' => $source,
            'session_id' => $sessionId,
            'audio_input_id' => is_string($inputId) ? $inputId : null,
            'audio_output_id' => is_string($outputId) ? $outputId : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $this->audioRouting->apply(
            is_string($inputId) ? $inputId : null,
            is_string($outputId) ? $outputId : null,
            $context,
        );

        $inputIdentifier = is_string($inputId) ? $inputId : null;
        $outputIdentifier = is_string($outputId) ? $outputId : null;

        if ($inputIdentifier !== null
            && $outputIdentifier !== null
            && str_starts_with($inputIdentifier, 'pulse:')
            && str_contains($inputIdentifier, '.monitor')
            && str_starts_with($outputIdentifier, 'pulse:')
        ) {
            $this->loopbackManager->ensure($inputIdentifier, $outputIdentifier);
        } else {
            $this->loopbackManager->clear();
        }
    }

    /**
     * Queue or cancel embedded playlist playback for the live broadcast.
     *
     * @param array<int, int> $manualRoute
     * @param array<int, int> $locationGroupIds
     * @param array<int, int> $nestIds
     */
    private function handleEmbeddedPlaylist(
        BroadcastSession $session,
        array $options,
        array $manualRoute,
        array $locationGroupIds,
        array $nestIds,
    ): void {
        if ($session->source !== 'central_file') {
            $this->cancelEmbeddedPlaylist($session);
            return;
        }

        $rawItems = Arr::get($options, 'playlist', []);
        if (!is_array($rawItems) || $rawItems === []) {
            $this->cancelEmbeddedPlaylist($session);
            return;
        }

        $recordings = $this->normalizePlaylistRecordings($rawItems);
        if ($recordings === []) {
            $this->cancelEmbeddedPlaylist($session);
            return;
        }

        $hash = $this->computePlaylistHash($recordings, $manualRoute, $locationGroupIds, $nestIds);
        $sessionOptions = $session->options ?? [];
        if (($sessionOptions['_live_playlist_hash'] ?? null) === $hash) {
            return;
        }

        $this->cancelEmbeddedPlaylist($session);

        $playlistOptions = Arr::except($options, ['playlist']);
        $playlistOptions['_context'] = array_merge(
            Arr::get($playlistOptions, '_context', []),
            [
                'mode' => 'live_broadcast',
                'session_id' => $session->id,
                'source' => $session->source,
            ],
        );

        $playlist = $this->enqueuePlaylist([
            'recordings' => $recordings,
            'route' => $manualRoute,
            'locations' => $locationGroupIds,
            'nests' => $nestIds,
            'options' => $playlistOptions,
        ]);

        $sessionOptions['_live_playlist_id'] = $playlist['id'] ?? null;
        $sessionOptions['_live_playlist_hash'] = $hash;
        $sessionOptions['playlist'] = [
            'items' => $recordings,
            'updated_at' => now()->toIso8601String(),
        ];
        $session->update(['options' => $sessionOptions]);
    }

    /**
     * @param array<int, mixed> $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizePlaylistRecordings(array $rawItems): array
    {
        $normalized = [];

        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $id = Arr::get($item, 'id');
                if ($id === null || $id === '') {
                    continue;
                }

                $recording = $item;
                $recording['id'] = (string) $id;

                foreach (['title', 'name', 'original_name'] as $key) {
                    $candidate = Arr::get($item, $key);
                    if (is_string($candidate) && $candidate !== '') {
                        $recording['title'] = $candidate;
                        break;
                    }
                }

                if (!isset($recording['title']) || $recording['title'] === '') {
                    $recording['title'] = 'ID ' . $recording['id'];
                }

                $duration = Arr::get($item, 'durationSeconds', Arr::get($item, 'duration_seconds'));
                if (is_numeric($duration)) {
                    $recording['durationSeconds'] = (int) $duration;
                }

                $gain = Arr::get($item, 'gain');
                if (is_numeric($gain)) {
                    $recording['gain'] = (float) $gain;
                }

                $gap = Arr::get($item, 'gapMs', Arr::get($item, 'gap_ms'));
                if (is_numeric($gap)) {
                    $recording['gapMs'] = (int) $gap;
                }

                $metadata = Arr::get($recording, 'metadata');
                if (!is_array($metadata)) {
                    $metadata = [];
                }

                $rawFilename = Arr::get($recording, 'filename', Arr::get($metadata, 'filename', Arr::get($metadata, 'file.filename')));
                $extension = $this->normalizeExtension(
                    Arr::get($recording, 'extension', Arr::get($metadata, 'extension')),
                    Arr::get($recording, 'mimeType', Arr::get($recording, 'mime_type', Arr::get($metadata, 'mimeType'))),
                );
                $filename = $this->buildFilename($rawFilename, $extension);
                if ($filename !== null) {
                    $recording['filename'] = $filename;
                    $metadata['filename'] = $metadata['filename'] ?? $filename;
                    $recording['extension'] = $recording['extension'] ?? $extension;
                    $metadata['extension'] = $metadata['extension'] ?? $extension;
                }

                $storagePath = $this->ensureFilePath(
                    Arr::get($recording, 'storage_path', Arr::get($metadata, 'storage_path', Arr::get($metadata, 'file.storage_path', Arr::get($recording, 'path')))),
                    $filename,
                    $extension,
                );
                if ($storagePath !== null) {
                    $recording['storage_path'] = $storagePath;
                    $metadata['storage_path'] = $metadata['storage_path'] ?? $storagePath;
                }

                $absolutePath = $this->ensureFilePath(
                    Arr::get($recording, 'path', Arr::get($metadata, 'path')),
                    $filename,
                    $extension,
                );
                if ($absolutePath !== null) {
                    $recording['path'] = $absolutePath;
                    $metadata['path'] = $metadata['path'] ?? $absolutePath;
                }

                $recording['metadata'] = $metadata;

                $normalized[] = $recording;
            } elseif (is_scalar($item) && $item !== '') {
                $normalized[] = [
                    'id' => (string) $item,
                ];
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, array<string, mixed>> $recordings
     * @param array<int, int> $route
     * @param array<int, int> $locations
     * @param array<int, int> $nests
     */
    private function computePlaylistHash(array $recordings, array $route, array $locations, array $nests): string
    {
        $hashData = [
            'route' => array_values($route),
            'locations' => array_values($locations),
            'nests' => array_values($nests),
            'recordings' => array_map(
                static function (array $recording): array {
                    $entry = [
                        'id' => $recording['id'],
                    ];

                    if (isset($recording['durationSeconds']) && is_numeric($recording['durationSeconds'])) {
                        $entry['durationSeconds'] = (int) $recording['durationSeconds'];
                    }

                    if (isset($recording['gain']) && is_numeric($recording['gain'])) {
                        $entry['gain'] = (float) $recording['gain'];
                    }

                    if (isset($recording['gapMs']) && is_numeric($recording['gapMs'])) {
                        $entry['gapMs'] = (int) $recording['gapMs'];
                    }

                    if (!empty($recording['storage_path'])) {
                        $entry['storage_path'] = (string) $recording['storage_path'];
                    }

                    return $entry;
                },
                $recordings,
            ),
        ];

        $encoded = json_encode($hashData, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = serialize($hashData);
        }

        return sha1($encoded);
    }

    private function cancelEmbeddedPlaylist(BroadcastSession $session): void
    {
        $sessionOptions = $session->options ?? [];
        $playlistId = Arr::get($sessionOptions, '_live_playlist_id');

        if (is_string($playlistId) && $playlistId !== '') {
            $playlist = BroadcastPlaylist::query()->find($playlistId);
            if ($playlist !== null && !in_array($playlist->status, ['completed', 'failed', 'cancelled'], true)) {
                $this->cancelPlaylist($playlistId);
            }
        }

        $optionsChanged = false;
        if (isset($sessionOptions['_live_playlist_id']) || isset($sessionOptions['_live_playlist_hash'])) {
            unset($sessionOptions['_live_playlist_id'], $sessionOptions['_live_playlist_hash']);
            $optionsChanged = true;
        }

        if (isset($sessionOptions['playlist'])) {
            unset($sessionOptions['playlist']);
            $optionsChanged = true;
        }

        if ($optionsChanged) {
            $session->update(['options' => $sessionOptions]);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, int> $manualRoute
     * @param array<int, int> $locationGroupIds
     * @param array<int, int> $nestIds
     * @param array<string, mixed> $targets
     * @return array<string, mixed>
     */
    private function augmentOptions(
        array $options,
        array $manualRoute,
        array $locationGroupIds,
        array $nestIds,
        array $targets,
    ): array {
        $options['_selection'] = [
            'route' => $manualRoute,
            'locations' => $locationGroupIds,
            'nests' => $nestIds,
        ];

        $options['_resolved'] = [
            'route' => $manualRoute,
            'zones' => $targets['zones'],
            'locationAddresses' => $targets['locationAddresses'],
            'nestAddresses' => $targets['nestAddresses'],
            'groupAddresses' => $targets['groupAddresses'],
        ];

        $options['_labels'] = [
            'locations' => $targets['groupSummaries'],
            'nests' => $targets['nestSummaries'],
        ];

        $missing = array_filter([
            'location_groups' => $targets['missingGroups'],
            'locations' => $targets['missingLocationIds'],
            'nests' => $targets['missingNests'],
            'zones_overflow' => $targets['zoneOverflow'],
            'location_groups_missing_address' => $targets['groupsWithoutAddress'],
        ], static fn (array $entries) => $entries !== []);

        if ($missing !== []) {
            $options['_missing'] = $missing;
        }

        return $options;
    }

    /**
     * Apply FM frequency option if requested.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function applyFrequencyOption(array $options): array
    {
        $frequencyHz = $this->extractFrequencyHz($options);
        if ($frequencyHz === null) {
            return $options;
        }

        $alreadyApplied = Arr::get($options, '_frequency.applied_hz');
        if (is_numeric($alreadyApplied) && abs((float) $alreadyApplied - $frequencyHz) < 0.5) {
            return $options;
        }

        try {
            $modbusUnitId = Arr::get($options, 'modbusUnitId');
            $result = $this->fmRadio->setFrequency($frequencyHz, $modbusUnitId !== null ? (int) $modbusUnitId : null);
            $appliedHz = (float) ($result['frequency'] ?? $frequencyHz);

            $options['_frequency'] = [
                'requested_hz' => $frequencyHz,
                'requested_mhz' => $frequencyHz / 1_000_000,
                'applied_hz' => $appliedHz,
                'applied_mhz' => $appliedHz / 1_000_000,
            ];

            if (isset($result['python'])) {
                $options['_frequency']['python'] = $result['python'];
            }
        } catch (\Throwable $exception) {
            Log::error('Unable to set FM frequency for broadcast session.', [
                'exception' => $exception->getMessage(),
                'frequency_hz' => $frequencyHz,
            ]);
            $options['_frequency_error'] = $exception->getMessage();
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractFrequencyHz(array $options): ?float
    {
        $candidates = [
            ['value' => $options['frequency_hz'] ?? null, 'unit' => 'hz'],
            ['value' => $options['frequencyHz'] ?? null, 'unit' => 'hz'],
            ['value' => $options['frequency_mhz'] ?? null, 'unit' => 'mhz'],
            ['value' => $options['frequencyMhz'] ?? null, 'unit' => 'mhz'],
            ['value' => $options['frequency'] ?? null, 'unit' => 'auto'],
        ];

        foreach ($candidates as $candidate) {
            $hz = $this->parseFrequencyValue($candidate['value'], $candidate['unit']);
            if ($hz !== null) {
                return $hz;
            }
        }

        return null;
    }

    private function parseFrequencyValue(mixed $value, string $unit = 'auto'): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $unitHint = strtolower($unit);
        $numeric = null;

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
            $numeric = (float) $sanitized;
        } elseif (is_numeric($value)) {
            $numeric = (float) $value;
        }

        if ($numeric === null || !is_finite($numeric) || $numeric <= 0) {
            return null;
        }

        if ($unitHint === 'mhz' || ($unitHint === 'auto' && $numeric < 2000)) {
            return $numeric * 1_000_000;
        }

        return $numeric;
    }

    /**
     * Determine hop route to apply before starting broadcast.
     *
     * @param array<int, int> $manualRoute
     * @param array<string, mixed> $targets
     * @return array<int, int>
     */
    private function resolveRoute(array $manualRoute, array $targets): array
    {
        if ($manualRoute !== []) {
            return array_values(array_unique($manualRoute));
        }

        $defaultRoute = config('broadcast.default_route', []);
        if (is_array($defaultRoute) && $defaultRoute !== []) {
            return array_values(array_unique(array_map(static fn ($value) => (int) $value, $defaultRoute)));
        }

        return [];
    }

    private function applySourceVolume(string $source): void
    {
        $channelMap = config('volume.source_channels', []);
        $channel = $channelMap[$source] ?? null;
        if ($channel === null) {
            return;
        }

        try {
            /** @var VolumeManager $volumeManager */
            $volumeManager = app(VolumeManager::class);
            $config = config('volume', []);
            $groupId = null;
            foreach ($config as $candidateId => $definition) {
                if (!is_array($definition) || !isset($definition['items']) || !is_array($definition['items'])) {
                    continue;
                }
                if (array_key_exists((string) $channel, $definition['items'])) {
                    $groupId = (string) $candidateId;
                    break;
                }
            }

            if ($groupId === null) {
                return;
            }

            $value = $volumeManager->getCurrentLevel($groupId, (string) $channel);
            $volumeManager->applyRuntimeLevel($groupId, (string) $channel, $value);
        } catch (Throwable $exception) {
            Log::warning('Unable to apply runtime input volume.', [
                'source' => $source,
                'channel' => $channel,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function buildFilename(?string $base, ?string $extension): ?string
    {
        if ($base === null || trim($base) === '') {
            return null;
        }

        $base = trim($base);
        if ($extension === null || $extension === '') {
            return $base;
        }

        $normalizedExtension = '.' . ltrim(strtolower($extension), '.');
        $hasExtension = str_ends_with(strtolower($base), $normalizedExtension);

        return $hasExtension ? $base : $base . $normalizedExtension;
    }

    private function normalizeExtension(mixed $extension, mixed $mime): ?string
    {
        if (is_string($extension) && $extension !== '') {
            return ltrim(strtolower($extension), '.');
        }

        if (!is_string($mime) || $mime === '') {
            return null;
        }

        $mime = strtolower($mime);

        return match ($mime) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg', 'audio/vorbis' => 'ogg',
            default => null,
        };
    }

    private function ensureFilePath(mixed $value, ?string $filename, ?string $extension): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = preg_replace('#[/\\\\]+#', '/', trim($value));
        if ($normalized === '') {
            return null;
        }

        $file = $this->buildFilename($filename, $extension);
        if ($file === null) {
            return $normalized;
        }

        $withoutTrailingSlash = rtrim($normalized, '/');
        if ($withoutTrailingSlash === $file || str_ends_with($withoutTrailingSlash, '/' . $file)) {
            return $withoutTrailingSlash;
        }

        $leaf = substr($withoutTrailingSlash, strrpos($withoutTrailingSlash, '/') !== false ? strrpos($withoutTrailingSlash, '/') + 1 : 0);
        if (str_contains($leaf, '.')) {
            return $withoutTrailingSlash;
        }

        return $withoutTrailingSlash . '/' . $file;
    }
}
