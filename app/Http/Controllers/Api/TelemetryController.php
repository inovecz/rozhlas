<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StreamOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelemetryController extends Controller
{
    public function __construct(private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator())
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $since = $request->query('since');
        $entries = $this->orchestrator->telemetry($since);

        return response()->json(['entries' => $entries]);
    }
}
