<?php

declare(strict_types=1);

namespace App\Services\RF\Driver;

interface Rs485DriverInterface
{
    public function enterTransmit(): void;

    public function enterReceive(): void;

    public function shutdown(): void;
}
