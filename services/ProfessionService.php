<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Professions: 1 main + 1 secondary per user. Main = 100% effect, secondary = 50% effect.
 */
class ProfessionService
{
    public function getProfessions(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT id, name, description FROM professions ORDER BY id");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("ProfessionService::getProfessions " . $e->getMessage());
            return [];
        }
    }

    /**
     * User's main profession (role='main') or null.
     */
    public function getMainProfession(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT up.user_id, up.profession_id, up.level, up.experience, up.role,
                       p.name AS profession_name, p.description AS profession_description
                FROM user_professions up
                JOIN professions p ON p.id = up.profession_id
                WHERE up.user_id = ? AND up.role = 'main'
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("ProfessionService::getMainProfession " . $e->getMessage());
            return null;
        }
    }

    /**
     * User's secondary profession (role='secondary') or null.
     */
    public function getSecondaryProfession(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT up.user_id, up.profession_id, up.level, up.experience, up.role,
                       p.name AS profession_name, p.description AS profession_description
                FROM user_professions up
                JOIN professions p ON p.id = up.profession_id
                WHERE up.user_id = ? AND up.role = 'secondary'
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("ProfessionService::getSecondaryProfession " . $e->getMessage());
            return null;
        }
    }

    /**
     * Backward compat: same as getMainProfession (for pages that check "main" only).
     */
    public function getUserMainProfession(int $userId): ?array
    {
        return $this->getMainProfession($userId);
    }

    /**
     * Get user's profession row by profession id (main or secondary).
     */
    public function getUserProfession(int $userId, int $professionId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT up.user_id, up.profession_id, up.level, up.experience, up.role,
                       p.name AS profession_name
                FROM user_professions up
                JOIN professions p ON p.id = up.profession_id
                WHERE up.user_id = ? AND up.profession_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $professionId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("ProfessionService::getUserProfession " . $e->getMessage());
            return null;
        }
    }

    /**
     * Effective level for bonuses: main = 100%, secondary = 50%.
     */
    public static function getEffectiveLevel(int $level, string $role): int
    {
        if ($role === 'main') {
            return $level;
        }
        return (int)floor($level * 0.5);
    }

    /**
     * Set main profession. Only one main; others cleared from main.
     */
    public function setMainProfession(int $userId, int $professionId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM professions WHERE id = ?");
            $stmt->execute([$professionId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'Profession not found.'];
            }
            $db->beginTransaction();
            $db->prepare("UPDATE user_professions SET role = 'secondary' WHERE user_id = ? AND role = 'main'")->execute([$userId]);
            $stmt = $db->prepare("SELECT 1 FROM user_professions WHERE user_id = ? AND profession_id = ?");
            $stmt->execute([$userId, $professionId]);
            if ($stmt->fetch()) {
                $db->prepare("UPDATE user_professions SET role = 'main' WHERE user_id = ? AND profession_id = ?")->execute([$userId, $professionId]);
            } else {
                $db->prepare("INSERT INTO user_professions (user_id, profession_id, level, experience, role) VALUES (?, ?, 1, 0, 'main')")->execute([$userId, $professionId]);
            }
            $db->commit();
            return ['success' => true, 'message' => 'Main profession set.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("ProfessionService::setMainProfession " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Set secondary profession. Only one secondary; if new one, old secondary is removed.
     */
    public function setSecondaryProfession(int $userId, int $professionId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM professions WHERE id = ?");
            $stmt->execute([$professionId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'Profession not found.'];
            }
            $main = $this->getMainProfession($userId);
            if ($main && (int)$main['profession_id'] === $professionId) {
                return ['success' => false, 'message' => 'Cannot set main profession as secondary. Choose a different profession.'];
            }
            $db->beginTransaction();
            $db->prepare("DELETE FROM user_professions WHERE user_id = ? AND role = 'secondary'")->execute([$userId]);
            $db->prepare("INSERT INTO user_professions (user_id, profession_id, level, experience, role) VALUES (?, ?, 1, 0, 'secondary')")->execute([$userId, $professionId]);
            $db->commit();
            return ['success' => true, 'message' => 'Secondary profession set.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("ProfessionService::setSecondaryProfession " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Clear secondary profession (only one secondary allowed).
     */
    public function clearSecondary(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("DELETE FROM user_professions WHERE user_id = ? AND role = 'secondary'")->execute([$userId]);
        } catch (PDOException $e) {
            error_log("ProfessionService::clearSecondary " . $e->getMessage());
        }
    }

    /**
     * Legacy: set profession as main (for choose_profession flow).
     */
    public function chooseProfession(int $userId, int $professionId): array
    {
        return $this->setMainProfession($userId, $professionId);
    }
}
