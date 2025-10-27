<?php

declare(strict_types=1);

return [
    /*
     * When false, mixer commands are skipped entirely. Falls back to the mixer toggle.
     */
    'enabled' => env('AUDIO_IO_ENABLED', env('BROADCAST_MIXER_ENABLED', true)),

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
        'primary_control' => 'Input Source',
        'mute_control' => 'ADC Capture Switch',
        'items' => [
            'system' => [
                'label' => 'Systémový zvuk (Loopback)',
                'controls' => [
                    'Input Source' => 'System',
                    'IN Source' => 'System',
                    'Capture Source' => 'System',
                ],
                'device' => 'pulse',
            ],
            'file' => [
                'label' => 'Soubor v ústředně',
                'alias_of' => 'system',
                'device' => null,
            ],
            'mic' => [
                'label' => 'Mikrofon',
                'controls' => [
                    'Input Source' => 'Mic',
                    'IN Source' => 'Mic',
                    'Capture Source' => 'Mic',
                ],
                'device' => 'tlv320aic3x',
            ],
            'fm' => [
                'label' => 'FM rádio',
                'controls' => [
                    'Input Source' => 'FM',
                    'IN Source' => 'FM',
                    'Capture Source' => 'FM',
                ],
                'device' => 'tlv320aic3x',
            ],
            'control_box' => [
                'label' => 'Control box',
                'controls' => [
                    'Input Source' => 'Control',
                    'IN Source' => 'Control',
                    'Capture Source' => 'Control',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux2' => [
                'label' => 'Aux 2',
                'controls' => [
                    'Input Source' => 'Aux2',
                    'IN Source' => 'Aux2',
                    'Capture Source' => 'Aux2',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux3' => [
                'label' => 'Aux 3',
                'controls' => [
                    'Input Source' => 'Aux3',
                    'IN Source' => 'Aux3',
                    'Capture Source' => 'Aux3',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux4' => [
                'label' => 'Aux 4',
                'controls' => [
                    'Input Source' => 'Aux4',
                    'IN Source' => 'Aux4',
                    'Capture Source' => 'Aux4',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux5' => [
                'label' => 'Aux 5',
                'controls' => [
                    'Input Source' => 'Aux5',
                    'IN Source' => 'Aux5',
                    'Capture Source' => 'Aux5',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux6' => [
                'label' => 'Aux 6',
                'controls' => [
                    'Input Source' => 'Aux6',
                    'IN Source' => 'Aux6',
                    'Capture Source' => 'Aux6',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux7' => [
                'label' => 'Aux 7',
                'controls' => [
                    'Input Source' => 'Aux7',
                    'IN Source' => 'Aux7',
                    'Capture Source' => 'Aux7',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux8' => [
                'label' => 'Aux 8',
                'controls' => [
                    'Input Source' => 'Aux8',
                    'IN Source' => 'Aux8',
                    'Capture Source' => 'Aux8',
                ],
                'device' => 'tlv320aic3x',
            ],
            'aux9' => [
                'label' => 'Aux 9',
                'controls' => [
                    'Input Source' => 'Aux9',
                    'IN Source' => 'Aux9',
                    'Capture Source' => 'Aux9',
                ],
                'device' => 'tlv320aic3x',
            ],
        ],
    ],

    /*
     * Output routing definitions. Similar structure to inputs.
     */
    'outputs' => [
        'primary_control' => 'Playback Path',
        'mute_control' => 'DAC Playback Switch',
        'items' => [
            'auto' => [
                'label' => 'Automaticky',
                'controls' => [
                    'Playback Path' => 'Auto',
                ],
                'device' => 'default',
            ],
            'lineout' => [
                'label' => 'Line Out',
                'controls' => [
                    'Playback Path' => 'LineOut',
                    'HP/LINE Select' => 'Line',
                    'Output Mux' => 'Line',
                ],
                'device' => 'tlv320aic3x',
            ],
            'hdmi' => [
                'label' => 'HDMI',
                'controls' => [
                    'Playback Path' => 'HDMI',
                    'HP/LINE Select' => 'HP',
                    'Output Mux' => 'HDMI',
                ],
                'device' => 'vc4hdmi',
            ],
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
