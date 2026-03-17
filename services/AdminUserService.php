<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Heavenly Dao Administration - Cultivator management.

 * Enables celestial overseers to issue warnings and ban cultivators who stray from the path.
 */
class AdminUserService
{
    public function getUsersForAdmin(?string $search = null, int $limit = 100): array
    {
        try {
            $db = Database::getConnection();
            $sql = "
                SELECT u.id, u.username, u.email, u.created_at, u.last_login_at, u.level, u.realm_id, u.rating, u.wins, u.losses,
                       u.is_banned, u.ban_reason, u.banned_at, r.name AS realm_name
                FROM users u
                LEFT JOIN realms r ON r.id = u.realm_id
            ";
            $params = [];
            if ($search !== null && $search !== '') {
                $sql .= " WHERE u.username LIKE ? OR u.email LIKE ?";
                $term = '%' . $search . '%';
                $params = [$term, $term];
            }
            $sql .= " ORDER BY u.created_at DESC LIMIT " . max(1, min(500, $limit));

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('AdminUserService::getUsersForAdmin ' . $e->getMessage());
            return [];
        }
    }

    public function getUserWithWarnings(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT u.id, u.username, u.email, u.created_at, u.last_login_at, u.level, u.realm_id, u.rating, u.wins, u.losses,
                       u.is_banned, u.ban_reason, u.banned_at, u.banned_by, r.name AS realm_name
                FROM users u
                LEFT JOIN realms r ON r.id = u.realm_id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return null;
            }

            $stmt = $db->prepare("
                SELECT w.*, a.username AS admin_username
                FROM user_warnings w
                LEFT JOIN users a ON a.id = w.admin_user_id
                WHERE w.user_id = ?
                ORDER BY w.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $user['warnings'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $user;
        } catch (PDOException $e) {
            error_log('AdminUserService::getUserWithWarnings ' . $e->getMessage());
            return null;
        }
    }

    public function issueWarning(int $adminUserId, int $targetUserId, string $message): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) < 3) {
            return ['success' => false, 'message' => 'The Heavenly Dao requires a meaningful warning.'];
        }
        if ($targetUserId === $adminUserId) {
            return ['success' => false, 'message' => 'The Heavenly Dao cannot warn itself.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, is_admin FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$targetUserId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                return ['success' => false, 'message' => 'Cultivator not found in the registry.'];
            }
            if (!empty($target['is_admin'])) {
                return ['success' => false, 'message' => 'The Heavenly Dao does not warn fellow overseers.'];
            }

            $stmt = $db->prepare('INSERT INTO user_warnings (user_id, message, admin_user_id) VALUES (?, ?, ?)');
            $stmt->execute([$targetUserId, $message, $adminUserId]);

            $notificationService = new NotificationService();
            $notificationService->createNotification(
                $targetUserId,
                'heavenly_warning',
                'Heavenly Dao Admonition',
                'The Heavenly Dao has issued a warning: ' . $message,
                null,
                null
            );

            return ['success' => true, 'message' => 'The Heavenly Dao has inscribed the warning upon the cultivator\'s record.'];
        } catch (PDOException $e) {
            error_log('AdminUserService::issueWarning ' . $e->getMessage());
            return ['success' => false, 'message' => 'The celestial registry could not record the warning.'];
        }
    }

    public function banUser(int $adminUserId, int $targetUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) < 5) {
            return ['success' => false, 'message' => 'The Heavenly Dao requires a clear reason for banishing a cultivator.'];
        }
        if ($targetUserId === $adminUserId) {
            return ['success' => false, 'message' => 'The Heavenly Dao cannot banish itself.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, is_admin, username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$targetUserId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                return ['success' => false, 'message' => 'Cultivator not found in the registry.'];
            }
            if (!empty($target['is_admin'])) {
                return ['success' => false, 'message' => 'The Heavenly Dao does not banish fellow overseers.'];
            }

            $stmt = $db->prepare('UPDATE users SET is_banned = 1, ban_reason = ?, banned_at = NOW(), banned_by = ? WHERE id = ?');
            $stmt->execute([$reason, $adminUserId, $targetUserId]);

            $notificationService = new NotificationService();
            $notificationService->createNotification(
                $targetUserId,
                'heavenly_ban',
                'Heavenly Dao Decree',
                'The Heavenly Dao has banished you from the realm. Reason: ' . $reason,
                null,
                null
            );

            return ['success' => true, 'message' => 'The cultivator has been banished from the realm.'];
        } catch (PDOException $e) {
            error_log('AdminUserService::banUser ' . $e->getMessage());
            return ['success' => false, 'message' => 'The celestial registry could not record the banishment.'];
        }
    }

    public function unbanUser(int $adminUserId, int $targetUserId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('UPDATE users SET is_banned = 0, ban_reason = NULL, banned_at = NULL, banned_by = NULL WHERE id = ?');
            $stmt->execute([$targetUserId]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Cultivator not found or already unbanished.'];
            }
            return ['success' => true, 'message' => 'The cultivator has been welcomed back to the realm.'];
        } catch (PDOException $e) {
            error_log('AdminUserService::unbanUser ' . $e->getMessage());
            return ['success' => false, 'message' => 'The celestial registry could not lift the banishment.'];
        }
    }
}
