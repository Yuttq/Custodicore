<?php

use Illuminate\Support\Facades\Facade;

return [
    'name' => env('APP_NAME', 'CustodiCore'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    ],
    // Password for every DatabaseSeeder demo account. Only local/testing fall
    // back to "password"; every other environment must set it.
    'seed_password' => env('SEED_PASSWORD'),
    // Production seeds reference data only; true also adds the demo accounts
    // and records (a demo server, never a real facility).
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),
    // Strict-Transport-Security max-age in seconds; 0 = off. Sent on HTTPS
    // requests only (App\Http\Middleware\SecurityHeaders).
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 0),
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
