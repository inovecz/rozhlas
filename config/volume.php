<?php

declare(strict_types=1);

return [
    /*
     * Template used when sending volume commands to ALSA mixer.
     * The placeholder {{alsa_control}} resolves to the target control name,
     * {{value}} to the numeric level, and {{value_percent}} to the integer percentage.
     */
    'command_template' => 'amixer -D pulse sset "{{alsa_control}}" {{value_percent}}%',

    'inputs' => [
        'label' => 'Hlasitost vstupů',
        'items' => [
            'capture_level' => [
                'label' => 'Analogový vstup (Capture)',
                'default' => 50.0,
                'channel' => 'capture_level',
                'alsa_control' => 'Capture',
            ],
        ],
    ],

    'outputs' => [
        'label' => 'Hlasitost výstupů',
        'items' => [
            'playback_level' => [
                'label' => 'Hlavní výstup (Master)',
                'default' => 50.0,
                'channel' => 'playback_level',
                'alsa_control' => 'Master',
            ],
        ],
    ],

    'playback' => [
        'label' => 'Hlasitost přehrávání',
        'items' => [
            'file_playback' => [
                'label' => 'Soubor v ústředně',
                'default' => 50.0,
                'channel' => 'file_playback',
                'alsa_control' => 'Master',
            ],
            'gsm_playback' => [
                'label' => 'Přehrávání GSM',
                'default' => 50.0,
                'channel' => 'gsm_playback',
                'alsa_control' => 'Master',
            ],
        ],
    ],

    'source_channels' => [
        'microphone' => 'capture_level',
        'central_file' => 'capture_level',
        'pc_webrtc' => 'capture_level',
        'system_audio' => 'capture_level',
        'input_2' => 'capture_level',
        'input_3' => 'capture_level',
        'input_4' => 'capture_level',
        'input_5' => 'capture_level',
        'input_6' => 'capture_level',
        'input_7' => 'capture_level',
        'input_8' => 'capture_level',
        'fm_radio' => 'capture_level',
        'control_box' => 'capture_level',
    ],

    'source_output_channels' => [
        'microphone' => 'playback_level',
        'central_file' => 'playback_level',
        'pc_webrtc' => 'playback_level',
        'system_audio' => 'playback_level',
        'input_2' => 'playback_level',
        'input_3' => 'playback_level',
        'input_4' => 'playback_level',
        'input_5' => 'playback_level',
        'input_6' => 'playback_level',
        'input_7' => 'playback_level',
        'input_8' => 'playback_level',
        'fm_radio' => 'playback_level',
        'control_box' => 'playback_level',
    ],
];
