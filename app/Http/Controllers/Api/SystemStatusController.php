<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemStatusService;
use Illuminate\Http\JsonResponse;

class SystemStatusController extends Controller
{
    public function __construct(private readonly SystemStatusService $service = new SystemStatusService())
    {
    }

    public function overview(): JsonResponse
    {
        return response()->json($this->service->overview());
    }
}
