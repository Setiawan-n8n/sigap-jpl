<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // PENTING: aplikasi ini punya 2 container (app + queue) yang menulis
            // ke SATU file SQLite yang sama lewat volume Docker bersama. Tanpa
            // busy_timeout, tulisan yang bentrok (mis. scheduler membuat
            // Video/LiveCaptureJob di container queue bersamaan dengan request
            // web di container app) langsung gagal dengan error "database is
            // locked" alih-alih menunggu sebentar -- ini diduga jadi penyebab
            // job deteksi live yang macet permanen di status pending/running.
            // WAL journal mode juga meningkatkan konkurensi baca/tulis SQLite.
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => Str::slug(env('APP_NAME', 'laravel'), '_').'_database_',
        ],
    ],
];