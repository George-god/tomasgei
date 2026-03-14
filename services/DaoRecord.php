<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Centralized Heavenly Dao record logging.
 */
final class DaoRecord
{
    public static function log(
        string $eventType,
        int $userId,
        ?int $targetId,
        string $description,
        array $contextData = [],
        ?PDO $db = null
    ): bool {
        if ($eventType === '' || $userId <= 0 || trim($description) === '') {
            return false;
        }

        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->prepare('
                INSERT INTO dao_records (event_type, user_id, target_id, description, context_data)
                VALUES (?, ?, ?, ?, ?)
            ');
            $json = $contextData !== [] ? json_encode($contextData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            if ($json === false) {
                $json = null;
            }
            $stmt->execute([
                $eventType,
                $userId,
                $targetId,
                trim($description),
                $json,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('DaoRecord::log ' . $e->getMessage());
            return false;
        }
    }

    public static function getRecords(array $filters = [], int $limit = 200): array
    {
        try {
            $db = Database::getConnection();
            $limit = max(1, min(500, $limit));
            $sql = '
                SELECT dr.*, u.username
                FROM dao_records dr
                JOIN users u ON u.id = dr.user_id
                WHERE 1=1
            ';
            $params = [];

            if (!empty($filters['event_type'])) {
                $sql .= ' AND dr.event_type = ?';
                $params[] = (string)$filters['event_type'];
            }
            if (!empty($filters['user_id'])) {
                $sql .= ' AND dr.user_id = ?';
                $params[] = (int)$filters['user_id'];
            }

            $sql .= ' ORDER BY dr.created_at DESC, dr.id DESC LIMIT ' . $limit;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('DaoRecord::getRecords ' . $e->getMessage());
            return [];
        }
    }

    public static function getRecordsForUser(int $userId, int $limit = 100): array
    {
        return self::getRecords(['user_id' => $userId], $limit);
    }

    public static function getEventTypes(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query('SELECT DISTINCT event_type FROM dao_records ORDER BY event_type ASC');
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (PDOException $e) {
            error_log('DaoRecord::getEventTypes ' . $e->getMessage());
            return [];
        }
    }
}
