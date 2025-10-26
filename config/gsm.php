<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('GSM_ENABLED', true),
    'webhook' => env('GSM_WEBHOOK', 'http://127.0.0.1/api/gsm/events'),
    'token' => env('GSM_TOKEN'),
    'serial' => [
        'port' => env('GSM_SERIAL_PORT', '/dev/ttyUSB2'),
        'baudrate' => (int) env('GSM_SERIAL_BAUDRATE', 115200),
        'bytesize' => (int) env('GSM_SERIAL_BYTESIZE', 8),
        'parity' => env('GSM_SERIAL_PARITY', 'N'),
        'stopbits' => (int) env('GSM_SERIAL_STOPBITS', 1),
        'timeout' => (float) env('GSM_SERIAL_TIMEOUT', 0.5),
        'write_timeout' => (float) env('GSM_SERIAL_WRITE_TIMEOUT', 1.0),
    ],
    'poll' => [
        'interval' => (float) env('GSM_POLL_INTERVAL', 0.2),
        'graceful_shutdown' => (float) env('GSM_GRACEFUL_TIMEOUT', 5.0),
        'signal_interval' => (float) env('GSM_SIGNAL_INTERVAL', 30.0),
    ],
    'call' => [
        'auto_answer_delay_ms' => (int) env('GSM_AUTO_ANSWER_DELAY_MS', 1000),
        'max_ring_attempts' => (int) env('GSM_MAX_RING_ATTEMPTS', 6),
    ],
];
