<?php
declare(strict_types=1);

namespace Game\Helper;

/**
 * Standardized JSON API responses. Sends and exits.
 * Shape: { success: bool, message?: string, data?: mixed }
 */
final class ApiResponse
{
    public static function success($data = null, ?string $message = null, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => true];
        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        echo json_encode($payload);
        exit;
    }

    /**
     * @param array<string, mixed>|null $data Optional extra payload (e.g. cooldown_remaining)
     */
    public static function error(string $message, int $httpCode = 400, ?array $data = null): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => false, 'message' => $message];
        if ($data !== null && $data !== []) {
            $payload['data'] = $data;
        }
        echo json_encode($payload);
        exit;
    }
}
