<?php

declare(strict_types=1);

$parseIntList = static function ($value): array {
    if ($value === null || $value === '') {
        return [];
    }

    $items = array_map('trim', explode(',', (string) $value));
    $items = array_filter($items, static fn ($item) => $item !== '');

    return array_values(array_map(static fn ($item) => (int) $item, $items));
};

$parseStringList = static function ($value): array {
    if ($value === null || $value === '') {
        return [];
    }

    $items = array_map('trim', explode(',', (string) $value));

    return array_values(array_filter($items, static fn ($item) => $item !== ''));
};

$parseBool = static function ($value, bool $default = false): bool {
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower((string) $value);
    if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
        return true;
    }

    if ($normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
        return false;
    }

    return $default;
};

return [
    'default_route' => array_values(array_filter(array_map(
        static fn ($value) => (int) trim($value),
        explode(',', (string) env('BROADCAST_DEFAULT_ROUTE', '1,116,225')),
    ))),
    'mixer' => [
        'enabled' => env('BROADCAST_MIXER_ENABLED', false),
        'binary' => env('BROADCAST_MIXER_BINARY', 'alsamixer'),
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
        'routing' => [
            'inputs' => [],
            'outputs' => [],
        ],
    ],
    'live' => [
        'source' => env('BROADCAST_LIVE_SOURCE', 'microphone'),
        'route' => $parseIntList(env('BROADCAST_LIVE_ROUTE')),
        'zones' => $parseIntList(env('BROADCAST_LIVE_ZONES')),
        'update_route' => $parseBool(env('BROADCAST_LIVE_UPDATE_ROUTE', false)),
        'timeout' => ($timeout = env('BROADCAST_LIVE_TIMEOUT')) !== null && $timeout !== ''
            ? (float) $timeout
            : null,
        'volume_groups' => $parseStringList(env('BROADCAST_LIVE_VOLUME_GROUPS', 'inputs,outputs')),
    ],
];
