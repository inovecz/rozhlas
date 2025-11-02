<?php

declare(strict_types=1);

namespace App\Services\RF\Driver;

use Illuminate\Support\Facades\Log;

class NullDriver implements Rs485DriverInterface
{
    public function __construct(private readonly array $context = [])
    {
    }

    public function enterTransmit(): void
    {
        Log::debug('RS-485 null driver: enter transmit requested.', $this->context);
    }

    public function enterReceive(): void
    {
        Log::debug('RS-485 null driver: enter receive requested.', $this->context);
    }

    public function shutdown(): void
    {
        Log::debug('RS-485 null driver: shutdown.', $this->context);
    }
}
