<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 2.4 Sect system. Create, join, leave. Bonuses by tier. No territory, no wars, no bank.
 */
class SectService
{
    private const CREATE_COST_GOLD = 5000;
    private const MAX_ELDERS = 5;
    private const EXP_TIER_SECOND = 5000;
    private const EXP_TIER_FIRST = 15000;

    /** Third: +2% cultivation. Second: +3% cultivation, +2% gold. First: +5% cultivation, +3% gold, +3% breakthrough. */
    private const BONUSES = [
        'third'  => ['cultivation_speed' => 0.02, 'gold_gain' => 0.00, 'breakthrough' => 0.00],
        'second' => ['cultivation_speed' => 0.03, 'gold_gain' => 0.02, 'breakthrough' => 0.00],
        'first'  => ['cultivation_speed' => 0.05, 'gold_gain' => 0.03, 'breakthrough' => 0.03],
    ];

    /**
     * Get sect and membership for a user, or null if not in a sect.
     */
    public function getSectByUserId(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT s.id, s.name, s.leader_user_id, s.tier, s.sect_exp, s.created_at,
                       m.role
                FROM sect_members m
                JOIN sects s ON s.id = m.sect_id
                WHERE m.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $tier = (string)$row['tier'];
            $row['bonuses'] = self::BONUSES[$tier] ?? self::BONUSES['third'];
            $row['member_count'] = $this->getMemberCount($db, (int)$row['id']);
            return $row;
        } catch (PDOException $e) {
            error_log("SectService::getSectByUserId " . $e->getMessage());
            return null;
        }
    }

    private function getMemberCount(PDO $db, int $sectId): int
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM sect_members WHERE sect_id = ?");
        $stmt->execute([$sectId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get sect by id (for join page). Returns null if sect does not exist.
     */
    public function getSectById(int $sectId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, name, leader_user_id, tier, sect_exp, created_at FROM sects WHERE id = ?");
            $stmt->execute([$sectId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $row['member_count'] = $this->getMemberCount($db, (int)$row['id']);
            return $row;
        } catch (PDOException $e) {
            error_log("SectService::getSectById " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get member role for user: 'leader'|'elder'|'disciple' or null if not in a sect.
     */
    public function getMemberRole(int $userId): ?string
    {
        $sect = $this->getSectByUserId($userId);
        return $sect ? (string)$sect['role'] : null;
    }

    /**
     * Get bonus multipliers for a user (from their sect tier). Returns cultivation_speed, gold_gain, breakthrough (0 if no sect).
     */
    public function getBonusesForUser(int $userId): array
    {
        $sect = $this->getSectByUserId($userId);
        if (!$sect) {
            return ['cultivation_speed' => 0.0, 'gold_gain' => 0.0, 'breakthrough' => 0.0];
        }
        $tier = (string)($sect['tier'] ?? 'third');
        return self::BONUSES[$tier] ?? self::BONUSES['third'];
    }

    /**
     * Compute tier from sect_exp (for display or updates).
     */
    public static function tierFromExp(int $sectExp): string
    {
        if ($sectExp >= self::EXP_TIER_FIRST) {
            return 'first';
        }
        if ($sectExp >= self::EXP_TIER_SECOND) {
            return 'second';
        }
        return 'third';
    }

    /**
     * Create a new sect. Cost 5000 gold. Player must not be in a sect.
     */
    public function createSect(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            return ['success' => false, 'message' => 'Invalid sect name.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            if ($this->getSectByUserId($userId)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You are already in a sect.'];
            }

            $stmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $gold = (int)($stmt->fetch()['gold'] ?? 0);
            if ($gold < self::CREATE_COST_GOLD) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Need 5000 gold to create a sect.'];
            }

            $db->prepare("UPDATE users SET gold = GREATEST(0, gold - ?) WHERE id = ?")
                ->execute([self::CREATE_COST_GOLD, $userId]);

            $db->prepare("INSERT INTO sects (name, leader_user_id, tier, sect_exp) VALUES (?, ?, 'third', 0)")
                ->execute([$name, $userId]);
            $sectId = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO sect_members (sect_id, user_id, role) VALUES (?, ?, 'leader')")
                ->execute([$sectId, $userId]);

            $db->commit();
            return ['success' => true, 'message' => 'Sect created.', 'sect_id' => $sectId];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectService::createSect " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not create sect.'];
        }
    }

    /**
     * Join a sect as disciple. User must not be in a sect.
     */
    public function joinSect(int $userId, int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            if ($this->getSectByUserId($userId)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You are already in a sect.'];
            }

            $sect = $this->getSectById($sectId);
            if (!$sect) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Sect not found.'];
            }

            $db->prepare("INSERT INTO sect_members (sect_id, user_id, role) VALUES (?, ?, 'disciple')")
                ->execute([$sectId, $userId]);

            $db->commit();
            return ['success' => true, 'message' => 'You joined the sect.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectService::joinSect " . $e->getMessage());
            return ['success' => false, 'message' => $e->getCode() === '23000' ? 'Already in a sect.' : 'Could not join.'];
        }
    }

    /**
     * Leave sect. Leader must transfer leadership or disband first.
     */
    public function leaveSect(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $sect = $this->getSectByUserId($userId);
            if (!$sect) {
                return ['success' => false, 'message' => 'You are not in a sect.'];
            }

            $role = (string)$sect['role'];
            if ($role === 'leader') {
                return ['success' => false, 'message' => 'Transfer leadership or disband the sect first.'];
            }

            $db->prepare("DELETE FROM sect_members WHERE user_id = ?")->execute([$userId]);
            return ['success' => true, 'message' => 'You left the sect.'];
        } catch (PDOException $e) {
            error_log("SectService::leaveSect " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not leave.'];
        }
    }

    /**
     * Disband sect. Leader only. Deletes sect and all members.
     */
    public function disbandSect(int $userId): array
    {
        try {
            $sect = $this->getSectByUserId($userId);
            if (!$sect || (string)$sect['role'] !== 'leader') {
                return ['success' => false, 'message' => 'Only the sect leader can disband.'];
            }
            $db = Database::getConnection();
            $db->prepare("DELETE FROM sects WHERE id = ?")->execute([(int)$sect['id']]);
            return ['success' => true, 'message' => 'Sect disbanded.'];
        } catch (PDOException $e) {
            error_log("SectService::disbandSect " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not disband.'];
        }
    }

    /**
     * Add sect EXP (from PvP win, PvE win, breakthrough). Updates tier if thresholds crossed.
     */
    public function addSectExp(int $userId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        try {
            $sect = $this->getSectByUserId($userId);
            if (!$sect) {
                return;
            }
            $db = Database::getConnection();
            $sectId = (int)$sect['id'];
            $db->prepare("UPDATE sects SET sect_exp = sect_exp + ? WHERE id = ?")->execute([$amount, $sectId]);

            $stmt = $db->prepare("SELECT sect_exp FROM sects WHERE id = ?");
            $stmt->execute([$sectId]);
            $newExp = (int)$stmt->fetchColumn();
            $newTier = self::tierFromExp($newExp);
            $db->prepare("UPDATE sects SET tier = ? WHERE id = ?")->execute([$newTier, $sectId]);
        } catch (PDOException $e) {
            error_log("SectService::addSectExp " . $e->getMessage());
        }
    }

    /**
     * Add contribution for a sect member (PvP/PvE/breakthrough). Phase 2.7.
     */
    public function addSectContribution(int $userId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        try {
            $sect = $this->getSectByUserId($userId);
            if (!$sect) {
                return;
            }
            $db = Database::getConnection();
            $sectId = (int)$sect['id'];
            $db->prepare("UPDATE sect_members SET contribution = contribution + ? WHERE sect_id = ? AND user_id = ?")
                ->execute([$amount, $sectId, $userId]);
        } catch (PDOException $e) {
            error_log("SectService::addSectContribution " . $e->getMessage());
        }
    }

    /**
     * Donate gold to sect: deduct gold, add sect_exp (2 per 100 gold), add contribution (1 per 100 gold). Transaction. Phase 2.7.
     */
    public function donate(int $userId, int $goldAmount): array
    {
        if ($goldAmount <= 0) {
            return ['success' => false, 'message' => 'Amount must be positive.'];
        }
        $sect = $this->getSectByUserId($userId);
        if (!$sect) {
            return ['success' => false, 'message' => 'You must be in a sect to donate.'];
        }
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT gold FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $gold = (int)$stmt->fetchColumn();
            if ($gold < $goldAmount) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Not enough gold.'];
            }

            $units = (int)floor($goldAmount / 100);
            $sectExpGain = $units * 2;
            $contributionGain = $units;
            if ($units < 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Minimum 100 gold per donation (1 contribution, 2 sect EXP).'];
            }

            $db->prepare("UPDATE users SET gold = GREATEST(0, gold - ?) WHERE id = ?")->execute([$goldAmount, $userId]);
            $sectId = (int)$sect['id'];
            $db->prepare("UPDATE sects SET sect_exp = sect_exp + ? WHERE id = ?")->execute([$sectExpGain, $sectId]);
            $db->prepare("UPDATE sect_members SET contribution = contribution + ? WHERE sect_id = ? AND user_id = ?")
                ->execute([$contributionGain, $sectId, $userId]);

            $stmt = $db->prepare("SELECT sect_exp FROM sects WHERE id = ?");
            $stmt->execute([$sectId]);
            $newExp = (int)$stmt->fetchColumn();
            $newTier = self::tierFromExp($newExp);
            $db->prepare("UPDATE sects SET tier = ? WHERE id = ?")->execute([$newTier, $sectId]);

            $db->commit();
            return [
                'success' => true,
                'message' => "Donated {$goldAmount} gold. +{$contributionGain} contribution, +{$sectExpGain} sect EXP.",
                'contribution_gain' => $contributionGain,
                'sect_exp_gain' => $sectExpGain,
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectService::donate " . $e->getMessage());
            return ['success' => false, 'message' => 'Donation failed.'];
        }
    }

    /**
     * Leaderboard: sects ranked by sect_exp DESC. Single query with JOIN and GROUP BY.
     * Uses index idx_sect_exp on sects. Returns id, name, tier, sect_exp, leader_username, member_count.
     */
    public function getLeaderboard(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT s.id, s.name, s.tier, s.sect_exp, s.leader_user_id,
                       u.username AS leader_username,
                       COUNT(m.id) AS member_count
                FROM sects s
                LEFT JOIN users u ON u.id = s.leader_user_id
                LEFT JOIN sect_members m ON m.sect_id = s.id
                GROUP BY s.id, s.name, s.tier, s.sect_exp, s.leader_user_id, u.username
                ORDER BY s.sect_exp DESC, s.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("SectService::getLeaderboard " . $e->getMessage());
            return [];
        }
    }

    /**
     * List all sects (for join page): id, name, tier, sect_exp, member_count.
     */
    public function listSects(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT s.id, s.name, s.tier, s.sect_exp, s.leader_user_id,
                       (SELECT COUNT(*) FROM sect_members m WHERE m.sect_id = s.id) AS member_count
                FROM sects s
                ORDER BY s.sect_exp DESC, s.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("SectService::listSects " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get members of a sect with username, role, contribution. Internal ranking: ORDER BY contribution DESC.
     * Uses index idx_sect_contribution (sect_id, contribution). Phase 2.7.
     */
    public function getMembers(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT m.id, m.user_id, m.role, m.joined_at, COALESCE(m.contribution, 0) AS contribution, u.username
                FROM sect_members m
                JOIN users u ON u.id = m.user_id
                WHERE m.sect_id = ?
                ORDER BY contribution DESC, FIELD(m.role, 'leader', 'elder', 'disciple'), m.joined_at ASC
            ");
            $stmt->execute([$sectId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("SectService::getMembers " . $e->getMessage());
            return [];
        }
    }

    /**
     * Promote disciple to elder. Leader only. Max 5 elders.
     */
    public function promoteToElder(int $leaderUserId, int $memberUserId): array
    {
        $sect = $this->getSectByUserId($leaderUserId);
        if (!$sect || (string)$sect['role'] !== 'leader') {
            return ['success' => false, 'message' => 'Only the leader can promote.'];
        }
        $sectId = (int)$sect['id'];
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT role FROM sect_members WHERE sect_id = ? AND user_id = ?");
        $stmt->execute([$sectId, $memberUserId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || (string)$member['role'] !== 'disciple') {
            return ['success' => false, 'message' => 'Member not found or already elder/leader.'];
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM sect_members WHERE sect_id = ? AND role = 'elder'");
        $stmt->execute([$sectId]);
        if ((int)$stmt->fetchColumn() >= self::MAX_ELDERS) {
            return ['success' => false, 'message' => 'Maximum 5 elders.'];
        }
        $db->prepare("UPDATE sect_members SET role = 'elder' WHERE sect_id = ? AND user_id = ?")->execute([$sectId, $memberUserId]);
        return ['success' => true, 'message' => 'Promoted to elder.'];
    }

    /**
     * Demote elder to disciple. Leader only.
     */
    public function demoteElder(int $leaderUserId, int $memberUserId): array
    {
        $sect = $this->getSectByUserId($leaderUserId);
        if (!$sect || (string)$sect['role'] !== 'leader') {
            return ['success' => false, 'message' => 'Only the leader can demote.'];
        }
        $sectId = (int)$sect['id'];
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT role FROM sect_members WHERE sect_id = ? AND user_id = ?");
        $stmt->execute([$sectId, $memberUserId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || (string)$member['role'] !== 'elder') {
            return ['success' => false, 'message' => 'Member is not an elder.'];
        }
        $db->prepare("UPDATE sect_members SET role = 'disciple' WHERE sect_id = ? AND user_id = ?")->execute([$sectId, $memberUserId]);
        return ['success' => true, 'message' => 'Demoted to disciple.'];
    }

    /**
     * Transfer leadership to an elder. Leader only.
     */
    public function transferLeadership(int $leaderUserId, int $newLeaderUserId): array
    {
        $sect = $this->getSectByUserId($leaderUserId);
        if (!$sect || (string)$sect['role'] !== 'leader') {
            return ['success' => false, 'message' => 'Only the leader can transfer.'];
        }
        $sectId = (int)$sect['id'];
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT role FROM sect_members WHERE sect_id = ? AND user_id = ?");
        $stmt->execute([$sectId, $newLeaderUserId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || (string)$member['role'] !== 'elder') {
            return ['success' => false, 'message' => 'New leader must be an elder.'];
        }
        $db->prepare("UPDATE sects SET leader_user_id = ? WHERE id = ?")->execute([$newLeaderUserId, $sectId]);
        $db->prepare("UPDATE sect_members SET role = 'elder' WHERE sect_id = ? AND user_id = ?")->execute([$sectId, $leaderUserId]);
        $db->prepare("UPDATE sect_members SET role = 'leader' WHERE sect_id = ? AND user_id = ?")->execute([$sectId, $newLeaderUserId]);
        return ['success' => true, 'message' => 'Leadership transferred.'];
    }
}
