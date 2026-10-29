<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailer bawaan
    |--------------------------------------------------------------------------
    |
    | Default "log": email TIDAK benar-benar dikirim, melainkan ditulis ke
    | storage/logs/laravel.log -- berguna untuk uji coba tanpa SMTP. Ganti ke
    | "smtp" (lewat MAIL_MAILER di .env) untuk pengiriman email sesungguhnya.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Alamat pengirim global
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@sigap-jpl.batimulia.com'),
        'name' => env('MAIL_FROM_NAME', 'SIGAP-JPL'),
    ],

];
