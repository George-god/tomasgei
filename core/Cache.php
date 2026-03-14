<?php
declare(strict_types=1);

namespace Game\Core;

final class Cache
{
    private const CACHE_DIR = 'cache';

    public static function remember(string $key, int $ttlSeconds, callable $resolver)
    {
        $path = self::pathForKey($key);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($payload) && isset($payload['expires_at']) && (int)$payload['expires_at'] >= time()) {
                return $payload['value'] ?? null;
            }
        }

        $value = $resolver();
        self::put($key, $value, $ttlSeconds);
        return $value;
    }

    public static function put(string $key, $value, int $ttlSeconds): void
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(self::pathForKey($key), json_encode([
            'cache_key' => $key,
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function forget(string $key): void
    {
        $path = self::pathForKey($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function forgetByPrefix(string $prefix): void
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($payload) && isset($payload['cache_key']) && str_starts_with((string)$payload['cache_key'], $prefix)) {
                @unlink($path);
            }
        }
    }

    private static function directory(): string
    {
        return APP_ROOT . DIRECTORY_SEPARATOR . self::CACHE_DIR;
    }

    private static function pathForKey(string $key): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }
}
