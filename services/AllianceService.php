<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Sect alliances: up to 5 sects per alliance; war damage bonuses when 3–5 members.
 */
class AllianceService
{
    public const MIN_SECTS_FOR_WAR_BONUS = 3;
    public const MAX_SECTS_PER_ALLIANCE = 5;

    /** Damage multiplier contribution from alliance (1.0 = none). Indexed by member count. */
    private const WAR_DAMAGE_MULTIPLIER_BY_SIZE = [
        3 => 1.05,
        4 => 1.08,
        5 => 1.12,
    ];

    public static function warBonusDescription(): array
    {
        return [
            ['members' => 3, 'bonus_pct' => 5, 'multiplier' => 1.05],
            ['members' => 4, 'bonus_pct' => 8, 'multiplier' => 1.08],
            ['members' => 5, 'bonus_pct' => 12, 'multiplier' => 1.12],
        ];
    }

    public function getWarDamageMultiplierForSect(int $sectId): float
    {
        $n = $this->getMemberCountForSectAlliance($sectId);
        if ($n < self::MIN_SECTS_FOR_WAR_BONUS || $n > self::MAX_SECTS_PER_ALLIANCE) {
            return 1.0;
        }
        return self::WAR_DAMAGE_MULTIPLIER_BY_SIZE[$n] ?? 1.0;
    }

    /**
     * @return int[] Sect IDs in the same alliance as $sectId (including $sectId), or [] if none.
     */
    public function getAlliedSectIds(int $sectId): array
    {
        $aid = $this->getAllianceIdForSect($sectId);
        if ($aid === null) {
            return [];
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT sect_id FROM alliance_members WHERE alliance_id = ?');
            $stmt->execute([$aid]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('intval', $ids);
        } catch (PDOException $e) {
            error_log('AllianceService::getAlliedSectIds ' . $e->getMessage());
            return [];
        }
    }

    public function areSectsAllied(int $sectIdA, int $sectIdB): bool
    {
        if ($sectIdA <= 0 || $sectIdB <= 0 || $sectIdA === $sectIdB) {
            return false;
        }
        $a = $this->getAllianceIdForSect($sectIdA);
        $b = $this->getAllianceIdForSect($sectIdB);
        return $a !== null && $a === $b;
    }

    public function getAllianceIdForSect(int $sectId): ?int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT alliance_id FROM alliance_members WHERE sect_id = ? LIMIT 1');
            $stmt->execute([$sectId]);
            $v = $stmt->fetchColumn();
            if ($v === false || $v === null) {
                return null;
            }
            return (int)$v;
        } catch (PDOException $e) {
            error_log('AllianceService::getAllianceIdForSect ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return int[] Every sect_id currently in an alliance (for invite UI filtering).
     */
    public function getAllSectIdsInAlliances(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query('SELECT sect_id FROM alliance_members');
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('intval', $ids);
        } catch (PDOException $e) {
            error_log('AllianceService::getAllSectIdsInAlliances ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Full alliance panel data for a user's sect, or null if not in an alliance.
     *
     * @return array{
     *   alliance_id: int,
     *   name: string,
     *   members: list<array{id:int,name:string,role:string,joined_at:string}>,
     *   member_count: int,
     *   war_damage_multiplier: float,
     *   war_bonus_active: bool,
     *   pending_invites_out: list<array{id:int,target_sect_id:int,target_name:string,created_at:string}>
     * }|null
     */
    public function getAlliancePanelForUser(int $userId): ?array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return null;
        }
        $sectId = (int)$sect['id'];
        $aid = $this->getAllianceIdForSect($sectId);
        if ($aid === null) {
            return null;
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, name FROM alliances WHERE id = ? LIMIT 1');
            $stmt->execute([$aid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $stmt = $db->prepare("
                SELECT m.sect_id, m.role, m.joined_at, s.name AS sect_name
                FROM alliance_members m
                JOIN sects s ON s.id = m.sect_id
                WHERE m.alliance_id = ?
                ORDER BY m.joined_at ASC, m.sect_id ASC
            ");
            $stmt->execute([$aid]);
            $members = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
                $members[] = [
                    'id' => (int)$m['sect_id'],
                    'name' => (string)$m['sect_name'],
                    'role' => (string)$m['role'],
                    'joined_at' => (string)$m['joined_at'],
                ];
            }
            $count = count($members);
            $mult = $this->getWarDamageMultiplierForSect($sectId);
            $bonusActive = $count >= self::MIN_SECTS_FOR_WAR_BONUS && $count <= self::MAX_SECTS_PER_ALLIANCE;

            $pendingOut = [];
            $stmt = $db->prepare("
                SELECT i.id, i.target_sect_id, i.created_at, s.name AS target_name
                FROM alliance_invitations i
                JOIN sects s ON s.id = i.target_sect_id
                WHERE i.alliance_id = ? AND i.status = 'pending'
                ORDER BY i.created_at DESC
            ");
            $stmt->execute([$aid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                $pendingOut[] = [
                    'id' => (int)$p['id'],
                    'target_sect_id' => (int)$p['target_sect_id'],
                    'target_name' => (string)$p['target_name'],
                    'created_at' => (string)$p['created_at'],
                ];
            }

            return [
                'alliance_id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'members' => $members,
                'member_count' => $count,
                'war_damage_multiplier' => $mult,
                'war_bonus_active' => $bonusActive && $mult > 1.0,
                'pending_invites_out' => $pendingOut,
            ];
        } catch (PDOException $e) {
            error_log('AllianceService::getAlliancePanelForUser ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return list<array{id:int,alliance_id:int,alliance_name:string,inviter_sect_id:int,inviter_sect_name:string,created_at:string}>
     */
    public function getPendingInvitesForSect(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT i.id, i.alliance_id, i.inviter_sect_id, i.created_at,
                       a.name AS alliance_name, s.name AS inviter_sect_name
                FROM alliance_invitations i
                JOIN alliances a ON a.id = i.alliance_id
                JOIN sects s ON s.id = i.inviter_sect_id
                WHERE i.target_sect_id = ? AND i.status = 'pending'
                ORDER BY i.created_at DESC
            ");
            $stmt->execute([$sectId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'alliance_id' => (int)$r['alliance_id'],
                    'alliance_name' => (string)$r['alliance_name'],
                    'inviter_sect_id' => (int)$r['inviter_sect_id'],
                    'inviter_sect_name' => (string)$r['inviter_sect_name'],
                    'created_at' => (string)$r['created_at'],
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log('AllianceService::getPendingInvitesForSect ' . $e->getMessage());
            return [];
        }
    }

    public function createAlliance(int $userId, string $name): array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can create an alliance.'];
        }
        $sectId = (int)$sect['id'];
        if ($this->getAllianceIdForSect($sectId) !== null) {
            return ['success' => false, 'message' => 'Your sect is already in an alliance.'];
        }
        $name = trim($name);
        if ($name === '') {
            $name = 'Alliance of ' . (string)$sect['name'];
        }
        $nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($nameLen > 80) {
            return ['success' => false, 'message' => 'Alliance name is too long (max 80 characters).'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $db->prepare('INSERT INTO alliances (name) VALUES (?)')->execute([$name]);
            $aid = (int)$db->lastInsertId();
            $db->prepare("
                INSERT INTO alliance_members (alliance_id, sect_id, role) VALUES (?, ?, 'founder')
            ")->execute([$aid, $sectId]);
            $db->commit();
            return ['success' => true, 'message' => 'Alliance founded. Invite other sects to grow your pact (3–5 sects for war bonuses).'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AllianceService::createAlliance ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not create alliance.'];
        }
    }

    public function inviteSect(int $userId, int $targetSectId): array
    {
        if ($targetSectId <= 0) {
            return ['success' => false, 'message' => 'Invalid sect.'];
        }
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can send invitations.'];
        }
        $inviterSectId = (int)$sect['id'];
        if ($targetSectId === $inviterSectId) {
            return ['success' => false, 'message' => 'You cannot invite your own sect.'];
        }
        $aid = $this->getAllianceIdForSect($inviterSectId);
        if ($aid === null) {
            return ['success' => false, 'message' => 'Your sect is not in an alliance.'];
        }
        if ($this->getAllianceIdForSect($targetSectId) !== null) {
            return ['success' => false, 'message' => 'That sect is already allied with another pact.'];
        }
        $count = $this->getMemberCountByAllianceId($aid);
        if ($count >= self::MAX_SECTS_PER_ALLIANCE) {
            return ['success' => false, 'message' => 'Your alliance already has the maximum of ' . self::MAX_SECTS_PER_ALLIANCE . ' sects.'];
        }
        $target = (new SectService())->getSectById($targetSectId);
        if (!$target) {
            return ['success' => false, 'message' => 'Sect not found.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO alliance_invitations (alliance_id, target_sect_id, inviter_sect_id, status)
                VALUES (?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE inviter_sect_id = VALUES(inviter_sect_id), status = 'pending', created_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$aid, $targetSectId, $inviterSectId]);
            return ['success' => true, 'message' => 'Invitation sent to ' . (string)$target['name'] . '.'];
        } catch (PDOException $e) {
            error_log('AllianceService::inviteSect ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not send invitation.'];
        }
    }

    public function acceptInvite(int $userId, int $inviteId): array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can accept invitations.'];
        }
        $sectId = (int)$sect['id'];
        if ($this->getAllianceIdForSect($sectId) !== null) {
            return ['success' => false, 'message' => 'Your sect is already in an alliance.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT id, alliance_id, target_sect_id, status
                FROM alliance_invitations
                WHERE id = ? LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$inviteId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv || (string)$inv['status'] !== 'pending') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Invitation not found or no longer pending.'];
            }
            if ((int)$inv['target_sect_id'] !== $sectId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'This invitation is for another sect.'];
            }
            $aid = (int)$inv['alliance_id'];
            $count = $this->getMemberCountByAllianceId($aid, $db);
            if ($count >= self::MAX_SECTS_PER_ALLIANCE) {
                $db->prepare("UPDATE alliance_invitations SET status = 'cancelled' WHERE id = ?")->execute([$inviteId]);
                $db->commit();
                return ['success' => false, 'message' => 'That alliance is already full.'];
            }
            $db->prepare("
                INSERT INTO alliance_members (alliance_id, sect_id, role) VALUES (?, ?, 'member')
            ")->execute([$aid, $sectId]);
            $db->prepare("UPDATE alliance_invitations SET status = 'accepted' WHERE id = ?")->execute([$inviteId]);
            $db->commit();
            return ['success' => true, 'message' => 'Your sect has joined the alliance.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AllianceService::acceptInvite ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not join alliance.'];
        }
    }

    public function declineInvite(int $userId, int $inviteId): array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can decline invitations.'];
        }
        $sectId = (int)$sect['id'];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                UPDATE alliance_invitations SET status = 'declined'
                WHERE id = ? AND target_sect_id = ? AND status = 'pending'
            ");
            $stmt->execute([$inviteId, $sectId]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Invitation not found.'];
            }
            return ['success' => true, 'message' => 'Invitation declined.'];
        } catch (PDOException $e) {
            error_log('AllianceService::declineInvite ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not update invitation.'];
        }
    }

    public function cancelInvite(int $userId, int $inviteId): array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can cancel invitations.'];
        }
        $sectId = (int)$sect['id'];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                UPDATE alliance_invitations i
                INNER JOIN alliance_members m ON m.alliance_id = i.alliance_id AND m.sect_id = ?
                SET i.status = 'cancelled'
                WHERE i.id = ? AND i.status = 'pending'
            ");
            $stmt->execute([$sectId, $inviteId]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Invitation not found or you are not in that alliance.'];
            }
            return ['success' => true, 'message' => 'Invitation cancelled.'];
        } catch (PDOException $e) {
            error_log('AllianceService::cancelInvite ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not cancel invitation.'];
        }
    }

    public function leaveAlliance(int $userId): array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect || !$this->isLeaderOrElder($sect)) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can withdraw from an alliance.'];
        }
        $sectId = (int)$sect['id'];
        $aid = $this->getAllianceIdForSect($sectId);
        if ($aid === null) {
            return ['success' => false, 'message' => 'Your sect is not in an alliance.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $db->prepare('DELETE FROM alliance_members WHERE alliance_id = ? AND sect_id = ?')->execute([$aid, $sectId]);
            $stmt = $db->prepare('SELECT COUNT(*) FROM alliance_members WHERE alliance_id = ?');
            $stmt->execute([$aid]);
            $remaining = (int)$stmt->fetchColumn();
            if ($remaining === 0) {
                $db->prepare('DELETE FROM alliances WHERE id = ?')->execute([$aid]);
            }
            $db->commit();
            return ['success' => true, 'message' => $remaining === 0 ? 'Your sect left the alliance. The pact has been dissolved.' : 'Your sect has left the alliance.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AllianceService::leaveAlliance ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not leave alliance.'];
        }
    }

    private function getMemberCountForSectAlliance(int $sectId): int
    {
        $aid = $this->getAllianceIdForSect($sectId);
        if ($aid === null) {
            return 0;
        }
        return $this->getMemberCountByAllianceId($aid);
    }

    private function getMemberCountByAllianceId(int $allianceId, ?PDO $db = null): int
    {
        $own = $db === null;
        try {
            if ($db === null) {
                $db = Database::getConnection();
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM alliance_members WHERE alliance_id = ?');
            $stmt->execute([$allianceId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            if ($own) {
                error_log('AllianceService::getMemberCountByAllianceId ' . $e->getMessage());
            }
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $sect Row from SectService::getSectByUserId
     */
    private function isLeaderOrElder(array $sect): bool
    {
        $r = (string)($sect['rank'] ?? $sect['role'] ?? '');
        return in_array($r, ['leader', 'elder'], true);
    }
}
