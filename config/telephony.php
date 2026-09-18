<?php

return [
    'driver' => env('TELEPHONY_DRIVER', 'demo'),
    'live_enabled' => (bool) env('TELEPHONY_LIVE_ENABLED', false),
    'exotel' => [
        'host' => env('EXOTEL_HOST', 'api.in.exotel.com'),
        'account_sid' => env('EXOTEL_ACCOUNT_SID'),
        'api_key' => env('EXOTEL_API_KEY'),
        'api_token' => env('EXOTEL_API_TOKEN'),
        'caller_id' => env('EXOTEL_CALLER_ID'),
        'callback_base_url' => env('EXOTEL_CALLBACK_BASE_URL', env('APP_URL')),
        'timezone' => env('EXOTEL_TIMEZONE', 'Asia/Kolkata'),
    ],
];
