<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Realm service - Phase 1.
 * Simple realm tiers. Unlock next realm at specific level. No tribulation.
 */
class RealmService
{
    /** Column for unlock check: required_level if present, else min_level */
    private function getRequiredLevelColumn(): string
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SHOW COLUMNS FROM realms LIKE 'required_level'");
            return $stmt->fetch() ? 'required_level' : 'min_level';
        } catch (\Throwable $e) {
            return 'min_level';
        }
    }

    /**
     * Get realms available for a user's level (required_level <= userLevel).
     *
     * @param int $userLevel User's current level
     * @return array List of realm rows (id, name, required_level/min_level, max_level)
     */
    public function getRealmsForLevel(int $userLevel): array
    {
        try {
            $db = Database::getConnection();
            $col = $this->getRequiredLevelColumn();
            $stmt = $db->prepare("SELECT id, name, min_level, max_level, $col as required_level FROM realms WHERE $col <= ? ORDER BY $col ASC");
            $stmt->execute([$userLevel]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("RealmService::getRealmsForLevel " . $e->getMessage());
            return [];
        }
    }

    /**
     * Set user's realm. Fails if user level is below realm's required_level (or min_level).
     *
     * @param int $userId User ID
     * @param int $realmId Realm ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function setUserRealm(int $userId, int $realmId): array
    {
        try {
            $db = Database::getConnection();
            $userStmt = $db->prepare("SELECT level FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }
            $col = $this->getRequiredLevelColumn();
            $realmStmt = $db->prepare("SELECT id, min_level, $col as required_level FROM realms WHERE id = ? LIMIT 1");
            $realmStmt->execute([$realmId]);
            $realm = $realmStmt->fetch();
            if (!$realm) {
                return ['success' => false, 'message' => 'Realm not found.'];
            }
            $userLevel = (int)$user['level'];
            $required = (int)($realm['required_level'] ?? $realm['min_level'] ?? 1);
            if ($userLevel < $required) {
                return ['success' => false, 'message' => "Reach level {$required} to unlock this realm."];
            }
            $db->prepare("UPDATE users SET realm_id = ? WHERE id = ?")->execute([$realmId, $userId]);
            return ['success' => true, 'message' => 'Realm updated.'];
        } catch (PDOException $e) {
            error_log("RealmService::setUserRealm " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Check if user can breakthrough to next realm (level >= next realm's required_level).
     * Does NOT auto-upgrade; used to show "Breakthrough Available" notice.
     *
     * @return array{available: bool, next_realm: array{id: int, name: string, required_level: int}|null}
     */
    public function getBreakthroughAvailable(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $col = $this->getRequiredLevelColumn();
            $userStmt = $db->prepare("SELECT level, realm_id FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            if (!$user) {
                return ['available' => false, 'next_realm' => null];
            }
            $userLevel = (int)$user['level'];
            $currentRealmId = (int)$user['realm_id'];
            $currentStmt = $db->prepare("SELECT $col as required_level FROM realms WHERE id = ? LIMIT 1");
            $currentStmt->execute([$currentRealmId]);
            $currentRealm = $currentStmt->fetch();
            $currentRequired = $currentRealm ? (int)($currentRealm['required_level'] ?? 0) : 0;
            $nextStmt = $db->prepare("SELECT id, name, $col as required_level FROM realms WHERE $col > ? ORDER BY $col ASC LIMIT 1");
            $nextStmt->execute([$currentRequired]);
            $nextRealm = $nextStmt->fetch();
            if (!$nextRealm || $userLevel < (int)$nextRealm['required_level']) {
                return ['available' => false, 'next_realm' => null];
            }
            return [
                'available' => true,
                'next_realm' => [
                    'id' => (int)$nextRealm['id'],
                    'name' => (string)$nextRealm['name'],
                    'required_level' => (int)$nextRealm['required_level']
                ]
            ];
        } catch (\Throwable $e) {
            error_log("RealmService::getBreakthroughAvailable " . $e->getMessage());
            return ['available' => false, 'next_realm' => null];
        }
    }

    /**
     * Get realm by ID.
     */
    public function getRealmById(int $realmId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM realms WHERE id = ? LIMIT 1");
            $stmt->execute([$realmId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
