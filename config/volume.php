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
            'input_1' => [
                'label' => 'Vstup 1',
                'default' => 50.0,
                'channel' => 'input_1',
                'alsa_control' => 'Capture',
            ],
            'input_2' => [
                'label' => 'Vstup 2',
                'default' => 50.0,
                'channel' => 'input_2',
                'alsa_control' => 'Capture',
            ],
            'rx_audio_dif' => [
                'label' => 'RX_AUDIO_DIF',
                'default' => 50.0,
                'channel' => 'rx_audio_dif',
                'alsa_control' => 'Capture',
            ],
            'control_box' => [
                'label' => 'Control box',
                'default' => 50.0,
                'channel' => 'control_box',
                'alsa_control' => 'Capture',
            ],
            'pc_webrtc' => [
                'label' => 'Vstup z PC (WebRTC)',
                'default' => 50.0,
                'channel' => 'pc_webrtc',
                'alsa_control' => 'Capture',
            ],
            'input_3' => [
                'label' => 'Vstup 3',
                'default' => 50.0,
                'channel' => 'input_3',
                'alsa_control' => 'Capture',
            ],
            'input_4' => [
                'label' => 'Vstup 4',
                'default' => 50.0,
                'channel' => 'input_4',
                'alsa_control' => 'Capture',
            ],
            'input_5' => [
                'label' => 'Vstup 5',
                'default' => 50.0,
                'channel' => 'input_5',
                'alsa_control' => 'Capture',
            ],
            'input_6' => [
                'label' => 'Vstup 6',
                'default' => 50.0,
                'channel' => 'input_6',
                'alsa_control' => 'Capture',
            ],
            'input_7' => [
                'label' => 'Vstup 7',
                'default' => 50.0,
                'channel' => 'input_7',
                'alsa_control' => 'Capture',
            ],
            'input_8' => [
                'label' => 'Vstup 8',
                'default' => 50.0,
                'channel' => 'input_8',
                'alsa_control' => 'Capture',
            ],
            'input_9' => [
                'label' => 'Vstup 9',
                'default' => 50.0,
                'channel' => 'input_9',
                'alsa_control' => 'Capture',
            ],
            'modem_80' => [
                'label' => 'MODEM_80',
                'default' => 50.0,
                'channel' => 'modem_80',
                'alsa_control' => 'Capture',
            ],
            'gsm_in' => [
                'label' => 'GSM_IN',
                'default' => 50.0,
                'channel' => 'gsm_in',
                'alsa_control' => 'Capture',
            ],
            'fm_radio' => [
                'label' => 'FM Rádio',
                'default' => 50.0,
                'channel' => 'fm_radio',
                'alsa_control' => 'Capture',
            ],
        ],
    ],

    'outputs' => [
        'label' => 'Hlasitost výstupů',
        'items' => [
            'tx_audio' => [
                'label' => 'TX_AUDIO',
                'default' => 50.0,
                'channel' => 'tx_audio',
                'alsa_control' => 'Master',
            ],
            'speaker_1' => [
                'label' => 'SPEAKER_1',
                'default' => 50.0,
                'channel' => 'speaker_1',
                'alsa_control' => 'Master',
            ],
            'speaker_2' => [
                'label' => 'SPEAKER_2',
                'default' => 50.0,
                'channel' => 'speaker_2',
                'alsa_control' => 'Master',
            ],
            'gsm_out' => [
                'label' => 'GSM_OUT',
                'default' => 50.0,
                'channel' => 'gsm_out',
                'alsa_control' => 'Master',
            ],
            'central_100v' => [
                'label' => 'CENTRAL_100V',
                'default' => 50.0,
                'channel' => 'central_100v',
                'alsa_control' => 'Master',
            ],
            'line_out' => [
                'label' => 'LINE_OUT',
                'default' => 50.0,
                'channel' => 'line_out',
                'alsa_control' => 'Master',
            ],
            'subtone' => [
                'label' => 'SUBTONE',
                'default' => 50.0,
                'channel' => 'subtone',
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
        'microphone' => 'input_1',
        'central_file' => 'file_playback',
        'pc_webrtc' => 'pc_webrtc',
        'input_2' => 'input_2',
        'input_3' => 'input_3',
        'input_4' => 'input_4',
        'input_5' => 'input_5',
        'input_6' => 'input_6',
        'input_7' => 'input_7',
        'input_8' => 'input_8',
        'fm_radio' => 'fm_radio',
        'control_box' => 'control_box',
    ],
];
