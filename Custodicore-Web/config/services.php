<?php

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Text messages (staff account setup link). 'log' only writes to the
    // Laravel log — set SMS_DRIVER=semaphore and SEMAPHORE_API_KEY to send
    // real SMS through semaphore.co. See App\Services\SmsSender.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'semaphore' => [
            'key' => env('SEMAPHORE_API_KEY'),
            'sender' => env('SEMAPHORE_SENDER_NAME'),
        ],
    ],

    // Google Sign-In (mobile visitors). Comma-separated OAuth client IDs a
    // Google ID token's `aud` claim may match (e.g. the web client ID the
    // mobile app requests ID tokens for). Empty = Google Sign-In disabled.
    // Client IDs only — no client secret or service-account key is needed
    // to verify ID tokens. See App\Services\Auth\GoogleIdTokenVerifier.
    'google' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))
        ))),
    ],
];
