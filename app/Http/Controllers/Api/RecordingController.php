<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use App\Services\Audio\MixerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RecordingController extends Controller
{
    public function start(Request $request, MixerService $mixer): JsonResponse
    {
        $source = $request->input('source');
        if (!is_string($source) || $source === '') {
            $source = null;
        }

        try {
            $recording = $mixer->startCapture($source);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'capture_failed',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => 'recording',
            'recording' => $this->formatRecording($recording),
        ]);
    }

    public function stop(MixerService $mixer): JsonResponse
    {
        try {
            $recording = $mixer->stopCapture();
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'capture_not_running',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => 'stopped',
            'recording' => $this->formatRecording($recording),
        ]);
    }

    private function formatRecording(Recording $recording): array
    {
        return [
            'id' => $recording->getId(),
            'path' => $recording->path,
            'source' => $recording->source,
            'started_at' => $recording->started_at?->toIso8601String(),
            'ended_at' => $recording->ended_at?->toIso8601String(),
            'duration_s' => $recording->duration_s,
        ];
    }
}
