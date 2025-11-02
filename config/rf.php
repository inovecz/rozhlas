<?php

declare(strict_types=1);

$bool = static function (mixed $value, bool $default = true): bool {
    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower((string) $value);
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
};

 $defaultMode = env('RF_RS485_MODE');
 if ($defaultMode === null || $defaultMode === '') {
     if ($bool(env('MODBUS_RS485_GPIO_ENABLE', false), false)) {
         $defaultMode = 'gpio';
     } elseif ($bool(env('MODBUS_RS485_DRIVER_ENABLE', false), false)) {
         $defaultMode = 'rts';
     } else {
         $defaultMode = 'none';
     }
 }

return [
    'unit_id' => (int) env('RF_UNIT_ID', env('MODBUS_UNIT_ID', 1)),
    'lock_key' => env('RF_LOCK_KEY', 'rf:bus'),
    'lock_ttl' => (int) env('RF_LOCK_TTL', 5),
    'lock_wait' => (int) env('RF_LOCK_WAIT', 2),

    'priority' => [
        'state_key' => env('RF_PRIORITY_STATE_KEY', 'rf:bus:priority'),
        'lock_key' => env('RF_PRIORITY_LOCK_KEY', 'rf:bus:priority:lock'),
        'default' => env('RF_PRIORITY_DEFAULT', 'plan'),
        'timeout' => (float) env('RF_PRIORITY_TIMEOUT', 5.0),
        'retry_delay' => (float) env('RF_PRIORITY_RETRY_DELAY', 0.1),
        'stale_after' => (float) env('RF_PRIORITY_STALE_AFTER', 5.0),
        'levels' => [
            'stop' => 0,
            'jsvv' => 10,
            'gsm' => 20,
            'plan' => 30,
            'polling' => 40,
        ],
        'aliases' => [
            'stop' => 'stop',
            'emergency_stop' => 'stop',
            'p0' => 'stop',
            'jsvv_stop' => 'stop',
            'abort' => 'stop',
            'jsvv' => 'jsvv',
            'p1' => 'jsvv',
            'p2' => 'jsvv',
            'p3' => 'jsvv',
            'gsm' => 'gsm',
            'incoming_call' => 'gsm',
            'plan' => 'plan',
            'schedule' => 'plan',
            'poll' => 'polling',
            'polling' => 'polling',
            'alarm' => 'polling',
            'status' => 'polling',
        ],
    ],

    'tx_control' => [
        'start' => (int) env('RF_TX_START_VALUE', 2),
        'stop' => (int) env('RF_TX_STOP_VALUE', 1),
    ],

    'rx_control' => [
        'stop' => (int) env('RF_RX_STOP_VALUE', 1),
        'play_last' => (int) env('RF_RX_PLAY_LAST_VALUE', 3),
    ],

    'rs485' => [
        'mode' => $defaultMode,
        'gpio' => [
            'binary' => env('RS485_GPIO_BINARY', 'gpioset'),
            'chip' => env('RS485_GPIO_CHIP', env('MODBUS_RS485_GPIO_CHIP')),
            'line' => env('RS485_GPIO_LINE', env('MODBUS_RS485_GPIO_LINE')),
            'active_high' => $bool(env('RS485_GPIO_ACTIVE_HIGH', env('MODBUS_RS485_GPIO_ACTIVE_HIGH', true))),
            'lead' => (float) env('RS485_GPIO_LEAD_SECONDS', env('MODBUS_RS485_GPIO_LEAD', 0.0)),
            'tail' => (float) env('RS485_GPIO_TAIL_SECONDS', env('MODBUS_RS485_GPIO_TAIL', 0.0)),
        ],
        'rts' => [
            'device' => env('RS485_RTS_DEVICE', env('MODBUS_PORT')),
            'python' => env('RS485_RTS_PYTHON', env('PYTHON_BINARY', 'python3')),
            'tx_high' => $bool(env('RS485_RTS_TX_HIGH', env('MODBUS_RS485_DRIVER_RTS_TX_HIGH', true))),
            'rx_high' => $bool(env('RS485_RTS_RX_HIGH', env('MODBUS_RS485_DRIVER_RTS_RX_HIGH', false))),
            'lead' => (float) env('RS485_RTS_LEAD_SECONDS', env('MODBUS_RS485_DRIVER_LEAD', 0.0)),
            'tail' => (float) env('RS485_RTS_TAIL_SECONDS', env('MODBUS_RS485_DRIVER_TAIL', 0.0)),
        ],
    ],
];
