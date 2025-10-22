<?php

return [
    'port' => env('MODBUS_PORT', '/dev/tty.usbserial-AV0K3CPZ'),
    'method' => env('MODBUS_METHOD', 'rtu'),
    'baudrate' => (int) env('MODBUS_BAUDRATE', 57600),
    'parity' => env('MODBUS_PARITY', 'N'),
    'stopbits' => (int) env('MODBUS_STOPBITS', 1),
    'bytesize' => (int) env('MODBUS_BYTESIZE', 8),
    'timeout' => (float) env('MODBUS_TIMEOUT', 1.0),
    'unit_id' => (int) env('MODBUS_UNIT_ID', 1),
];
