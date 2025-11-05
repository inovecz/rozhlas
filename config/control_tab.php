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
    'cli' => [
        'python' => env('CONTROL_TAB_PYTHON', env('PYTHON_BINARY', 'python3')),
        'script' => env('CONTROL_TAB_CLI_SCRIPT', base_path('python-client/ct_listener.py')),
        'timeout' => (float) env('CONTROL_TAB_CLI_TIMEOUT', 5.0),
    ],
    'modbus_unit_id' => (int) env('CONTROL_TAB_MODBUS_UNIT_ID', (int) env('MODBUS_UNIT_ID', 55)),
    'webhook' => env('CONTROL_TAB_WEBHOOK', 'http://127.0.0.1/api/control-tab/events'),
    'token' => env('CONTROL_TAB_TOKEN'),
    'poll_interval' => (float) env('CONTROL_TAB_POLL_INTERVAL', 0.05),
    'graceful_timeout' => (float) env('CONTROL_TAB_GRACEFUL_TIMEOUT', 5.0),
    'retry_backoff_ms' => (int) env('CONTROL_TAB_RETRY_BACKOFF_MS', 250),
    'inter_message_delay_ms' => (int) env('CONTROL_TAB_INTER_MESSAGE_DELAY_MS', 5),
    'default_location_group_id' => (int) env('CONTROL_TAB_DEFAULT_LOCATION_GROUP_ID', 1),
    'test_progress_field' => (int) env('CONTROL_TAB_TEST_PROGRESS_FIELD', 1),
    'default_screen' => (int) env('CONTROL_TAB_DEFAULT_SCREEN', 3),
    'default_panel' => (int) env('CONTROL_TAB_DEFAULT_PANEL', 1),
    'text_fields' => [
        1 => 'status_summary',
        2 => 'running_duration',
        3 => 'running_length',
        4 => 'planned_duration',
        5 => 'active_locations',
        6 => 'active_playlist_item',
    ],
    'buttons' => [
        1 => [
            'action' => 'ack_message',
            'message' => 'Vyberte „Spustit přímé hlášení“ v dalším kroku.',
        ],
        2 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 2,
            'label' => 'Zkouška sirén',
        ],
        3 => [
            'action' => 'select_jsvv_alarm',
            'button' => 3,
            'label' => 'Chemická havárie',
        ],
        4 => [
            'action' => 'select_jsvv_alarm',
            'button' => 4,
            'label' => 'Všeobecná výstraha',
        ],
        5 => [
            'action' => 'select_jsvv_alarm',
            'button' => 5,
            'label' => 'Požární poplach',
        ],
        6 => [
            'action' => 'select_jsvv_alarm',
            'button' => 6,
            'label' => 'Zátopová vlna',
        ],
        7 => [
            'action' => 'ack_message',
            'message' => 'Zvolte „Spustit přímé hlášení“ na další obrazovce.',
        ],
        8 => [
            'action' => 'stop_jsvv',
            'success_message' => 'Poplach JSVV byl zastaven.',
        ],
        9 => [
            'action' => 'start_or_trigger_selected_jsvv_alarm',
            'source' => 'microphone',
            'options' => [
                'origin' => 'prime_hlaseni',
            ],
            'locations' => [(int) env('CONTROL_TAB_DEFAULT_LOCATION_GROUP_ID', 1)],
            'success_message' => 'Přímé hlášení bylo spuštěno.',
        ],
        10 => [
            'action' => 'stop_stream',
            'success_message' => 'Přímé hlášení bylo ukončeno.',
            'idle_message' => 'Žádné vysílání neběží.',
        ],
        11 => [
            'action' => 'start_or_trigger_selected_jsvv_alarm',
            'source' => 'microphone',
            'options' => [
                'origin' => 'prime_hlaseni',
            ],
            'locations' => [(int) env('CONTROL_TAB_DEFAULT_LOCATION_GROUP_ID', 1)],
            'success_message' => 'Přímé hlášení bylo spuštěno.',
        ],
        12 => [
            'action' => 'stop_stream',
            'success_message' => 'Přímé hlášení bylo ukončeno.',
            'idle_message' => 'Žádné vysílání neběží.',
        ],
        13 => [
            'action' => 'trigger_selected_jsvv_alarm',
            'fallback_button' => 2,
            'label' => 'Poplach JSVV',
        ],
        14 => [
            'action' => 'ack_message',
            'message' => 'Funkce „Ostatní“ není v této verzi dostupná.',
        ],
        15 => [
            'action' => 'stop_stream',
            'success_message' => 'Přímé hlášení bylo ukončeno.',
            'idle_message' => 'Žádné vysílání neběží.',
        ],
        16 => [
            'action' => 'lock_panel',
            'message' => 'Panel byl uzamčen. Pro odemknutí použijte kód na zařízení.',
        ],
        17 => [
            'action' => 'select_jsvv_alarm',
            'button' => 17,
            'label' => 'Radiační poplach',
        ],
        18 => [
            'action' => 'cancel_selection_stop_stream',
            'success_message' => 'Výběr poplachu byl zrušen a přímé hlášení bylo ukončeno.',
            'idle_message' => 'Výběr poplachu byl zrušen. Žádné vysílání neběží.',
        ],
        19 => [
            'action' => 'ack_message',
            'message' => 'Výběr znělky proveďte v aplikaci.',
        ],
        20 => [
            'action' => 'ack_message',
            'message' => 'Výběr lokality proveďte v aplikaci.',
        ],
    ],
    'defaults' => [
        'route' => [],
        'locations' => [(int) env('CONTROL_TAB_DEFAULT_LOCATION_GROUP_ID', 1)],
        'nests' => [],
        'options' => [],
    ],
];
