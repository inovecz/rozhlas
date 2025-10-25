<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\BroadcastLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VolumeLevelRequest;
use App\Services\StreamOrchestrator;
use App\Services\VolumeManager;
use App\Services\Mixer\AudioDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class LiveBroadcastApiController extends Controller
{
    public function __construct(private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator())
    {
    }

    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'locations' => ['sometimes', 'array'],
            'locations.*' => ['integer'],
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
        $nests = Arr::get($payload, 'nests', []);
        $payload['locations'] = $locations;
        $payload['zones'] = $locations;
        $payload['nests'] = $nests;
        try {
            $session = $this->orchestrator->start($payload);
        } catch (BroadcastLockedException $exception) {
            return response()->json([
                'status' => 'jsvv_active',
                'message' => 'Nelze spustit vysílání: probíhá poplach JSVV.',
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['session' => $session]);
    }

    public function stop(Request $request): JsonResponse
    {
        $reason = $request->string('reason')->toString() ?: null;
        $session = $this->orchestrator->stop($reason);

        return response()->json(['session' => $session]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->orchestrator->getStatusDetails());
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
}
