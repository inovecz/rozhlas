<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FmRadioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FmController extends Controller
{
    public function __construct(private readonly FmRadioService $service = new FmRadioService())
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->service->getFrequency());
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'frequency' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $frequencyMHz = (float) $validator->validated()['frequency'];
        $result = $this->service->setFrequency($frequencyMHz * 1_000_000);

        return response()->json($result + [
            'requested_frequency_mhz' => $frequencyMHz,
        ]);
    }
}
