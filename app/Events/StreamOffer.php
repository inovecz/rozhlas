<?php

namespace App\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class StreamOffer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public $data)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('stream-signal-channel.'.$this->data['receiver']['id']),
        ];
    }
}
