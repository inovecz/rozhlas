<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\LiveBroadcastService;

class LiveBroadcastController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $liveBroadcastService = new LiveBroadcastService();
        $output = $liveBroadcastService->startBroadcast();
        return $output === false ? $this->error('live_broadcast.start_failed') : $this->success(['message' => 'live_broadcast.started', 'output' => $output]);
    }

    public function stop(Request $request): JsonResponse
    {
        $liveBroadcastService = new LiveBroadcastService();
        $output = $liveBroadcastService->stopBroadcast();
        return $output === false ? $this->error('live_broadcast.stop_failed') : $this->success(['message' => 'live_broadcast.stopped', 'output' => $output]);
    }
}
