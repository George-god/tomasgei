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
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT is_banned FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && !empty($row['is_banned'])) {
                session_destroy();
                header('Location: ' . $loginUrl . '?banned=1');
                exit;
            }
        } catch (\Throwable $e) {
            // If column doesn't exist yet, ignore
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

    /**
     * Get current admin level: observer, executor, or overseer. Returns null if not admin.
     */
    public static function getAdminLevel(): ?string
    {
        if (!self::isAdmin()) {
            return null;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['admin_level']) && $_SESSION['admin_level'] !== '') {
            return (string)$_SESSION['admin_level'];
        }
        try {
            $db = Database::getConnection();
            $userId = self::getUserId();
            if ($userId === null) {
                return null;
            }
            $stmt = $db->prepare('SELECT admin_level FROM users WHERE id = ? AND is_admin = 1 LIMIT 1');
            $stmt->execute([$userId]);
            $level = $stmt->fetchColumn();
            $level = ($level !== false && $level !== null && $level !== '') ? (string)$level : 'overseer';
            $_SESSION['admin_level'] = $level;
            return $level;
        } catch (\Throwable $e) {
            return 'overseer';
        }
    }

    /**
     * Require admin with at least the given level (observer < executor < overseer).
     *
     * @param string $minLevel observer, executor, or overseer
     * @param string $redirectUrl Where to redirect if insufficient
     * @param string $loginUrl Login page URL
     * @return int Current user ID
     */
    public static function requireAdminLevel(string $minLevel, string $redirectUrl = 'game.php', string $loginUrl = 'login.php'): int
    {
        $userId = self::requireAdmin($redirectUrl, $loginUrl);
        $rank = ['observer' => 1, 'executor' => 2, 'overseer' => 3];
        $current = self::getAdminLevel() ?? 'observer';
        $requiredRank = $rank[$minLevel] ?? 0;
        $currentRank = $rank[$current] ?? 0;
        if ($currentRank < $requiredRank) {
            header('Location: ' . $redirectUrl);
            exit;
        }
        return $userId;
    }
}
