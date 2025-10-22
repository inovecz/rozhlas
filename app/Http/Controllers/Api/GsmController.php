<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GsmStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GsmController extends Controller
{
    public function __construct(private readonly GsmStreamService $service = new GsmStreamService())
    {
    }

    public function events(Request $request): JsonResponse
    {
        $payload = $request->all();
        $this->service->handleIncomingCall($payload);

        return response()->json(['status' => 'accepted']);
    }

    public function index(): JsonResponse
    {
        return response()->json(['whitelist' => $this->service->listWhitelist()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'number' => ['required', 'string'],
            'label' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $entry = $this->service->upsertWhitelist(null, $validator->validated());
        return response()->json(['entry' => $entry], 201);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'number' => ['sometimes', 'string'],
            'label' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $entry = $this->service->upsertWhitelist($id, $validator->validated());
        return response()->json(['entry' => $entry]);
    }

    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->service->deleteWhitelist($id);
        return response()->json(['deleted' => $deleted]);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sessionId' => ['required', 'string'],
            'pin' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = $this->service->verifyPin($validator->validated()['sessionId'], $validator->validated()['pin']);
        return response()->json(['result' => $result]);
    }
}
