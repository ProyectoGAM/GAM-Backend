<?php

return [
    'personal_token_days' => (int) env('IDENTITY_PERSONAL_TOKEN_DAYS', 90),
    'personal_session_days' => (int) env('IDENTITY_PERSONAL_SESSION_DAYS', 90),
    'shared_device_days' => (int) env('IDENTITY_SHARED_DEVICE_DAYS', 365),
    'shared_session_hours' => (int) env('IDENTITY_SHARED_SESSION_HOURS', 8),
    'shared_idle_seconds' => (int) env('IDENTITY_SHARED_IDLE_SECONDS', 120),
    'pairing_minutes' => (int) env('IDENTITY_PAIRING_MINUTES', 10),
    'password_confirmation_minutes' => (int) env('IDENTITY_PASSWORD_CONFIRMATION_MINUTES', 10),
    'pin' => [
        'hash_driver' => env('IDENTITY_PIN_HASH_DRIVER', 'argon2id'),
        'pepper' => env('IDENTITY_PIN_PEPPER'),
        'pepper_version' => env('IDENTITY_PIN_PEPPER_VERSION', 'v1'),
        'window_minutes' => 15,
        'max_attempts' => 5,
        'daily_max_attempts' => 20,
    ],
];
