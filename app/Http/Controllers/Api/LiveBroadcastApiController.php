<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolumeLevelRequest;
use App\Models\BroadcastSession;
use App\Services\Audio\AlsamixerService;
use App\Services\ModbusControlService;
use App\Services\StreamOrchestrator;
use App\Services\VolumeManager;
use App\Services\Mixer\AudioDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class LiveBroadcastApiController extends Controller
{
    public function __construct(private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator())
    {
    }

    public function start(Request $request, AlsamixerService $alsamixer, ModbusControlService $modbus): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'zones' => ['sometimes', 'array'],
            'zones.*' => ['integer'],
            'volume' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $volume = Arr::get($payload, 'volume');
        $clampedVolume = $this->normalizeVolume($volume);

        if (!$alsamixer->selectInput((string) $payload['source'], $clampedVolume)) {
            return response()->json([
                'status' => 'mixer_error',
                'message' => 'Nepodařilo se nastavit požadovaný audio vstup.',
            ], 500);
        }

        $route = Arr::get($payload, 'route', []);
        $zones = Arr::get($payload, 'zones', []);

        try {
            $commandResult = $modbus->startStream($zones, $route);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'modbus_error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        $session = $this->storeSession([
            'source' => (string) $payload['source'],
            'route' => $this->normalizeArray((array) $route),
            'zones' => $this->normalizeArray((array) $zones),
            'options' => [
                'volume' => $clampedVolume,
            ],
            'python_response' => $commandResult,
        ]);

        return response()->json([
            'status' => 'ok',
            'modbus' => $commandResult,
            'session' => $session,
        ]);
    }

    public function stop(Request $request, ModbusControlService $modbus): JsonResponse
    {
        try {
            $result = $modbus->stopStream();
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'modbus_error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        $session = $this->closeSession();

        return response()->json([
            'status' => 'ok',
            'modbus' => $result,
            'session' => $session,
        ]);
    }

    public function runtimeInput(Request $request, AlsamixerService $alsamixer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string'],
            'volume' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $volume = Arr::get($payload, 'volume');

        if (!$alsamixer->selectInput($payload['source'], $volume !== null ? (float) $volume : null)) {
            return response()->json([
                'status' => 'mixer_error',
                'message' => 'Nepodařilo se nastavit audio vstup.',
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function status(): JsonResponse
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        return response()->json([
            'session' => $session?->toArray(),
        ]);
    }

    public function playlist(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recordings' => ['required', 'array', 'min:1'],
            'recordings.*.id' => ['required'],
            'recordings.*.durationSeconds' => ['sometimes', 'integer', 'min:1'],
            'recordings.*.gain' => ['sometimes', 'numeric'],
            'recordings.*.gapMs' => ['sometimes', 'integer', 'min:0'],
            'locations' => ['sometimes', 'array'],
            'locations.*' => ['integer'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'zones' => ['sometimes', 'array'],
            'zones.*' => ['integer'],
            'nests' => ['sometimes', 'array'],
            'nests.*' => ['integer'],
            'options' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $locations = Arr::get($payload, 'locations', Arr::get($payload, 'zones', []));
        $route = Arr::get($payload, 'route', []);
        $nests = Arr::get($payload, 'nests', []);

        $playlist = $this->orchestrator->enqueuePlaylist([
            'recordings' => Arr::get($payload, 'recordings', []),
            'route' => $route,
            'locations' => $locations,
            'nests' => $nests,
            'options' => Arr::get($payload, 'options', []),
        ]);

        return response()->json(['playlist' => $playlist]);
    }

    public function cancelPlaylist(string $playlistId): JsonResponse
    {
        $playlist = $this->orchestrator->cancelPlaylist($playlistId);
        return response()->json(['playlist' => $playlist]);
    }

    public function sources(): JsonResponse
    {
        return response()->json(['sources' => $this->orchestrator->listSources()]);
    }

    public function getVolumeLevels(VolumeManager $volumeManager): JsonResponse
    {
        return response()->json([
            'groups' => $volumeManager->listGroups(),
            'sourceChannels' => config('volume.source_channels', []),
            'sourceOutputChannels' => config('volume.source_output_channels', []),
        ]);
    }

    public function updateVolumeLevel(VolumeLevelRequest $request, VolumeManager $volumeManager): JsonResponse
    {
        $item = $volumeManager->updateLevel(
            $request->input('group'),
            $request->input('id'),
            (float) $request->input('value'),
        );

        return response()->json(['item' => $item]);
    }

    public function applyRuntimeVolumeLevel(VolumeLevelRequest $request, VolumeManager $volumeManager): JsonResponse
    {
        $item = $volumeManager->applyRuntimeLevel(
            $request->input('group'),
            $request->input('id'),
            (float) $request->input('value'),
        );

        return response()->json(['item' => $item]);
    }

    public function audioDevices(AudioDeviceService $service): JsonResponse
    {
        $devices = $service->listDevices();

        return response()->json([
            'devices' => $devices,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function storeSession(array $data): array
    {
        $running = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($running !== null) {
            $running->update(array_merge($data, [
                'status' => 'running',
            ]));

            return $running->fresh()->toArray();
        }

        $session = BroadcastSession::create(array_merge($data, [
            'status' => 'running',
            'started_at' => now(),
        ]));

        return $session->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closeSession(): ?array
    {
        $running = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($running === null) {
            return null;
        }

        $running->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => 'manual',
        ]);

        return $running->fresh()->toArray();
    }

    private function normalizeVolume(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;
        if (!is_finite($numeric)) {
            return null;
        }

        return max(0.0, min(100.0, $numeric));
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, int>
     */
    private function normalizeArray(array $values): array
    {
        return array_values(array_map(
            static fn ($value) => (int) $value,
            array_filter($values, static fn ($value) => $value !== null && $value !== '')
        ));
    }
}
