<?php

declare(strict_types=1);

return [
    'python' => env('MODBUS_PYTHON', env('PYTHON_BINARY', 'python3')),
    'script' => env('MODBUS_SCRIPT', base_path('python-client/modbus_control.py')),
    'port' => env('MODBUS_PORT', '/dev/ttyUSB0'),
    'unit_id' => (int) env('MODBUS_UNIT_ID', 55),
    'timeout' => env('MODBUS_TIMEOUT'),
];
