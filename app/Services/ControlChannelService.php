<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlChannelCommand;
use App\Models\JsvvMessage;
use Illuminate\Support\Facades\Log;

class ControlChannelService
{
    public const STATE_IDLE = 'IDLE';
    public const STATE_TRANSMITTING = 'TRANSMITTING';
    public const STATE_PAUSED = 'PAUSED';
    public const STATE_STOPPED = 'STOPPED';

    public function pause(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->record('pause_modbus', self::STATE_TRANSMITTING, self::STATE_PAUSED, $message, $reason);
    }

    public function resume(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->record('resume_modbus', self::STATE_PAUSED, self::STATE_TRANSMITTING, $message, $reason);
    }

    public function stop(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->record('stop_modbus', null, self::STATE_STOPPED, $message, $reason);
    }

    public function status(?JsvvMessage $message = null, string $reason = ''): ControlChannelCommand
    {
        return $this->record('status_modbus', null, null, $message, $reason);
    }

    protected function record(
        string $command,
        ?string $stateBefore,
        ?string $stateAfter,
        ?JsvvMessage $message,
        string $reason
    ): ControlChannelCommand {
        $payload = [
            'note' => 'Control channel integration pending implementation',
        ];

        $record = ControlChannelCommand::create([
            'command' => $command,
            'state_before' => $stateBefore,
            'state_after' => $stateAfter,
            'reason' => $reason,
            'message_id' => $message?->id,
            'result' => 'NOT_IMPLEMENTED',
            'payload' => $payload,
            'issued_at' => now(),
        ]);

        Log::info('Control channel command recorded', [
            'command' => $command,
            'message_id' => $message?->id,
            'reason' => $reason,
        ]);

        return $record;
    }
}
