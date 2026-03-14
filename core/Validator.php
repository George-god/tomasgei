<?php
declare(strict_types=1);

namespace Game\Core;

require_once __DIR__ . '/ApiResponse.php';

final class Validator
{
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            \Game\Helper\ApiResponse::error('Method not allowed.', 405);
        }
    }

    public static function intParam(array $source, string $key, int $min = 0): int
    {
        $value = isset($source[$key]) ? (int)$source[$key] : 0;
        return max($min, $value);
    }

    public static function boolParam(array $source, string $key): bool
    {
        $value = $source[$key] ?? null;
        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    public static function stringParam(array $source, string $key, int $maxLen = 0): string
    {
        $value = trim((string)($source[$key] ?? ''));
        if ($maxLen > 0) {
            $value = mb_substr($value, 0, $maxLen);
        }
        return $value;
    }
}
