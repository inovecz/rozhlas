<?php

declare(strict_types=1);

namespace App\Services\RF\Driver;

class NullDriver implements Rs485DriverInterface
{
    public function __construct(private readonly array $context = [])
    {
    }

    public function enterTransmit(): void
    {
        // Intentionally no-op.
    }

    public function enterReceive(): void
    {
        // Intentionally no-op.
    }

    public function shutdown(): void
    {
        // Intentionally no-op.
    }
}
