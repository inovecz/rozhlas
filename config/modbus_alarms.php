<?php

declare(strict_types=1);

return [
    /*
     * Definition of available alarm interpretations for values exposed via the Modbus alarm buffer
     * (registers 0x3000 – 0x3009). The decoder evaluates the conditions sequentially and returns
     * the first matching definition.
     */
    'definitions' => [
        [
            'code' => 'battery_voltage_low',
            'label' => 'Slabá baterie',
            'category' => 'power',
            'severity' => 'critical',
            'conditions' => [
                [
                    'word' => 'battery_voltage_raw',
                    'operator' => 'lt',
                    'value' => 1150,
                ],
            ],
            'metrics' => [
                'battery_voltage_v' => [
                    'word' => 'battery_voltage_raw',
                    'scale' => 0.01,
                    'precision' => 2,
                ],
                'battery_current_a' => [
                    'word' => 'battery_current_raw',
                    'scale' => 0.01,
                    'precision' => 2,
                ],
            ],
            'message_tokens' => [
                '{alarm}' => 'Slabá baterie',
                '{voltage}' => '{battery_voltage_v}',
                '{current}' => '{battery_current_a}',
                '{category}' => 'power',
            ],
        ],
    ],

    /*
     * Alias map for the Modbus data words. Word 0 corresponds to register 0x3002, word 1 to 0x3003, etc.
     */
    'word_aliases' => [
        'power_status' => 0,
        'battery_status' => 1,
        'battery_voltage_raw' => 2,
        'battery_current_raw' => 3,
    ],

    /*
     * Scaling applied when the decoder falls back to raw interpretation.
     */
    'defaults' => [
        'voltage_scale' => 0.01,
        'current_scale' => 0.01,
    ],
];
