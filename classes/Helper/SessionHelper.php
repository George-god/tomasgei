<?php
declare(strict_types=1);

namespace Game\Helper;

/**
 * Session validation for Phase 1. Use requireLoggedIn() for HTML pages (redirect),
 * requireUserIdForApi() for JSON endpoints (401 + JSON).
 */
final class SessionHelper
{
    /**
     * Start session if not started; require valid user_id. For API endpoints only.
     * Exits with 401 JSON if not authenticated.
     *
     * @return int Current user ID
     */
    public static function requireUserIdForApi(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
            ApiResponse::error('Not authenticated.', 401);
        }
        return (int)$_SESSION['user_id'];
    }

    /**
     * Returns current user ID or null. Does not redirect or exit.
     */
    public static function getUserId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
            return null;
        }
        return (int)$_SESSION['user_id'];
    }

    /**
     * For HTML pages: redirect to login if not logged in. Call after session_start().
     *
     * @param string $loginUrl Default 'login.php'
     * @return int Current user ID (never returns if not logged in)
     */
    public static function requireLoggedIn(string $loginUrl = 'login.php'): int
    {
        $id = self::getUserId();
        if ($id === null) {
            header('Location: ' . $loginUrl);
            exit;
        }
        return $id;
    }
}
