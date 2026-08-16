<?php

return [
    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],
];
