<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Jobs\ProcessRecordingPlaylist;
use App\Libraries\PythonClient;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\BroadcastSession;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\Schedule;
use App\Models\StreamTelemetryEntry;
use App\Services\Mixer\AudioRoutingService;
use App\Services\Mixer\MixerController;
use App\Services\VolumeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class StreamOrchestrator extends Service
{
    private const MAX_DEST_ZONES = 5;
    private const JSVV_ACTIVE_LOCK_KEY = 'jsvv:sequence:active';

    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly MixerController $mixer = new MixerController(),
        private readonly AudioRoutingService $audioRouting = new AudioRoutingService(),
    ) {
        parent::__construct();
    }

    public function start(array $payload): array
    {
        $manualRoute = $this->normalizeNumericArray(Arr::get($payload, 'route', []));
        $locationGroupIds = $this->normalizeNumericArray(
            Arr::get($payload, 'locations', Arr::get($payload, 'zones', [])),
        );
        $nestIds = $this->normalizeNumericArray(Arr::get($payload, 'nests', []));
        $options = Arr::get($payload, 'options', []);
        $source = (string) Arr::get($payload, 'source', 'unknown');

        $targets = $this->resolveTargets($locationGroupIds, $nestIds);
        $route = $this->resolveRoute($manualRoute, $targets);
        $zones = $targets['zones'];
        $augmentedOptions = $this->augmentOptions($options, $manualRoute, $locationGroupIds, $nestIds, $targets);
        if ($zones === []) {
            throw new InvalidArgumentException('Destination zones must be defined before starting broadcast.');
        }
        if ($source !== 'jsvv' && Cache::has(self::JSVV_ACTIVE_LOCK_KEY)) {
            throw new BroadcastLockedException('JSVV sequence is currently running');
        }

        $active = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($active !== null) {
            $this->mixer->activatePreset($source, [
                'options' => $augmentedOptions,
                'route' => $route,
                'zones' => $zones,
                'session_id' => $active->id,
            ]);

            $this->applyAudioRouting($augmentedOptions, $source, $active->id);
            $this->applySourceVolume($source);

            $response = $this->client->startStream(
                $route !== [] ? $route : null,
                $zones,
            );

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

            return $active->fresh()->toArray();
        }

        $this->mixer->activatePreset($source, [
            'options' => $augmentedOptions,
            'route' => $route,
            'zones' => $zones,
        ]);

        $this->applyAudioRouting($augmentedOptions, $source, null);
        $this->applySourceVolume($source);

        $response = $this->client->startStream(
            $route !== [] ? $route : null,
            $zones,
        );

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

        return $session->fresh()->toArray();
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

        $response = $this->client->stopStream();

        $session->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => $reason,
            'python_response' => $response,
        ]);

        $this->mixer->reset([
            'session_id' => $session->id,
            'reason' => $reason,
        ]);

        $this->recordTelemetry([
            'type' => 'stream_stopped',
            'session_id' => $session->id,
            'payload' => [
                'reason' => $reason,
            ],
        ]);

        return $session->fresh()->toArray();
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
        $hasSystemPlaybackTap = $hasPulseMonitor || collect($devices['pulse']['sinks'] ?? [])->isNotEmpty();

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
            ['id' => 'fm_radio', 'label' => 'FM Rádio', 'available' => true],
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
            $locationGroupIds = $this->normalizeNumericArray(
                Arr::get($payload, 'locations', Arr::get($payload, 'zones', [])),
            );
            $nestIds = $this->normalizeNumericArray(Arr::get($payload, 'nests', []));
            $options = Arr::get($payload, 'options', []);

            $targets = $this->resolveTargets($locationGroupIds, $nestIds);

            $playlist = BroadcastPlaylist::create([
                'route' => $manualRoute,
                'zones' => $targets['zones'],
                'options' => $this->augmentOptions($options, $manualRoute, $locationGroupIds, $nestIds, $targets),
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
    private function applyAudioRouting(array $options, string $source, ?int $sessionId = null): void
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
}
