<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // Private files (IDs, photos, documents). storage_path() follows
        // LARAVEL_STORAGE_PATH when set (Azure: /home/custodicore/storage).
        // Files are only ever streamed by authorized controllers, so
        // Laravel's signed /storage/{path} routes are not registered.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
