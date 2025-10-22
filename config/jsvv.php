<?php

declare(strict_types=1);

return [
    'sequence' => [
        // playback_mode:
        //  - local_stream: route audio through the central stream (current behaviour)
        //  - remote_trigger: only relay frames to JSVV field units
        'playback_mode' => env('JSVV_SEQUENCE_MODE', 'local_stream'),
        'default_durations' => [
            'verbal' => (float) env('JSVV_DEFAULT_VERBAL_SECONDS', 12.0),
            'siren' => (float) env('JSVV_DEFAULT_SIREN_SECONDS', 60.0),
            'fallback' => (float) env('JSVV_DEFAULT_FALLBACK_SECONDS', 10.0),
        ],
        'duration_cache_ttl' => (int) env('JSVV_DURATION_CACHE_SECONDS', 86400),
    ],
];
