<?php

declare(strict_types=1);

return [
    'gosms' => [
        'client_id' => env('SMS_GOSMS_CLIENT_ID', env('SMS_GOSMS_USERNAME')),
        'client_secret' => env('SMS_GOSMS_CLIENT_SECRET', env('SMS_GOSMS_PASSWORD')),
        'channel' => (int) env('SMS_GOSMS_CHANNEL', 6),
        'sender' => env('SMS_GOSMS_SENDER'),
        // Legacy keys kept for backward compatibility; prefer client_id/client_secret.
        'username' => env('SMS_GOSMS_USERNAME'),
        'password' => env('SMS_GOSMS_PASSWORD'),
    ],
];
