<?php
declare(strict_types=1);

namespace Game\Helper;

/**
 * Standardized JSON API responses. Sends and exits.
 * Shape: { success: bool, message?: string, data?: mixed }
 */
final class ApiResponse
{
    public static function respond(array $payload, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, ?string $message = null, int $httpCode = 200): void
    {
        $payload = ['success' => true];
        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::respond($payload, $httpCode);
    }

    /**
     * @param array<string, mixed>|null $data Optional extra payload (e.g. cooldown_remaining)
     */
    public static function error(string $message, int $httpCode = 400, ?array $data = null): void
    {
        $payload = ['success' => false, 'message' => $message];
        if ($data !== null && $data !== []) {
            $payload['data'] = $data;
        }
        self::respond($payload, $httpCode);
    }

    /**
     * Standardize controller responses from service arrays.
     *
     * @param array<string, mixed> $result
     */
    public static function fromServiceResult(array $result, int $successCode = 200, int $errorCode = 400): void
    {
        $ok = (bool)($result['success'] ?? false);
        $message = isset($result['message']) ? (string)$result['message'] : null;

        $data = null;
        if (array_key_exists('data', $result)) {
            $data = $result['data'];
        } else {
            $data = $result;
            unset($data['success'], $data['message'], $data['error']);
            if ($data === []) {
                $data = null;
            }
        }

        if ($ok) {
            self::success($data, $message, $successCode);
        }

        if ($data !== null && is_array($data)) {
            self::error($message ?? ((string)($result['error'] ?? 'Request failed.')), $errorCode, $data);
        }
        self::error($message ?? ((string)($result['error'] ?? 'Request failed.')), $errorCode);
    }
}
