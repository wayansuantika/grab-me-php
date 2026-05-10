<?php

declare(strict_types=1);

require __DIR__ . '/Core/helpers.php';

load_env_file(base_path('.env'));

date_default_timezone_set((string) config('app.timezone', 'Asia/Makassar'));

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = base_path('app/' . str_replace('\\', '/', $relative) . '.php');

    if (is_file($file)) {
        require $file;
    }
});

session_name((string) config('session.name', 'GRABMASSESSID'));
session_set_cookie_params([
    'lifetime' => (int) config('session.lifetime', 7200),
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
