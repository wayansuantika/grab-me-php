<?php

declare(strict_types=1);

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__, 2);
    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function load_env_file(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null ? $default : (string) $value;
}

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require base_path('app/Config/config.php');
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function app_log(string $message, string $level = 'INFO'): void
{
    $line = sprintf("[%s] [%s] %s\n", date('c'), $level, $message);
    file_put_contents(base_path('storage/logs/app.log'), $line, FILE_APPEND);
}

/**
 * Log an error with full context
 */
function app_error(string $message, ?\Throwable $exception = null): void
{
    $context = $message;
    if ($exception) {
        $context .= " | " . $exception::class . ": " . $exception->getMessage();
        $context .= " | File: " . $exception->getFile() . ":" . $exception->getLine();
    }
    app_log($context, 'ERROR');
}

/**
 * Get pagination from query parameters
 */
function get_pagination(int $total, int $defaultPerPage = 50): \App\Core\Pagination
{
    return \App\Core\Pagination::fromRequest($total, $defaultPerPage);
}

/**
 * Sanitize string input
 */
function sanitize_string(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize array input recursively
 */
function sanitize_array(array $input): array
{
    return array_map(function ($item) {
        if (is_array($item)) {
            return sanitize_array($item);
        }
        return is_string($item) ? sanitize_string($item) : $item;
    }, $input);
}

/**
 * Get IP address of request
 */
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    return trim($ip);
}

/**
 * Rate limit helper - check if IP has exceeded requests
 */
function is_rate_limited(string $identifier, int $maxRequests = 100, int $windowSeconds = 3600): bool
{
    $cacheKey = "rate_limit_{$identifier}";
    $file = base_path("storage/rate_limits/{$identifier}.json");
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $now = time();
    $data = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true) ?? [];
    }

    // Clean old entries
    $data['timestamps'] = array_filter($data['timestamps'] ?? [], fn($t) => $t > $now - $windowSeconds);

    // Check limit
    if (count($data['timestamps']) >= $maxRequests) {
        return true;
    }

    // Add current request
    $data['timestamps'][] = $now;
    file_put_contents($file, json_encode($data));

    return false;
}

/**
 * Format currency for display
 */
function format_currency(float $amount, string $currency = 'IDR'): string
{
    $formatter = new \NumberFormatter('id_ID', \NumberFormatter::CURRENCY);
    return $formatter->formatCurrency($amount, $currency);
}

/**
 * Check if string is valid JSON
 */
function is_json(string $string): bool
{
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Get array value safely with default
 */
function array_get(array $array, string $key, mixed $default = null): mixed
{
    $segments = explode('.', $key);
    $value = $array;

    foreach ($segments as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
        } else {
            return $default;
        }
    }

    return $value;
}

/**
 * Format phone number
 */
function format_phone(string $phone): string
{
    $cleaned = preg_replace('/[^0-9+]/', '', $phone);
    
    if (str_starts_with($cleaned, '0')) {
        $cleaned = '+62' . substr($cleaned, 1);
    } elseif (!str_starts_with($cleaned, '+')) {
        $cleaned = '+62' . $cleaned;
    }
    
    return $cleaned;
}

/**
 * Parse boolean from string
 */
function parse_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
    return (bool) $value;
}
