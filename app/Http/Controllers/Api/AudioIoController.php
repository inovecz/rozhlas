<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AudioInputRequest;
use App\Http\Requests\AudioOutputRequest;
use App\Http\Requests\AudioVolumeRequest;
use App\Services\Audio\AudioIoService;
use Illuminate\Http\JsonResponse;

class AudioIoController extends Controller
{
    public function status(AudioIoService $service): JsonResponse
    {
        return response()->json([
            'status' => $service->status(),
        ]);
    }

    public function setInput(AudioInputRequest $request, AudioIoService $service): JsonResponse
    {
        $status = $service->setInput($request->input('identifier'));

        return response()->json([
            'status' => $status,
        ]);
    }

    public function setOutput(AudioOutputRequest $request, AudioIoService $service): JsonResponse
    {
        $status = $service->setOutput($request->input('identifier'));

        return response()->json([
            'status' => $status,
        ]);
    }

    public function setVolume(AudioVolumeRequest $request, AudioIoService $service): JsonResponse
    {
        $scope = $request->input('scope');
        $value = $request->input('value');
        $mute = $request->has('mute') ? $request->boolean('mute') : null;

        $volume = $service->setVolume($scope, $value, $mute);

        return response()->json([
            'scope' => $scope,
            'volume' => $volume,
        ]);
    }
}
