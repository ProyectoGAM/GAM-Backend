<?php

return [
    'default' => env('REVERB_APP_KEY') && env('REVERB_APP_SECRET') && env('REVERB_APP_ID')
        ? env('BROADCAST_CONNECTION', 'null')
        : 'null',

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ],
        'null' => ['driver' => 'null'],
    ],
];
