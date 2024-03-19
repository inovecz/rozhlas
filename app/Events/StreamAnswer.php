<?php

namespace App\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class StreamAnswer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public $data)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('stream-signal-channel.'.$this->data['broadcaster']),
        ];
    }
}
