<?php

declare(strict_types=1);

namespace App\Services\ControlTab;

use App\DataTransferObjects\ControlTabEvent;
use App\Services\ControlTabService;
use Illuminate\Support\Arr;

class ControlTabEventProcessor
{
    public function __construct(
        private readonly ControlTabService $controlTabService,
        private readonly ControlTabContextStore $contextStore,
        private readonly ControlTabPushService $pushService,
        private readonly ButtonMappingRepository $buttonMappings,
        private readonly ControlTabDataProvider $dataProvider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ControlTabEvent $event): array
    {
        return match ($event->type) {
            'button', 'button_pressed' => $this->handleButton($event),
            'request' => $this->handleRequest($event),
            'textfield' => $this->handleTextField($event),
            'text_field_request' => $this->handleLegacyTextFieldRequest($event),
            'panel_loaded' => $this->handlePanelLoaded($event),
            default => [
                'status' => 'unsupported',
                'handled_as' => $event->type,
                'message' => sprintf('Unsupported Control Tab event type "%s".', $event->type),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function handleButton(ControlTabEvent $event): array
    {
        $controlId = $this->toIntControlId($event->controlId);
        if ($controlId === null) {
            return [
                'status' => 'validation_error',
                'handled_as' => 'button',
                'message' => 'Control ID is required for button events.',
            ];
        }

        $mapping = $this->buttonMappings->find($controlId);
        $context = $this->contextStore->get($event->deviceId);

        $result = $this->controlTabService->handleButtonPress($controlId, [
            'device_id' => $event->deviceId,
            'screen' => $event->screenId,
            'panel' => $event->panelId,
            'selections' => Arr::get($context, 'selections', []),
        ]);

        $status = (string) ($result['status'] ?? 'ok');
        $actions = ['BUTTON'];

        if (isset($mapping['action'])) {
            $actions[] = strtoupper((string) $mapping['action']);
        }

        $this->updateStateAfterButton($mapping ?? [], $status, $event, $result);

        return [
            'status' => $status,
            'handled_as' => 'button',
            'actions' => array_values(array_unique($actions)),
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRequest(ControlTabEvent $event): array
    {
        $controlId = is_string($event->controlId) ? strtolower($event->controlId) : null;
        if ($controlId === null) {
            return [
                'status' => 'validation_error',
                'handled_as' => 'request',
                'message' => 'Request control_id must be provided.',
            ];
        }

        $actions = ['REQUEST'];
        $status = 'ok';

        switch ($controlId) {
            case 'localities':
                $text = $this->dataProvider->listLocalities();
                $this->pushService->setField(5, $text);
                $actions[] = 'PUSH_FIELDS';
                break;

            case 'jingles':
                $this->pushService->setField(6, $this->dataProvider->listJingles());
                $actions[] = 'PUSH_FIELDS';
                break;

            default:
                $status = 'unsupported';
                break;
        }

        return [
            'status' => $status,
            'handled_as' => 'request',
            'actions' => array_values(array_unique($actions)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleTextField(ControlTabEvent $event): array
    {
        $controlId = $this->toIntControlId($event->controlId);
        if ($controlId === null) {
            return [
                'status' => 'validation_error',
                'handled_as' => 'textfield',
                'message' => 'control_id must be numeric for textfield events.',
            ];
        }

        $data = is_scalar($event->data) ? (string) $event->data : '';
        $selections = $this->contextStore->get($event->deviceId)['selections'] ?? [];

        switch ($controlId) {
            case 5: // localities
                $selections['localities'] = $this->splitLines($data);
                break;

            case 6: // jingle
                $selections['jingle'] = $data !== '' ? $data : null;
                break;

            default:
                return [
                    'status' => 'unsupported',
                    'handled_as' => 'textfield',
                    'message' => sprintf('Unhandled textfield control_id %d.', $controlId),
                ];
        }

        $this->contextStore->update([
            'selections' => $selections,
        ], $event->deviceId);

        return [
            'status' => 'ok',
            'handled_as' => 'textfield',
            'actions' => ['STORE_SELECTION'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleLegacyTextFieldRequest(ControlTabEvent $event): array
    {
        $controlId = $this->toIntControlId($event->controlId);
        if ($controlId === null) {
            return [
                'status' => 'validation_error',
                'handled_as' => 'text_field_request',
                'message' => 'field_id is required.',
            ];
        }

        $result = $this->controlTabService->handleTextRequest($controlId);

        if (($result['status'] ?? '') === 'ok') {
            $this->pushService->setField($controlId, (string) ($result['text'] ?? ''));
        }

        return [
            'status' => (string) ($result['status'] ?? 'ok'),
            'handled_as' => 'text_field_request',
            'actions' => ['LEGACY_COMPAT'],
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePanelLoaded(ControlTabEvent $event): array
    {
        $screen = $event->screenId ?? 0;
        $panel = $event->panelId ?? 0;
        $result = $this->controlTabService->handlePanelLoaded($screen, $panel);

        return [
            'status' => (string) ($result['status'] ?? 'ok'),
            'handled_as' => 'panel_loaded',
            'result' => $result,
        ];
    }

    private function updateStateAfterButton(array $mapping, string $status, ControlTabEvent $event, array $result): void
    {
        if (!in_array($status, ['ok', 'queued', 'running', 'idle'], true)) {
            return;
        }

        $action = $mapping['action'] ?? null;
        if (!is_string($action)) {
            return;
        }

        $action = strtolower($action);
        $now = now();
        $context = $this->contextStore->get($event->deviceId);

        if (in_array($action, ['start_stream', 'start_or_trigger_selected_jsvv_alarm'], true)) {
            $localities = Arr::get($context, 'selections.localities', []);
            $jingle = Arr::get($context, 'selections.jingle');

            $this->contextStore->update([
                'current_live' => [
                    'active' => true,
                    'started_at' => $now->toIso8601String(),
                    'elapsed_seconds' => 0,
                    'localities' => $localities,
                    'jingle' => $jingle,
                ],
            ], $event->deviceId);

            $this->pushLiveStart($localities, $jingle);
        } elseif (in_array($action, ['stop_stream', 'cancel_selection_stop_stream'], true)) {
            $this->contextStore->resetLive($event->deviceId);
            $this->pushLiveStop();
        } elseif (str_starts_with($action, 'trigger_jsvv')) {
            $this->contextStore->update([
                'current_jsvv' => [
                    'active' => true,
                    'started_at' => $now->toIso8601String(),
                    'elapsed_seconds' => 0,
                    'type' => Arr::get($mapping, 'label'),
                ],
            ], $event->deviceId);

            $this->pushJsvvStart(Arr::get($mapping, 'label'));
        } elseif ($action === 'stop_jsvv') {
            $this->contextStore->resetJsvv($event->deviceId);
            $this->pushJsvvStop();
        } elseif ($action === 'select_jsvv_alarm') {
            // selection handled inside ControlTabService
        }
    }

    /**
     * @param array<int, string> $localities
     */
    private function pushLiveStart(array $localities, ?string $jingle): void
    {
        $fields = [
            2 => '00:00',
            3 => '0',
            4 => '',
            5 => implode("\n", $localities),
            6 => $jingle ?? '',
        ];

        $this->pushService->sendFields($fields, [
            'switch_panel' => true,
        ]);
    }

    private function pushLiveStop(): void
    {
        $fields = [
            2 => '00:00',
            3 => '0',
            4 => '',
            5 => '',
            6 => '',
        ];

        $this->pushService->sendFields($fields, [
            'switch_panel' => true,
            'panel_status' => 0,
        ]);
    }

    private function pushJsvvStart(?string $label): void
    {
        $fields = [
            2 => '00:00',
            3 => '0',
            4 => '',
            5 => $label ?? '',
            6 => '',
        ];

        $this->pushService->sendFields($fields, [
            'switch_panel' => true,
        ]);
    }

    private function pushJsvvStop(): void
    {
        $fields = [
            2 => '00:00',
            3 => '0',
            4 => '',
            5 => '',
            6 => '',
        ];

        $this->pushService->sendFields($fields, [
            'switch_panel' => true,
            'panel_status' => 0,
        ]);
    }

    private function toIntControlId(int|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $data): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $data) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));

        return $lines;
    }
}
