<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JsvvListenerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JsvvEventController extends Controller
{
    public function __construct(private readonly JsvvListenerService $listener = new JsvvListenerService())
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $this->listener->handleFrame($payload);

        return response()->json(['status' => 'accepted']);
    }
}
