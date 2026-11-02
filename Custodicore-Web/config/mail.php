<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // Fail within seconds (instead of hanging the page) if the mail server cannot be reached.
            'timeout' => (int) env('MAIL_TIMEOUT', 15),
            // true = refuse to send unless the server switches to TLS (STARTTLS on port 587), so
            // links, codes and the password never cross the network in plain text. Off by default
            // for local test servers; set it in production (Azure Communication Services supports TLS).
            'require_tls' => (bool) env('MAIL_REQUIRE_TLS', false),
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@custodicore.local'),
        'name' => env('MAIL_FROM_NAME', 'CustodiCore'),
    ],
];
