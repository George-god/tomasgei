<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Dao path unlocks and selection.
 */
class DaoPathService
{
    private const FOUNDATION_REALM_NAME = 'Foundation Building';

    public function getSelectionState(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $foundationRealm = $this->getFoundationRealm($db);
            $currentPath = $this->getCurrentPathForUser($userId, $db);
            $realmRequiredLevel = $this->getUserRealmRequiredLevel($userId, $db);

            return [
                'unlocked' => $foundationRealm !== null && $realmRequiredLevel >= (int)$foundationRealm['required_level'],
                'foundation_realm_name' => self::FOUNDATION_REALM_NAME,
                'current_path' => $currentPath,
                'paths' => $this->getAllPaths($db),
            ];
        } catch (PDOException $e) {
            error_log('DaoPathService::getSelectionState ' . $e->getMessage());
            return [
                'unlocked' => false,
                'foundation_realm_name' => self::FOUNDATION_REALM_NAME,
                'current_path' => null,
                'paths' => [],
            ];
        }
    }

    public function selectPath(int $userId, int $pathId): array
    {
        if ($pathId <= 0) {
            return ['success' => false, 'message' => 'Invalid Dao Path.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $foundationRealm = $this->getFoundationRealm($db);
            $realmRequiredLevel = $this->getUserRealmRequiredLevel($userId, $db, true);
            if ($foundationRealm === null || $realmRequiredLevel < (int)$foundationRealm['required_level']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Dao Paths unlock at Foundation Building.'];
            }

            $currentPath = $this->getCurrentPathForUser($userId, $db, true);
            if ($currentPath !== null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You have already chosen a Dao Path.'];
            }

            $path = $this->getPathById($pathId, $db);
            if ($path === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Dao Path not found.'];
            }

            $stmt = $db->prepare('UPDATE users SET dao_path_id = ? WHERE id = ?');
            $stmt->execute([$pathId, $userId]);
            $db->commit();

            return ['success' => true, 'message' => 'You have awakened the ' . (string)$path['name'] . '.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DaoPathService::selectPath ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not bind a Dao Path right now.'];
        }
    }

    public function getCurrentPathForUser(int $userId, ?PDO $db = null, bool $forUpdate = false): ?array
    {
        try {
            $db = $db ?? Database::getConnection();
            $sql = "
                SELECT d.*
                FROM users u
                LEFT JOIN dao_paths d ON d.id = u.dao_path_id
                WHERE u.id = ?
                LIMIT 1
            ";
            if ($forUpdate) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && !empty($row['id']) ? $row : null;
        } catch (PDOException $e) {
            error_log('DaoPathService::getCurrentPathForUser ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaths(?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->query("
                SELECT *
                FROM dao_paths
                ORDER BY FIELD(alignment, 'orthodox', 'demonic'), FIELD(element, 'flame', 'water', 'wind', 'earth'), id ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('DaoPathService::getAllPaths ' . $e->getMessage());
            return [];
        }
    }

    private function getPathById(int $pathId, PDO $db): ?array
    {
        $stmt = $db->prepare('SELECT * FROM dao_paths WHERE id = ? LIMIT 1');
        $stmt->execute([$pathId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getFoundationRealm(PDO $db): ?array
    {
        $requiredColumn = $this->getRequiredLevelColumn($db);
        $stmt = $db->prepare("SELECT id, {$requiredColumn} AS required_level FROM realms WHERE name = ? LIMIT 1");
        $stmt->execute([self::FOUNDATION_REALM_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getUserRealmRequiredLevel(int $userId, PDO $db, bool $forUpdate = false): int
    {
        $requiredColumn = $this->getRequiredLevelColumn($db);
        $sql = "
            SELECT r.{$requiredColumn} AS required_level
            FROM users u
            JOIN realms r ON r.id = u.realm_id
            WHERE u.id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 1);
    }

    private function getRequiredLevelColumn(PDO $db): string
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM realms LIKE 'required_level'");
            return $stmt->fetch() ? 'required_level' : 'min_level';
        } catch (\Throwable $e) {
            return 'min_level';
        }
    }
}
