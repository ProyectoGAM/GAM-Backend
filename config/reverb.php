<?php

$origins = explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'localhost,127.0.0.1'));

return [
    'apps' => [
        'provider' => 'config',
        'apps' => env('REVERB_APP_KEY') && env('REVERB_APP_SECRET') && env('REVERB_APP_ID') ? [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'allowed_origins' => array_values(array_filter(array_map('trim', $origins))),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10000,
                'accept_client_events_from' => 'none',
            ],
        ] : [],
    ],
];
