<?php
declare(strict_types=1);

namespace Game\Helper;

require_once __DIR__ . '/ApiResponse.php';

use Game\Config\Database;

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

    public static function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['is_admin'])) {
            return (bool)$_SESSION['is_admin'];
        }
        $userId = self::getUserId();
        if ($userId === null) {
            return false;
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $isAdmin = (bool)$stmt->fetchColumn();
            $_SESSION['is_admin'] = $isAdmin;
            return $isAdmin;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function requireAdmin(string $redirectUrl = 'game.php', string $loginUrl = 'login.php'): int
    {
        $userId = self::requireLoggedIn($loginUrl);
        if (!self::isAdmin()) {
            header('Location: ' . $redirectUrl);
            exit;
        }
        return $userId;
    }
}
