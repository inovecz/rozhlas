<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('CONTROL_TAB_ENABLED', true),
    'serial' => [
        'port' => env('CONTROL_TAB_SERIAL_PORT', '/dev/ttyUSB3'),
        'baudrate' => (int) env('CONTROL_TAB_SERIAL_BAUDRATE', 115200),
        'bytesize' => (int) env('CONTROL_TAB_SERIAL_BYTESIZE', 8),
        'parity' => env('CONTROL_TAB_SERIAL_PARITY', 'N'),
        'stopbits' => (int) env('CONTROL_TAB_SERIAL_STOPBITS', 1),
        'timeout' => (float) env('CONTROL_TAB_SERIAL_TIMEOUT', 0.2),
        'write_timeout' => (float) env('CONTROL_TAB_SERIAL_WRITE_TIMEOUT', 1.0),
    ],
    'webhook' => env('CONTROL_TAB_WEBHOOK', 'http://127.0.0.1/api/control-tab/events'),
    'token' => env('CONTROL_TAB_TOKEN'),
    'poll_interval' => (float) env('CONTROL_TAB_POLL_INTERVAL', 0.05),
    'graceful_timeout' => (float) env('CONTROL_TAB_GRACEFUL_TIMEOUT', 5.0),
    'retry_backoff_ms' => (int) env('CONTROL_TAB_RETRY_BACKOFF_MS', 250),
    'text_fields' => [
        1 => 'status_summary',
    ],
    'buttons' => [
        // Example mapping; override in environment-specific config.
        // 1 => ['action' => 'start_stream', 'source' => 'microphone'],
        // 2 => ['action' => 'stop_stream'],
        // 13 => ['action' => 'trigger_jsvv_alarm', 'button' => 2],
    ],
    'defaults' => [
        'route' => [],
        'locations' => [],
        'nests' => [],
        'options' => [],
    ],
];
