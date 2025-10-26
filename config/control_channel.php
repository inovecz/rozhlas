<?php

declare(strict_types=1);

return [
    'endpoint' => env('CONTROL_CHANNEL_ENDPOINT', 'unix:///var/run/jsvv-control.sock'),
    'timeout_ms' => (int) env('CONTROL_CHANNEL_TIMEOUT_MS', 500),
    'retry_attempts' => (int) env('CONTROL_CHANNEL_RETRY', 3),
    'deadline_ms' => (int) env('CONTROL_CHANNEL_DEADLINE_MS', 500),
    'handshake_timeout_ms' => (int) env('CONTROL_CHANNEL_HANDSHAKE_TIMEOUT_MS', 150),
];
