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

$parsePlayerArguments = static function ($value): ?array {
    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    $stringValue = trim((string) $value);

    if (str_starts_with($stringValue, '[')) {
        try {
            $decoded = json_decode($stringValue, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            return array_values(array_map(static fn ($item) => (string) $item, $decoded));
        }
    }

    $items = str_getcsv($stringValue, ' ');
    $items = array_map(static fn ($item) => trim($item), $items);
    $items = array_values(array_filter($items, static fn ($item) => $item !== ''));

    return $items !== [] ? $items : null;
};

$mixerPresetSources = [
    'microphone',
    'central_file',
    'pc_webrtc',
    'system_audio',
    'fm_radio',
    'control_box',
    'gsm',
    'jsvv_remote_voice',
    'jsvv_local_voice',
    'jsvv_external_primary',
    'jsvv_external_secondary',
];

$defaultPresetArgs = ['artisan', 'audio:preset', '{{source}}'];
$mixerPresets = [];
foreach ($mixerPresetSources as $presetSource) {
    $mixerPresets[$presetSource] = [
        'args' => $defaultPresetArgs,
    ];
}
$mixerPresets['default'] = [
    'args' => ['artisan', 'audio:preset', 'default'],
];

$resetPreset = (string) env('AUDIO_MIXER_RESET_PRESET', 'default');
if ($resetPreset === '') {
    $resetPreset = 'default';
}

$inputRoutingIdentifiers = [
    'mic',
    'fm',
    'system',
    'file',
    'pc_webrtc',
    'control_box',
    'gsm',
    'jsvv_remote_voice',
    'jsvv_local_voice',
    'jsvv_external_primary',
    'jsvv_external_secondary',
];

$inputRoutingArgs = ['artisan', 'audio:input', '{{audio_id}}'];
$inputRouting = [];
foreach ($inputRoutingIdentifiers as $identifier) {
    $inputRouting[$identifier] = [
        'args' => $inputRoutingArgs,
    ];
}

$outputRoutingIdentifiers = ['lineout'];
$outputRoutingArgs = ['artisan', 'audio:output', '{{audio_id}}'];
$outputRouting = [];
foreach ($outputRoutingIdentifiers as $identifier) {
    $outputRouting[$identifier] = [
        'args' => $outputRoutingArgs,
    ];
}

return [
    'default_route' => array_values(array_filter(array_map(
        static fn ($value) => (int) trim($value),
        explode(',', (string) env('AUDIO_DEFAULT_ROUTE', '1,116,225')),
    ))),
    'mixer' => [
        'enabled' => env('AUDIO_MIXER_ENABLED', false),
        'binary' => env('AUDIO_MIXER_BINARY', PHP_BINARY),
        'timeout' => (int) env('AUDIO_MIXER_TIMEOUT', 10),
        'presets' => $mixerPresets,
        'reset' => [
            'args' => ['artisan', 'audio:preset', $resetPreset],
        ],
        'routing' => [
            'inputs' => $inputRouting,
            'outputs' => $outputRouting,
        ],
    ],
    'live' => [
        'source' => env('AUDIO_LIVE_SOURCE', 'microphone'),
        'route' => $parseIntList(env('AUDIO_LIVE_ROUTE')),
        'zones' => $parseIntList(env('AUDIO_LIVE_ZONES')),
        'update_route' => $parseBool(env('AUDIO_LIVE_UPDATE_ROUTE', false)),
        'timeout' => ($timeout = env('AUDIO_LIVE_TIMEOUT')) !== null && $timeout !== ''
            ? (float) $timeout
            : null,
        'volume_groups' => $parseStringList(env('AUDIO_LIVE_VOLUME_GROUPS', 'inputs,outputs')),
    ],
    'auto_timeout' => [
        'enabled' => $parseBool(env('AUDIO_AUTO_TIMEOUT_ENABLED', true), true),
        'seconds' => (int) env('AUDIO_AUTO_TIMEOUT_SECONDS', 595),
        'sources' => $parseStringList(env('AUDIO_AUTO_TIMEOUT_SOURCES')),
    ],
    'playlist' => [
        'storage_root' => env('PLAYLIST_STORAGE_ROOT', storage_path('app/recordings')),
        'storage_fallbacks' => array_filter([
            env('PLAYLIST_STORAGE_FALLBACK'),
        ]),
        'supported_extensions' => array_values(array_filter(array_map(
            static fn (string $value) => strtolower(trim($value)),
            explode(',', (string) env('PLAYLIST_SUPPORTED_EXTENSIONS', 'mp3,wav,ogg,flac')),
        ), static fn (string $value) => $value !== '')),
        'default_gap_ms' => (int) env('PLAYLIST_DEFAULT_GAP_MS', 250),
        'player' => [
            'binary' => env('PLAYLIST_PLAYER_BINARY', env('PLAYLIST_PLAYER', 'ffmpeg')),
            'arguments' => $parsePlayerArguments(env('PLAYLIST_PLAYER_ARGUMENTS')) ?? [
                '-nostdin',
                '-hide_banner',
                '-loglevel',
                env('PLAYLIST_PLAYER_LOGLEVEL', 'error'),
                '-i',
                '{input}',
                '-vn',
                '-f',
                env('PLAYLIST_PLAYER_FORMAT', 'alsa'),
                env('PLAYLIST_PLAYER_OUTPUT', 'default'),
            ],
            'timeout' => ($playerTimeout = env('PLAYLIST_PLAYER_TIMEOUT')) !== null && $playerTimeout !== ''
                ? (float) $playerTimeout
                : null,
        ],
    ],

    'schedule' => [
        'input' => env('AUDIO_SCHEDULE_INPUT', 'file'),
        'reset_input' => env('AUDIO_SCHEDULE_RESET_INPUT'),
        'queue' => env('AUDIO_SCHEDULE_QUEUE'),
    ],
];
