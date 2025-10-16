<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StreamOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualControlController extends Controller
{
    public function __construct(private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator())
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $this->orchestrator->recordTelemetry([
            'type' => 'manual_control_event',
            'payload' => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(['status' => 'accepted']);
    }
}
