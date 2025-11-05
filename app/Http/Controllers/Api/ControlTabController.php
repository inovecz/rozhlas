<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ControlTabEvent;
use App\Http\Controllers\Controller;
use App\Models\StreamTelemetryEntry;
use App\Services\ControlTabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ControlTabController extends Controller
{
    public function __construct(private readonly ControlTabService $service)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        try {
            $event = ControlTabEvent::fromPayload($payload);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'validation_error',
                'handled_as' => 'none',
                'message' => $exception->getMessage(),
            ], 422);
        }

        Log::channel('control_tab')->info('Control Tab event received', [
            'device_id' => $event->deviceId,
            'type' => $event->type,
            'control_id' => $event->controlId,
            'screen_id' => $event->screenId,
            'panel_id' => $event->panelId,
            'payload' => $payload,
        ]);

        $result = $this->dispatchEvent($event);
        $status = (string) ($result['status'] ?? 'ok');
        $handledAs = (string) ($result['handled_as'] ?? $event->type);
        $httpStatus = $this->httpStatusForResult($status);

        $response = [
            'status' => $status,
            'handled_as' => $handledAs,
            'actions' => $result['actions'] ?? [],
            'device_id' => $event->deviceId,
            'timestamp' => $event->timestamp?->toIso8601String(),
            'action' => 'ack',
            'ack' => [
                'screen' => $event->screenId ?? 0,
                'panel' => $event->panelId ?? 0,
                'eventType' => $this->mapEventType($event->type),
                'status' => $this->ackStatus($status),
            ],
        ];

        if (isset($result['message'])) {
            $response['ack']['message'] = (string) $result['message'];
        }

        if (isset($result['control_tab']) && is_array($result['control_tab'])) {
            $response['control'] = $result['control_tab'];
        }

        if ($handledAs === 'text_field_request' && ($result['status'] ?? '') === 'ok') {
            $fieldId = $result['field_id'] ?? null;
            $text = $result['text'] ?? null;
            if ($fieldId !== null) {
                $response['action'] = 'text';
                $response['text'] = [
                    'fieldId' => (int) $fieldId,
                    'text' => (string) ($text ?? ''),
                ];
            }
        }

        $this->recordTelemetry($event, $result + ['response' => $response]);

        Log::channel('control_tab')->info('Control Tab response prepared', [
            'status' => $status,
            'response' => $response,
        ]);

        return response()->json($response, $httpStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchEvent(ControlTabEvent $event): array
    {
        return match (strtolower($event->type)) {
            'panel_loaded' => $this->service->handlePanelLoaded($event->screenId ?? 0, $event->panelId ?? 0) + [
                'handled_as' => 'panel_loaded',
                'actions' => ['PANEL_LOADED'],
            ],
            'text_field_request', 'textfield' => $this->service->handleTextRequest(
                $this->normalizeControlId($event->controlId)
            ) + [
                'handled_as' => 'text_field_request',
                'actions' => ['TEXT_FIELD_REQUEST'],
            ],
            'button', 'button_pressed' => $this->handleButton($event),
            default => [
                'status' => 'unsupported',
                'handled_as' => $event->type,
                'actions' => [],
                'message' => sprintf('Event type "%s" is not supported.', $event->type),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function handleButton(ControlTabEvent $event): array
    {
        $buttonId = $this->normalizeControlId($event->controlId);
        if ($buttonId === null) {
            return [
                'status' => 'validation_error',
                'handled_as' => 'button',
                'actions' => ['BUTTON'],
                'message' => 'Control Tab button id is missing.',
            ];
        }

        $result = $this->service->handleButtonPress($buttonId, $event->originalPayload + [
            'device_id' => $event->deviceId,
            'screen' => $event->screenId,
            'panel' => $event->panelId,
        ]);

        return $result + [
            'handled_as' => 'button',
            'actions' => ['BUTTON'],
        ];
    }

    private function recordTelemetry(ControlTabEvent $event, array $result): void
    {
        $payload = [
            'event' => $event->originalPayload,
            'resolved' => [
                'type' => $event->type,
                'device_id' => $event->deviceId,
                'control_id' => $event->controlId,
                'screen_id' => $event->screenId,
                'panel_id' => $event->panelId,
            ],
            'result' => $result,
        ];

        StreamTelemetryEntry::create([
            'type' => 'control_tab_event',
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }

    private function httpStatusForResult(string $status): int
    {
        return match ($status) {
            'validation_error' => 422,
            'error', 'failed' => 500,
            default => 200,
        };
    }

    private function ackStatus(string $status): int
    {
        $failures = ['error', 'invalid_request', 'unsupported', 'validation_error', 'jsvv_active', 'failed'];
        return in_array($status, $failures, true) ? 0 : 1;
    }

    private function mapEventType(string $type): int
    {
        return match (strtolower($type)) {
            'panel_loaded' => 1,
            'text_field_request', 'textfield' => 3,
            default => 2,
        };
    }

    private function normalizeControlId(int|string|null $controlId): ?int
    {
        if (is_int($controlId)) {
            return $controlId;
        }

        if (is_string($controlId) && is_numeric($controlId)) {
            return (int) $controlId;
        }

        return null;
    }
}
