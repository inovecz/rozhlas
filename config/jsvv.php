<?php

declare(strict_types=1);

return [
    'sequence' => [
        // playback_mode:
        //  - local_stream: route audio through the central stream (current behaviour)
        //  - remote_trigger: only relay frames to JSVV field units
        'playback_mode' => env('JSVV_SEQUENCE_MODE', 'local_stream'),
        'local_gap_seconds' => (float) env('JSVV_LOCAL_GAP_SECONDS', 0.0),
        'default_durations' => [
            'verbal' => (float) env('JSVV_DEFAULT_VERBAL_SECONDS', 12.0),
            'siren' => (float) env('JSVV_DEFAULT_SIREN_SECONDS', 60.0),
            'fallback' => (float) env('JSVV_DEFAULT_FALLBACK_SECONDS', 10.0),
        ],
        'duration_cache_ttl' => (int) env('JSVV_DURATION_CACHE_SECONDS', 86400),
    ],
    'dedup' => [
        'cache_store' => env('JSVV_DEDUP_CACHE_STORE'),
        'ttl' => (int) env('JSVV_DEDUP_CACHE_TTL', 600),
    ],
    'remote_trigger' => [
        'dtrx' => [
            'enabled' => env('JSVV_REMOTE_DTRX_ENABLED', false),
            'register_base' => env('JSVV_REMOTE_DTRX_BASE'),
            'register_count' => (int) env('JSVV_REMOTE_DTRX_COUNT', 24),
            'register_stride' => (int) env('JSVV_REMOTE_DTRX_STRIDE', 1),
            'clear_value' => (int) env('JSVV_REMOTE_DTRX_CLEAR_VALUE', 0),
            'reset_after' => env('JSVV_REMOTE_DTRX_RESET_AFTER', true),
            'command_register_start' => (int) env('JSVV_REMOTE_DTRX_COMMAND_START', 11),
            'command_register_count' => (int) env('JSVV_REMOTE_DTRX_COMMAND_COUNT', 4),
            'priority_register' => (int) env('JSVV_REMOTE_DTRX_PRIORITY_REGISTER', 10),
            'priority_clear_value' => (int) env('JSVV_REMOTE_DTRX_PRIORITY_CLEAR', 0),
        ],
    ],
];
