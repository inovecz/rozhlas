<?php

declare(strict_types=1);

return [
    /*
     * Template used when sending volume commands to ALSA mixer.
     * The placeholder {{alsa_control}} resolves to the target control name,
     * {{value}} to the numeric level, and {{value_db}} appends the dB suffix.
     */
    'command_template' => [
        'command' => sprintf(
            'amixer -q -c %s sset "{{alsa_control}}" {{value_db}}',
            env('BROADCAST_MIXER_CARD', 0)
        ),
    ],

    'inputs' => [
        'label' => 'Hlasitost vstupů',
        'items' => [
            'input_1' => [
                'label' => 'Vstup 1',
                'default' => 31.5,
                'channel' => 'input_1',
                'alsa_control' => 'Input 1',
            ],
            'input_2' => [
                'label' => 'Vstup 2',
                'default' => 19.5,
                'channel' => 'input_2',
                'alsa_control' => 'Input 2',
            ],
            'rx_audio_dif' => [
                'label' => 'RX_AUDIO_DIF',
                'default' => 0.0,
                'channel' => 'rx_audio_dif',
                'alsa_control' => 'RX_AUDIO_DIF',
            ],
            'control_box' => [
                'label' => 'Control box',
                'default' => 8.5,
                'channel' => 'control_box',
                'alsa_control' => 'Control box',
            ],
            'pc_webrtc' => [
                'label' => 'Vstup z PC (WebRTC)',
                'default' => 0.0,
                'channel' => 'pc_webrtc',
                'alsa_control' => 'PC WebRTC',
            ],
            'input_3' => [
                'label' => 'Vstup 3',
                'default' => -3.0,
                'channel' => 'input_3',
                'alsa_control' => 'Input 3',
            ],
            'input_4' => [
                'label' => 'Vstup 4',
                'default' => -2.5,
                'channel' => 'input_4',
                'alsa_control' => 'Input 4',
            ],
            'input_5' => [
                'label' => 'Vstup 5',
                'default' => -1.5,
                'channel' => 'input_5',
                'alsa_control' => 'Input 5',
            ],
            'input_6' => [
                'label' => 'Vstup 6',
                'default' => -0.5,
                'channel' => 'input_6',
                'alsa_control' => 'Input 6',
            ],
            'input_7' => [
                'label' => 'Vstup 7',
                'default' => 0.0,
                'channel' => 'input_7',
                'alsa_control' => 'Input 7',
            ],
            'input_8' => [
                'label' => 'Vstup 8',
                'default' => 2.5,
                'channel' => 'input_8',
                'alsa_control' => 'Input 8',
            ],
            'input_9' => [
                'label' => 'Vstup 9',
                'default' => 10.0,
                'channel' => 'input_9',
                'alsa_control' => 'Input 9',
            ],
            'modem_80' => [
                'label' => 'MODEM_80',
                'default' => 4.0,
                'channel' => 'modem_80',
                'alsa_control' => 'MODEM_80',
            ],
            'gsm_in' => [
                'label' => 'GSM_IN',
                'default' => 14.0,
                'channel' => 'gsm_in',
                'alsa_control' => 'GSM_IN',
            ],
            'fm_radio' => [
                'label' => 'FM Rádio',
                'default' => -12.0,
                'channel' => 'fm_radio',
                'alsa_control' => 'FM Rádio',
            ],
        ],
    ],

    'outputs' => [
        'label' => 'Hlasitost výstupů',
        'items' => [
            'tx_audio' => [
                'label' => 'TX_AUDIO',
                'default' => 0.0,
                'channel' => 'tx_audio',
                'alsa_control' => 'TX_AUDIO',
            ],
            'speaker_1' => [
                'label' => 'SPEAKER_1',
                'default' => 0.0,
                'channel' => 'speaker_1',
                'alsa_control' => 'SPEAKER_1',
            ],
            'speaker_2' => [
                'label' => 'SPEAKER_2',
                'default' => -22.0,
                'channel' => 'speaker_2',
                'alsa_control' => 'SPEAKER_2',
            ],
            'gsm_out' => [
                'label' => 'GSM_OUT',
                'default' => 0.0,
                'channel' => 'gsm_out',
                'alsa_control' => 'GSM_OUT',
            ],
            'central_100v' => [
                'label' => 'CENTRAL_100V',
                'default' => 0.0,
                'channel' => 'central_100v',
                'alsa_control' => 'CENTRAL_100V',
            ],
            'line_out' => [
                'label' => 'LINE_OUT',
                'default' => 0.0,
                'channel' => 'line_out',
                'alsa_control' => 'LINE_OUT',
            ],
            'subtone' => [
                'label' => 'SUBTONE',
                'default' => 36.0,
                'channel' => 'subtone',
                'alsa_control' => 'SUBTONE',
            ],
        ],
    ],

    'playback' => [
        'label' => 'Hlasitost přehrávání',
        'items' => [
            'file_playback' => [
                'label' => 'Soubor v ústředně',
                'default' => -14.0,
                'channel' => 'file_playback',
                'alsa_control' => 'File playback',
            ],
            'gsm_playback' => [
                'label' => 'Přehrávání GSM',
                'default' => -9.5,
                'channel' => 'gsm_playback',
                'alsa_control' => 'GSM playback',
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
