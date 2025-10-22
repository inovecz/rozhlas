<?php

declare(strict_types=1);

return [
    'default_route' => array_values(array_filter(array_map(
        static fn ($value) => (int) trim($value),
        explode(',', (string) env('BROADCAST_DEFAULT_ROUTE', '1,116,225')),
    ))),
    'mixer' => [
        'enabled' => env('BROADCAST_MIXER_ENABLED', false),
        'binary' => env('BROADCAST_MIXER_BINARY', '/usr/local/bin/alza-mixer'),
        'timeout' => (int) env('BROADCAST_MIXER_TIMEOUT', 10),
        'presets' => [
            'microphone' => [
                'args' => ['preset', 'microphone'],
            ],
            'central_file' => [
                'args' => ['preset', 'central-file'],
            ],
            'pc_webrtc' => [
                'args' => ['preset', 'pc-webrtc'],
            ],
            'input_2' => [
                'args' => ['preset', 'input-2'],
            ],
            'input_3' => [
                'args' => ['preset', 'input-3'],
            ],
            'input_4' => [
                'args' => ['preset', 'input-4'],
            ],
            'input_5' => [
                'args' => ['preset', 'input-5'],
            ],
            'input_6' => [
                'args' => ['preset', 'input-6'],
            ],
            'input_7' => [
                'args' => ['preset', 'input-7'],
            ],
            'input_8' => [
                'args' => ['preset', 'input-8'],
            ],
            'fm_radio' => [
                'args' => ['preset', 'fm-radio'],
            ],
            'control_box' => [
                'args' => ['preset', 'control-box'],
            ],
            'default' => [
                'args' => ['preset', 'default'],
            ],
        ],
        'reset' => [
            'args' => ['reset'],
        ],
    ],
];
