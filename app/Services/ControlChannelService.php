<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlChannelCommand;
use App\Models\JsvvMessage;
use App\Models\JsvvEvent;
use App\Exceptions\ControlChannelTimeoutException;
use App\Exceptions\ControlChannelTransportException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\CarbonInterface;

class ControlChannelService
{
    public const STATE_IDLE = 'IDLE';
    public const STATE_TRANSMITTING = 'TRANSMITTING';
    public const STATE_PAUSED = 'PAUSED';
    public const STATE_STOPPED = 'STOPPED';

    public function __construct(private readonly ControlChannelTransport $transport)
    {
    }

    public function pause(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->dispatch(
            'pause_modbus',
            $message,
            $reason,
            expectedStateAfter: self::STATE_PAUSED,
            fallbackStateBefore: self::STATE_TRANSMITTING
        );
    }

    public function resume(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->dispatch(
            'resume_modbus',
            $message,
            $reason,
            expectedStateAfter: self::STATE_TRANSMITTING,
            fallbackStateBefore: self::STATE_PAUSED
        );
    }

    public function stop(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->dispatch(
            'stop_modbus',
            $message,
            $reason,
            expectedStateAfter: self::STATE_STOPPED
        );
    }

    public function status(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->dispatch(
            'status_modbus',
            $message,
            $reason
        );
    }

    private function dispatch(
        string $command,
        ?JsvvMessage $message,
        string $reason,
        ?string $expectedStateAfter = null,
        ?string $fallbackStateBefore = null
    ): ControlChannelCommand {
        $request = [
            'id' => (string) Str::uuid(),
            'command' => $command,
            'reason' => $reason,
            'sourceMessageId' => $message?->id,
            'deadlineMs' => max(1, (int) config('control_channel.deadline_ms', 500)),
        ];
        $stateBefore = $this->resolveLastState($fallbackStateBefore);

        if (!$this->isEnabled()) {
            $record = ControlChannelCommand::create([
                'command' => $command,
                'state_before' => $stateBefore,
                'state_after' => $expectedStateAfter,
                'reason' => $reason,
                'message_id' => $message?->id,
                'result' => 'SKIPPED',
                'payload' => [
                    'request' => $request,
                    'details' => [
                        'skipped' => true,
                        'reason' => 'modbus_port_missing',
                    ],
                ],
                'issued_at' => now(),
            ]);

            $this->recordEvent($message, $command, 'ControlChannelSkipped', [
                'state_after' => $expectedStateAfter,
                'since_message_ms' => $this->calculateSinceMessageMs($message),
            ]);

            Log::info('Control channel command skipped because Modbus port is not configured', [
                'command' => $command,
                'message_id' => $message?->id,
                'reason' => $reason,
            ]);

            return $record;
        }

        $record = ControlChannelCommand::create([
            'command' => $command,
            'state_before' => $stateBefore,
            'state_after' => null,
            'reason' => $reason,
            'message_id' => $message?->id,
            'result' => 'PENDING',
            'payload' => [
                'request' => $request,
            ],
            'issued_at' => now(),
        ]);

        try {
            $response = $this->transport->send($request);
            $stateAfter = $response['state'] ?? $expectedStateAfter;
            $ok = ($response['ok'] ?? false) === true;
            $latencyMs = Arr::get($response, 'latencyMs');
            $sinceMessageMs = $this->calculateSinceMessageMs($message);

            $record->update([
                'state_after' => $stateAfter,
                'result' => $ok ? 'OK' : 'FAILED',
                'payload' => $this->mergePayload($record, [
                    'response' => $response,
                    'latency_ms' => $latencyMs,
                    'since_message_ms' => $sinceMessageMs,
                ]),
            ]);

            $this->recordEvent($message, $command, $ok ? 'ControlChannelAcknowledged' : 'ControlChannelFailed', [
                'state_after' => $stateAfter,
                'response' => $response,
                'latency_ms' => $latencyMs,
                'since_message_ms' => $sinceMessageMs,
            ]);

            Log::info('Control channel command acknowledged', [
                'command' => $command,
                'message_id' => $message?->id,
                'reason' => $reason,
                'state_after' => $stateAfter,
                'ok' => $ok,
                'latency_ms' => $latencyMs,
                'since_message_ms' => $sinceMessageMs,
            ]);
        } catch (ControlChannelTimeoutException $exception) {
            $sinceMessageMs = $this->calculateSinceMessageMs($message);

            $record->update([
                'result' => 'TIMEOUT',
                'state_after' => $expectedStateAfter,
                'payload' => $this->mergePayload($record, [
                    'error' => $exception->getMessage(),
                    'since_message_ms' => $sinceMessageMs,
                ]),
            ]);

            $this->recordEvent($message, $command, 'ControlChannelTimeout', [
                'error' => $exception->getMessage(),
                'since_message_ms' => $sinceMessageMs,
            ]);

            Log::error('Control channel command timed out', [
                'command' => $command,
                'message_id' => $message?->id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
                'since_message_ms' => $sinceMessageMs,
            ]);
        } catch (ControlChannelTransportException $exception) {
            $sinceMessageMs = $this->calculateSinceMessageMs($message);

            $record->update([
                'result' => 'FAILED',
                'state_after' => null,
                'payload' => $this->mergePayload($record, [
                    'error' => $exception->getMessage(),
                    'since_message_ms' => $sinceMessageMs,
                ]),
            ]);

            $this->recordEvent($message, $command, 'ControlChannelTransportError', [
                'error' => $exception->getMessage(),
                'since_message_ms' => $sinceMessageMs,
            ]);

            Log::error('Control channel command transport failure', [
                'command' => $command,
                'message_id' => $message?->id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
                'since_message_ms' => $sinceMessageMs,
            ]);
        }

        return $record->fresh();
    }

    private function isEnabled(): bool
    {
        $port = config('modbus.port');
        if ($port === null) {
            return false;
        }

        if (is_string($port)) {
            return trim($port) !== '';
        }

        return true;
    }

    private function resolveLastState(?string $fallback): ?string
    {
        $latest = ControlChannelCommand::query()
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->value('state_after');

        return $latest ?? $fallback;
    }

    private function mergePayload(ControlChannelCommand $record, array $payload): array
    {
        $current = $record->payload ?? [];

        return array_merge($current, $payload);
    }

    private function recordEvent(?JsvvMessage $message, string $command, string $event, array $data): void
    {
        if ($message === null) {
            JsvvEvent::create([
                'event' => $event,
                'data' => array_merge($data, [
                    'command' => $command,
                ]),
            ]);
            return;
        }

        JsvvEvent::create([
            'message_id' => $message->id,
            'event' => $event,
            'data' => array_merge($data, [
                'command' => $command,
                'priority' => $message->priority,
            ]),
        ]);
    }

    private function calculateSinceMessageMs(?JsvvMessage $message): ?int
    {
        $receivedAt = $message?->received_at;
        if (!$receivedAt instanceof CarbonInterface) {
            return null;
        }

        return (int) round($receivedAt->diffInRealMilliseconds(now()));
    }
}
