<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Notification service for user notifications
 */
class NotificationService
{
    /**
     * Create a notification
     * 
     * @param int $userId User ID
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $message Notification message
     * @param int|null $relatedId Related entity ID
     * @param string|null $relatedType Related entity type
     * @return bool Success status
     */
    public function createNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?int $relatedId = null,
        ?string $relatedType = null
    ): bool {
        try {
            $db = Database::getConnection();
            $sql = "INSERT INTO notifications (user_id, type, title, message, related_id, related_type) 
                    VALUES (:user_id, :type, :title, :message, :related_id, :related_type)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':type' => $type,
                ':title' => $title,
                ':message' => $message,
                ':related_id' => $relatedId,
                ':related_type' => $relatedType
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("Notification creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notifications for a user
     * 
     * @param int $userId User ID
     * @param bool $unreadOnly Only get unread notifications
     * @param int $limit Maximum number of notifications
     * @return array List of notifications
     */
    public function getNotifications(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM notifications 
                    WHERE user_id = ?";
            
            if ($unreadOnly) {
                $sql .= " AND is_read = 0";
            }
            
            $limit = max(1, min(500, (int)$limit));
            $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to fetch notifications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark notification as read
     * 
     * @param int $notificationId Notification ID
     * @param int $userId User ID (for security)
     * @return bool Success status
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 
                                 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notificationId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Failed to mark notification as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all notifications as read for a user
     * 
     * @param int $userId User ID
     * @return bool Success status
     */
    public function markAllAsRead(int $userId): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$userId]);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to mark all notifications as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread notification count
     * 
     * @param int $userId User ID
     * @return int Unread count
     */
    public function getUnreadCount(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications 
                                 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Failed to get unread count: " . $e->getMessage());
            return 0;
        }
    }
}
