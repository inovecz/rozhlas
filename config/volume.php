<?php

declare(strict_types=1);

$mixerCard = env('AUDIO_MIXER_CARD', env('BROADCAST_MIXER_CARD', '2'));
$volumeCommandParts = ['amixer'];
if ($mixerCard !== null && $mixerCard !== '') {
    $volumeCommandParts[] = '-c';
    $volumeCommandParts[] = $mixerCard;
}
$volumeCommandParts[] = 'sset "{{alsa_control}}" {{value_percent}}%';
$volumeCommandTemplate = implode(' ', $volumeCommandParts);

return [
    /*
     * Template used when sending volume commands to ALSA mixer.
     * The placeholder {{alsa_control}} resolves to the target control name,
     * {{value}} to the numeric level, and {{value_percent}} to the integer percentage.
     */
    'command_template' => $volumeCommandTemplate,

    'inputs' => [
        'label' => 'Hlasitost vstupů',
        'items' => [
            'mic_capture' => [
                'label' => 'Mikrofon (Capture)',
                'default' => 50.0,
                'channel' => 'mic_capture',
                'alsa_control' => 'PGA',
            ],
            'fm_capture' => [
                'label' => 'FM vstup (Capture)',
                'default' => 50.0,
                'channel' => 'fm_capture',
                'alsa_control' => 'Line Line2 Bypass',
            ],
            'system_capture' => [
                'label' => 'Systémový zvuk (Capture)',
                'default' => 50.0,
                'channel' => 'system_capture',
                'alsa_control' => 'Line Line2 Bypass',
            ],
        ],
    ],

    'outputs' => [
        'label' => 'Hlasitost výstupů',
        'items' => [
            'playback_level' => [
                'label' => 'Výstup (DAC Playback)',
                'default' => 50.0,
                'channel' => 'playback_level',
                'alsa_control' => 'Line',
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
                'alsa_control' => 'PCM',
            ],
            'gsm_playback' => [
                'label' => 'GSM modul (Playback)',
                'default' => 50.0,
                'channel' => 'gsm_playback',
                'alsa_control' => 'PCM',
            ],
        ],
    ],

    'source_channels' => [
        'microphone' => 'mic_capture',
        'fm_radio' => 'fm_capture',
        'system_audio' => 'system_capture',
        'pc_webrtc' => 'system_capture',
        'central_file' => 'system_capture',
        'control_box' => 'mic_capture',
        'gsm' => 'system_capture',
        'jsvv_remote_voice' => 'system_capture',
        'jsvv_local_voice' => 'mic_capture',
        'jsvv_external_primary' => 'system_capture',
        'jsvv_external_secondary' => 'system_capture',
    ],

    'source_output_channels' => [
        'microphone' => 'playback_level',
        'fm_radio' => 'playback_level',
        'system_audio' => 'playback_level',
        'pc_webrtc' => 'playback_level',
        'central_file' => 'playback_level',
        'control_box' => 'playback_level',
        'gsm' => 'playback_level',
        'jsvv_remote_voice' => 'playback_level',
        'jsvv_local_voice' => 'playback_level',
        'jsvv_external_primary' => 'playback_level',
        'jsvv_external_secondary' => 'playback_level',
    ],
    'source_playback_channels' => [
        'central_file' => 'file_playback',
        'gsm' => 'gsm_playback',
    ],
];
