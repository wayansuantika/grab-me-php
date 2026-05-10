<?php

declare(strict_types=1);

namespace App\Core;

class FileCache
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? base_path('storage/cache');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->read($key);
        if ($payload === null) {
            return $default;
        }

        return $payload['value'] ?? $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): mixed
    {
        $this->ensureDirectory();

        $payload = [
            'key' => $key,
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];

        file_put_contents($this->pathFor($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $value;
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = $this->read($key);
        if ($cached !== null) {
            return $cached['value'] ?? null;
        }

        return $this->put($key, $callback(), $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function forgetByPrefix(string $prefix): void
    {
        $this->ensureDirectory();

        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $content = file_get_contents($file);
            $payload = json_decode($content ?: '', true);

            if (!is_array($payload) || !isset($payload['key'])) {
                @unlink($file);
                continue;
            }

            if (str_starts_with((string) $payload['key'], $prefix)) {
                @unlink($file);
            }
        }
    }

    private function read(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $payload = json_decode($content ?: '', true);
        if (!is_array($payload) || !isset($payload['expires_at'])) {
            @unlink($path);
            return null;
        }

        if ((int) $payload['expires_at'] < time()) {
            @unlink($path);
            return null;
        }

        return $payload;
    }

    private function pathFor(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
}