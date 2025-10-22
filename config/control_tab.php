<?php

declare(strict_types=1);

return [
    /*
     * Default destinations used when Control Tab spouští vysílání.
     * Lze upravit podle konkrétní instalace.
     */
    'defaults' => [
        'route' => [],
        'locations' => [],
        'nests' => [],
        'options' => [
            'note' => 'Control Tab',
        ],
    ],

    /*
     * Mapování ID tlačítek na konkrétní akce.
     * Action může být:
     *  - start_stream (vyžaduje `source`, volitelně `locations`, `nests`, `route`, `options`)
     *  - stop_stream
     *  - trigger_jsvv_alarm (vyžaduje `button`)
     *  - stop_jsvv (ukončí aktuální poplach / vysílání)
     */
    'buttons' => [
        // Screen 2 – menu1
        1 => [
            'action' => 'start_stream',
            'source' => 'microphone',
        ],
        2 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 1, // Zkouška sirén
        ],
        3 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 5, // Chemická havárie
        ],
        4 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 2, // Všeobecná výstraha
        ],
        5 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 3, // Požární poplach
        ],
        6 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 4, // Zátopová vlna
        ],
        7 => [
            'action' => 'start_stream',
            'source' => 'microphone',
        ],
        8 => [
            'action' => 'stop_jsvv',
        ],
        13 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 2, // Spusť poplach JSVV – výchozí všeobecná výstraha
        ],
        14 => [
            'action' => 'unsupported',
        ],
        15 => [
            'action' => 'stop_stream',
        ],
        16 => [
            'action' => 'unsupported',
        ],
        17 => [
            'action' => 'trigger_jsvv_alarm',
            'button' => 6, // Radiační poplach
        ],
        18 => [
            'action' => 'stop_stream',
        ],

        // Screen 3 – playInfo / PrimeHlaseni
        9 => [
            'action' => 'start_stream',
            'source' => 'central_file',
        ],
        10 => [
            'action' => 'stop_stream',
        ],
        11 => [
            'action' => 'start_stream',
            'source' => 'microphone',
        ],
        12 => [
            'action' => 'stop_stream',
        ],
        19 => [
            'action' => 'unsupported',
        ],
        20 => [
            'action' => 'unsupported',
        ],

        // Globální / servisní
        100 => [
            'action' => 'unsupported',
        ],
    ],

    /*
     * Mapování textových polí na generující callbacky.
     * Dostupné hodnoty:
     *  - status_summary
     *  - running_duration
     *  - running_length
     *  - planned_duration
     *  - active_locations
     *  - active_playlist_item
     */
    'text_fields' => [
        1 => 'status_summary',
        2 => 'running_duration',
        3 => 'running_length',
        4 => 'planned_duration',
        5 => 'active_locations',
        6 => 'active_playlist_item',
    ],
];
