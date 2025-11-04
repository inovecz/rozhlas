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

$routingEnabledEnv = env('AUDIO_ROUTING_ENABLED', true);

$defaultOutputIdentifier = (string) env('AUDIO_DEFAULT_OUTPUT', 'lineout');
if ($defaultOutputIdentifier === '') {
    $defaultOutputIdentifier = 'lineout';
}

$defaultPresetIdentifier = (string) env('AUDIO_DEFAULT_PRESET', 'microphone');
if ($defaultPresetIdentifier === '') {
    $defaultPresetIdentifier = 'microphone';
}

$mixerCard = env('AUDIO_MIXER_CARD', '0');

$alsamixerBinary = env('AUDIO_ALSAMIXER_BINARY', base_path('python-client/alsamixer.py'));
if (!is_string($alsamixerBinary) || $alsamixerBinary === '') {
    $alsamixerBinary = base_path('python-client/alsamixer.py');
}
$alsamixerBinary = (string) $alsamixerBinary;
if (!str_starts_with($alsamixerBinary, DIRECTORY_SEPARATOR) && !preg_match('#^[A-Za-z]:[\\\\/]#', $alsamixerBinary)) {
    $alsamixerBinary = base_path($alsamixerBinary);
}
$alsamixerPython = env('AUDIO_ALSAMIXER_PYTHON', env('APP_PYTHON_BINARY', 'python3'));

return [
    /*
     * When false, mixer commands are skipped entirely. Falls back to the mixer toggle.
     */
    'enabled' => $toBool($routingEnabledEnv),

    /*
     * ALSA device that should receive audio when routing is disabled. Not applied automatically
     * while routing is enabled (the mixer definitions take precedence).
     */
    'fallback_output_device' => (string) env('AUDIO_FALLBACK_OUTPUT', 'default'),

    /*
     * Default ALSA card used when invoking amixer/aplay/arecord.
     * Set AUDIO_MIXER_CARD in the environment to override.
     */
    'card' => $mixerCard,

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

    'alsamixer' => [
        'enabled' => true,
        'python' => (string) $alsamixerPython,
        'binary' => $alsamixerBinary,
        'card' => (string) env('AUDIO_ALSAMIXER_CARD', $mixerCard),
        'timeout' => (float) env('AUDIO_ALSAMIXER_TIMEOUT', 8),
        'input_map' => [
            'mic' => 'mic',
            'microphone' => 'mic',
            'mic_capture' => 'mic',
            'system' => 'system',
            'system_audio' => 'system',
            'central_file' => 'system',
            'file' => 'system',
            'fm' => 'fm',
            'fm_radio' => 'fm',
            'input_1' => 'line1',
            'line1' => 'line1',
            'input_2' => 'line2',
            'line2' => 'line2',
        ],
        'input_volume_channels' => [
            'mic' => ['group' => 'inputs', 'channel' => 'mic_capture'],
            'microphone' => ['group' => 'inputs', 'channel' => 'mic_capture'],
            'input_1' => ['group' => 'inputs', 'channel' => 'mic_capture'],
            'line1' => ['group' => 'inputs', 'channel' => 'mic_capture'],
            'system' => ['group' => 'inputs', 'channel' => 'system_capture'],
            'system_audio' => ['group' => 'inputs', 'channel' => 'system_capture'],
            'central_file' => ['group' => 'inputs', 'channel' => 'system_capture'],
            'file' => ['group' => 'inputs', 'channel' => 'system_capture'],
            'fm' => ['group' => 'inputs', 'channel' => 'fm_capture'],
            'fm_radio' => ['group' => 'inputs', 'channel' => 'fm_capture'],
            'input_2' => ['group' => 'inputs', 'channel' => 'fm_capture'],
            'line2' => ['group' => 'inputs', 'channel' => 'fm_capture'],
        ],
        'volume_channels' => [
            'mic_capture' => 'mic',
            'fm_capture' => 'line2',
            'system_capture' => 'system',
        ],
    ],

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
            'input_1' => [
                'label' => 'Vstup 1',
                'controls' => [
                    'Left PGA Mixer Line1L' => 'on',
                    'Left PGA Mixer Line1R' => 'off',
                    'Left PGA Mixer Line2L' => 'off',
                    'Left PGA Mixer Mic3L' => 'off',
                    'Left PGA Mixer Mic3R' => 'off',
                    'Right PGA Mixer Line1R' => 'on',
                    'Right PGA Mixer Line1L' => 'off',
                    'Right PGA Mixer Line2R' => 'off',
                    'Right PGA Mixer Mic3L' => 'off',
                    'Right PGA Mixer Mic3R' => 'off',
                    'Left HP Mixer DACL1' => 'off',
                    'Right HP Mixer DACR1' => 'off',
                    'Left HP Mixer Line2L Bypass' => 'off',
                    'Right HP Mixer Line2R Bypass' => 'off',
                    'Left HP Mixer PGAL Bypass' => 'on',
                    'Right HP Mixer PGAR Bypass' => 'on',
                    'Left Line Mixer DACL1' => 'off',
                    'Right Line Mixer DACR1' => 'off',
                    'Left Line Mixer Line2L Bypass' => 'off',
                    'Right Line Mixer Line2R Bypass' => 'off',
                    'Left Line Mixer PGAL Bypass' => 'on',
                    'Right Line Mixer PGAR Bypass' => 'on',
                    'Left HPCOM Mixer DACL1' => 'off',
                    'Right HPCOM Mixer DACR1' => 'off',
                    'Left HPCOM Mixer Line2L Bypass' => 'off',
                    'Right HPCOM Mixer Line2R Bypass' => 'off',
                    'Left HPCOM Mixer PGAL Bypass' => 'on',
                    'Right HPCOM Mixer PGAR Bypass' => 'on',
                ],
                'device' => 'tlv320aic3x',
            ],
            'input_2' => [
                'label' => 'Vstup 2',
                'controls' => [
                    'Left PGA Mixer Line1L' => 'off',
                    'Left PGA Mixer Line1R' => 'off',
                    'Left PGA Mixer Line2L' => 'on',
                    'Left PGA Mixer Mic3L' => 'off',
                    'Left PGA Mixer Mic3R' => 'off',
                    'Right PGA Mixer Line1L' => 'off',
                    'Right PGA Mixer Line1R' => 'off',
                    'Right PGA Mixer Line2R' => 'on',
                    'Right PGA Mixer Mic3L' => 'off',
                    'Right PGA Mixer Mic3R' => 'off',
                    'Left HP Mixer DACL1' => 'off',
                    'Right HP Mixer DACR1' => 'off',
                    'Left HP Mixer Line2L Bypass' => 'on',
                    'Right HP Mixer Line2R Bypass' => 'on',
                    'Left HP Mixer PGAL Bypass' => 'on',
                    'Right HP Mixer PGAR Bypass' => 'on',
                    'Left Line Mixer DACL1' => 'off',
                    'Right Line Mixer DACR1' => 'off',
                    'Left Line Mixer Line2L Bypass' => 'on',
                    'Right Line Mixer Line2R Bypass' => 'on',
                    'Left Line Mixer PGAL Bypass' => 'on',
                    'Right Line Mixer PGAR Bypass' => 'on',
                    'Left HPCOM Mixer DACL1' => 'off',
                    'Right HPCOM Mixer DACR1' => 'off',
                    'Left HPCOM Mixer Line2L Bypass' => 'off',
                    'Right HPCOM Mixer Line2R Bypass' => 'off',
                    'Left HPCOM Mixer PGAL Bypass' => 'on',
                    'Right HPCOM Mixer PGAR Bypass' => 'on',
                ],
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
        'gsm' => [
            'label' => 'GSM modul',
            'input' => 'gsm',
            'output' => $defaultOutputIdentifier,
        ],
        'input_1' => [
            'label' => 'Vstup 1',
            'input' => 'input_1',
            'output' => $defaultOutputIdentifier,
        ],
        'input_2' => [
            'label' => 'Vstup 2',
            'input' => 'input_2',
            'output' => $defaultOutputIdentifier,
        ],
        'control_box' => [
            'label' => 'Control box',
            'input' => 'control_box',
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

    /*
     * Audio capture defaults for live recordings.
     */
    'capture' => [
        'device' => env('AUDIO_CAPTURE_DEVICE', 'default'),
        'format' => env('AUDIO_CAPTURE_FORMAT', 'cd'),
        'directory' => storage_path('app/public/recordings'),
    ],
];
