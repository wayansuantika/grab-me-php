<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => env('APP_ENV', 'production'),
        'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
        'url' => env('APP_URL', 'http://localhost/grabmas/public'),
        'name' => env('APP_NAME', 'GrabMas Spa'),
        'timezone' => env('APP_TIMEZONE', 'Asia/Makassar'),
    ],
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'grabme'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => env('SESSION_NAME', 'GRABMESSSESSID'),
        'lifetime' => (int) env('SESSION_LIFETIME', '7200'),
    ],
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],
];
