<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StreamTelemetryEntry;
use App\Services\ControlTabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ControlTabController extends Controller
{
    public function __construct(private readonly ControlTabService $service)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $camelToSnake = [
            'buttonId' => 'button_id',
            'fieldId' => 'field_id',
        ];
        foreach ($camelToSnake as $camel => $snake) {
            if (!array_key_exists($snake, $payload) && array_key_exists($camel, $payload)) {
                $request->merge([$snake => $payload[$camel]]);
            }
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:panel_loaded,button_pressed,text_field_request'],
            'screen' => ['sometimes', 'integer'],
            'panel' => ['sometimes', 'integer'],
            'button_id' => ['required_if:type,button_pressed', 'integer'],
            'field_id' => ['required_if:type,text_field_request', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $type = $data['type'];
        $screen = (int) ($data['screen'] ?? 0);
        $panel = (int) ($data['panel'] ?? 0);
        $sessionId = $request->string('sessionId')->toString() ?: null;

        $result = match ($type) {
            'panel_loaded' => $this->service->handlePanelLoaded($screen, $panel),
            'button_pressed' => $this->service->handleButtonPress((int) $data['button_id'], $data),
            'text_field_request' => $this->service->handleTextRequest((int) $data['field_id']),
            default => ['status' => 'unsupported'],
        };

        $status = (string) ($result['status'] ?? 'ok');
        $action = 'ack';
        $responsePayload = [
            'status' => $status,
            'sessionId' => $sessionId,
            'action' => 'ack',
            'ack' => [
                'screen' => $screen,
                'panel' => $panel,
                'eventType' => $this->mapEventType($type),
                'status' => $this->ackStatus($status),
            ],
        ];

        if ($type === 'text_field_request' && ($result['status'] ?? null) === 'ok') {
            $responsePayload['action'] = $action = 'text';
            $responsePayload['text'] = [
                'fieldId' => (int) ($result['field_id'] ?? $data['field_id'] ?? 0),
                'text' => (string) ($result['text'] ?? ''),
            ];
        }

        if (isset($result['message'])) {
            $responsePayload['ack']['message'] = $result['message'];
        }

        if (isset($result['control_tab']) && is_array($result['control_tab'])) {
            $responsePayload['control'] = $result['control_tab'];
        }

        $this->recordTelemetry($type, $data, $result, $responsePayload);

        return response()->json($responsePayload, $status === 'unsupported' ? 400 : 200);
    }

    private function ackStatus(string $status): int
    {
        $failureStatuses = ['error', 'invalid_request', 'unsupported', 'validation_error', 'jsvv_active'];
        return in_array($status, $failureStatuses, true) ? 0 : 1;
    }

    private function mapEventType(string $type): int
    {
        return match ($type) {
            'panel_loaded' => 1,
            'button_pressed' => 2,
            'text_field_request' => 3,
            default => 0,
        };
    }

    private function recordTelemetry(string $type, array $requestData, array $serviceResult, array $response): void
    {
        $payload = [
            'request' => $requestData,
            'result' => $serviceResult,
            'response' => $response,
        ];

        StreamTelemetryEntry::create([
            'type' => 'control_tab_' . $type,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}
