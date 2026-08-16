<?php

return [
    'detector' => [
        'url' => env('DETECTOR_SERVICE_URL', 'http://detector:8001'),
        'secret' => env('DETECTOR_CALLBACK_SECRET', 'change-me-secret'),
        'danger_dwell_seconds' => env('DANGER_DWELL_SECONDS', 5),
    ],
];
