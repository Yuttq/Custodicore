<?php

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'accounts'),
    ],

    'guards' => [
        // The three staff dashboards (Warden/Admin, Record Officer, Front
        // Desk Officer) — session-based, same as before.
        'web' => [
            'driver' => 'session',
            'provider' => 'accounts',
        ],

        // The mobile app — bearer-token auth via Sanctum. Same underlying
        // `accounts` table/model as 'web' above, just a different guard
        // driver, so a visitor logging in from the app is the same kind of
        // row as a staff member logging into a dashboard.
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'accounts',
        ],
    ],

    'providers' => [
        // Real login for every role now lives in `accounts`, not Laravel's
        // default `users` table.
        'accounts' => [
            'driver' => 'eloquent',
            'model' => App\Models\Account::class,
        ],

        // Kept only so Laravel's stock scaffold (App\Models\User, its
        // factory/migration) still resolves if anything references it.
        // Nothing in this app logs in against this provider.
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'accounts' => [
            'provider' => 'accounts',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
