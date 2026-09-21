<?php

return [
    'url' => env('NORIA_MAIL_URL', 'http://localhost:3000'),

    'key' => env('NORIA_MAIL_KEY', ''),

    'timeout' => (int) env('NORIA_MAIL_TIMEOUT', 15),

    'retries' => (int) env('NORIA_MAIL_RETRIES', 2),

    'webhook_secret' => env('NORIA_MAIL_WEBHOOK_SECRET', ''),

    'webhook_tolerance' => (int) env('NORIA_MAIL_WEBHOOK_TOLERANCE', 300),
];
