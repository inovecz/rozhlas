<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\JsvvMessageReceived;
use App\Services\ControlChannelService;
use Illuminate\Contracts\Queue\ShouldQueue;

class CoordinateControlChannel implements ShouldQueue
{
    public string $queue = 'activations-high';

    public function __construct(private readonly ControlChannelService $controlChannel)
    {
    }

    public function handle(JsvvMessageReceived $event): void
    {
        if ($event->duplicate) {
            return;
        }

        $message = $event->message;
        $priority = strtoupper($message->priority ?? 'P3');

        if ($priority === 'P1') {
            $this->controlChannel->stop($message, sprintf('P1 %s', $message->command));
            return;
        }

        if ($priority === 'P2') {
            $this->controlChannel->pause($message, sprintf('P2 %s', $message->command));
        }
    }
}
