<?php

declare(strict_types=1);

$toBool = static function (mixed $value, bool $default = true): bool {
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

$routingEnabledEnv = env('BROADCAST_AUDIO_ROUTING_ENABLED');
if ($routingEnabledEnv === null) {
    $routingEnabledEnv = env('BROADCAST_MIXER_ENABLED');
}
if ($routingEnabledEnv === null) {
    $routingEnabledEnv = true;
}

$defaultOutputIdentifier = (string) env('AUDIO_DEFAULT_OUTPUT', 'lineout');
if ($defaultOutputIdentifier === '') {
    $defaultOutputIdentifier = 'lineout';
}

$defaultPresetIdentifier = (string) env('AUDIO_DEFAULT_PRESET', 'microphone');
if ($defaultPresetIdentifier === '') {
    $defaultPresetIdentifier = 'microphone';
}

return [
    /*
     * When false, mixer commands are skipped entirely. Falls back to the mixer toggle.
     */
    'enabled' => $toBool($routingEnabledEnv),

    /*
     * ALSA device that should receive audio when routing is disabled. Not applied automatically
     * while routing is enabled (the mixer definitions take precedence).
     */
    'fallback_output_device' => (string) env('BROADCAST_AUDIO_FALLBACK_OUTPUT', 'default'),

    /*
     * Default ALSA card used when invoking amixer/aplay/arecord.
     * Set AUDIO_MIXER_CARD in the environment to override.
     */
    'card' => env('AUDIO_MIXER_CARD', '0'),

    /*
     * Executables used for mixer and device probing.
     */
    'binaries' => [
        'amixer' => env('AUDIO_AMIXER_BINARY', 'amixer'),
        'aplay' => env('AUDIO_APLAY_BINARY', 'aplay'),
        'arecord' => env('AUDIO_ARECORD_BINARY', 'arecord'),
    ],

    /*
     * Environment variables forwarded to subprocesses to keep the output
     * language predictable for parsing.
     */
    'process_env' => [
        'LANG' => 'C',
        'LC_ALL' => 'C',
    ],

    /*
     * Default timeout (in seconds) applied to spawned processes.
     */
    'timeout' => (float) env('AUDIO_PROCESS_TIMEOUT', 5),

    /*
     * Input routing definitions. Each logical identifier can map to multiple
     * mixer controls which are applied in order. When alias_of is present the
     * referenced definition is reused.
     */
    'inputs' => [
        'primary_control' => null,
        'mute_control' => 'PGA',
        'items' => [
            'mic' => [
                'label' => 'Mikrofon',
                'controls' => [
                    'Left PGA Mixer Mic3L' => 'on',
                    'Left PGA Mixer Mic3R' => 'on',
                    'Left PGA Mixer Line2L' => 'off',
                    'Right PGA Mixer Mic3L' => 'on',
                    'Right PGA Mixer Mic3R' => 'on',
                    'Right PGA Mixer Line2R' => 'off',
                ],
                'device' => 'tlv320aic3x',
            ],
            'fm' => [
                'label' => 'FM vstup',
                'controls' => [
                    'Left PGA Mixer Mic3L' => 'off',
                    'Left PGA Mixer Mic3R' => 'off',
                    'Left PGA Mixer Line2L' => 'on',
                    'Right PGA Mixer Mic3L' => 'off',
                    'Right PGA Mixer Mic3R' => 'off',
                    'Right PGA Mixer Line2R' => 'on',
                ],
                'device' => 'tlv320aic3x',
            ],
            'system' => [
                'label' => 'Systémový zvuk',
                'controls' => [
                    'Left PGA Mixer Mic3L' => 'off',
                    'Left PGA Mixer Mic3R' => 'off',
                    'Left PGA Mixer Line2L' => 'on',
                    'Right PGA Mixer Mic3L' => 'off',
                    'Right PGA Mixer Mic3R' => 'off',
                    'Right PGA Mixer Line2R' => 'on',
                ],
                'device' => 'tlv320aic3x',
            ],
            'gsm' => [
                'label' => 'GSM modul',
                'alias_of' => 'system',
                'device' => 'tlv320aic3x',
            ],
            'file' => [
                'label' => 'Soubor v ústředně',
                'alias_of' => 'system',
                'device' => 'tlv320aic3x',
            ],
            'pc_webrtc' => [
                'label' => 'Vstup z PC (WebRTC)',
                'alias_of' => 'system',
                'device' => 'tlv320aic3x',
            ],
            'control_box' => [
                'label' => 'Control box',
                'alias_of' => 'jsvv_local_voice',
                'device' => 'tlv320aic3x',
            ],
            'jsvv_remote_voice' => [
                'label' => 'JSVV – Vzdálený hlas',
                'alias_of' => 'pc_webrtc',
                'device' => 'tlv320aic3x',
            ],
            'jsvv_local_voice' => [
                'label' => 'JSVV – Místní mikrofon',
                'alias_of' => 'mic',
                'device' => 'tlv320aic3x',
            ],
            'jsvv_external_primary' => [
                'label' => 'JSVV – Externí audio (primární)',
                'alias_of' => 'system',
                'device' => 'tlv320aic3x',
            ],
            'jsvv_external_secondary' => [
                'label' => 'JSVV – Externí audio (sekundární)',
                'alias_of' => 'system',
                'device' => 'tlv320aic3x',
            ],
        ],
    ],

    /*
     * Output routing definitions. Similar structure to inputs.
     */
    'outputs' => [
        'primary_control' => null,
        'mute_control' => 'Line',
        'items' => [
            'lineout' => [
                'label' => 'Line Out',
                'controls' => [
                    'Left Line Mixer DACL1' => 'on',
                    'Right Line Mixer DACL1' => 'on',
                    'Line' => 'on',
                ],
                'device' => 'tlv320aic3x',
            ],
        ],
    ],

    /*
     * Logical presets combining mixer input/output selections.
     */
    'presets' => [
        'microphone' => [
            'label' => 'Mikrofon',
            'input' => 'mic',
            'output' => $defaultOutputIdentifier,
        ],
        'central_file' => [
            'label' => 'Soubor v ústředně',
            'input' => 'file',
            'output' => $defaultOutputIdentifier,
        ],
        'pc_webrtc' => [
            'label' => 'Vstup z PC (WebRTC)',
            'input' => 'pc_webrtc',
            'output' => $defaultOutputIdentifier,
        ],
        'system_audio' => [
            'label' => 'Systémový zvuk',
            'input' => 'system',
            'output' => $defaultOutputIdentifier,
        ],
        'fm_radio' => [
            'label' => 'FM rádio',
            'input' => 'fm',
            'output' => $defaultOutputIdentifier,
        ],
        'control_box' => [
            'label' => 'Control box',
            'input' => 'control_box',
            'output' => $defaultOutputIdentifier,
        ],
        'gsm' => [
            'label' => 'GSM modul',
            'input' => 'gsm',
            'output' => $defaultOutputIdentifier,
        ],
        'jsvv_remote_voice' => [
            'label' => 'JSVV – Vzdálený hlas',
            'input' => 'jsvv_remote_voice',
            'output' => $defaultOutputIdentifier,
        ],
        'jsvv_local_voice' => [
            'label' => 'JSVV – Místní mikrofon',
            'input' => 'jsvv_local_voice',
            'output' => $defaultOutputIdentifier,
        ],
        'jsvv_external_primary' => [
            'label' => 'JSVV – Externí audio (primární)',
            'input' => 'jsvv_external_primary',
            'output' => $defaultOutputIdentifier,
        ],
        'jsvv_external_secondary' => [
            'label' => 'JSVV – Externí audio (sekundární)',
            'input' => 'jsvv_external_secondary',
            'output' => $defaultOutputIdentifier,
        ],
        'default' => [
            'label' => 'Výchozí',
            'alias_of' => $defaultPresetIdentifier,
        ],
    ],

    /*
     * Volume controls with associated mute switches. Percentages are expected
     * in range 0-100.
     */
    'volumes' => [
        'master' => [
            'label' => 'Master výstup',
            'control' => 'DAC Playback Volume',
            'mute_control' => 'DAC Playback Switch',
            'type' => 'playback',
        ],
        'output' => [
            'label' => 'Výstup (DAC)',
            'control' => 'DAC Playback Volume',
            'mute_control' => 'DAC Playback Switch',
            'type' => 'playback',
        ],
        'input' => [
            'label' => 'Vstup (ADC)',
            'control' => 'ADC Capture Volume',
            'mute_control' => 'ADC Capture Switch',
            'type' => 'capture',
        ],
    ],
];
