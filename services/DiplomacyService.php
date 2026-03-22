<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Sect diplomacy: non-aggression pacts, rivalry, reputation, sect war modifiers.
 */
class DiplomacyService
{
    public const RIVAL_WAR_DAMAGE_MULTIPLIER = 1.10;
    public const MIN_REPUTATION = 0;
    public const MAX_REPUTATION = 10000;
    public const LONG_NAP_DAYS = 14;

    private const REP_NAP_SIGNED_BOTH = 6;
    private const REP_BREAK_NAP_BREAKER = -12;
    private const REP_BREAK_NAP_KEEPER = 5;
    private const REP_LONG_NAP_EXTRA_BREAKER = -8;
    private const REP_LONG_NAP_EXTRA_KEEPER = 8;
    /** Declaring rivalry while an NAP is active (betrayal). */
    private const REP_BETRAY_NAP_FOR_RIVALRY = -28;
    private const REP_BETRAY_VICTIM = 10;
    private const REP_DECLARE_RIVAL_INITIATOR = -4;
    private const REP_DECLARE_RIVAL_TARGET = -6;
    private const REP_WITHDRAW_NAP_PROPOSAL = -3;
    private const REP_END_RIVALRY_EACH = 3;

    /**
     * @return array{0: int, 1: int}
     */
    public function normalizePair(int $sectIdA, int $sectIdB): array
    {
        if ($sectIdA < $sectIdB) {
            return [$sectIdA, $sectIdB];
        }
        return [$sectIdB, $sectIdA];
    }

    public function hasActiveNap(int $sectIdA, int $sectIdB): bool
    {
        if ($sectIdA <= 0 || $sectIdB <= 0 || $sectIdA === $sectIdB) {
            return false;
        }
        $row = $this->getRelationRow($sectIdA, $sectIdB);
        return $row !== null && (string)$row['nap_status'] === 'active';
    }

    public function areRivals(int $sectIdA, int $sectIdB): bool
    {
        if ($sectIdA <= 0 || $sectIdB <= 0 || $sectIdA === $sectIdB) {
            return false;
        }
        $row = $this->getRelationRow($sectIdA, $sectIdB);
        return $row !== null && (int)$row['is_rival'] === 1;
    }

    public function getRivalDamageMultiplier(int $actorSectId, int $enemySectId): float
    {
        return $this->areRivals($actorSectId, $enemySectId) ? self::RIVAL_WAR_DAMAGE_MULTIPLIER : 1.0;
    }

    /**
     * @return int[] Other sect IDs with an active NAP toward $sectId (symmetric).
     */
    public function getActiveNapPartnerSectIds(int $sectId): array
    {
        if ($sectId <= 0) {
            return [];
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT lower_sect_id, higher_sect_id
                FROM sect_relations
                WHERE nap_status = 'active' AND (lower_sect_id = ? OR higher_sect_id = ?)
            ");
            $stmt->execute([$sectId, $sectId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $lo = (int)$r['lower_sect_id'];
                $hi = (int)$r['higher_sect_id'];
                $out[] = $lo === $sectId ? $hi : $lo;
            }
            return $out;
        } catch (PDOException $e) {
            error_log('DiplomacyService::getActiveNapPartnerSectIds ' . $e->getMessage());
            return [];
        }
    }

    public function canInitiateNewDiplomacy(int $mySectId, int $otherSectId): bool
    {
        if ($mySectId <= 0 || $otherSectId <= 0 || $mySectId === $otherSectId) {
            return false;
        }
        $row = $this->getRelationRow($mySectId, $otherSectId);
        if ($row === null) {
            return true;
        }
        return (string)$row['nap_status'] === 'none' && (int)$row['is_rival'] === 0;
    }

    /**
     * @return array{sect_id: int, sect_name: string, reputation: int, relations: list<array<string, mixed>}, reputation_band: string}|null
     */
    public function getDiplomacyPanelForUser(int $userId): ?array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return null;
        }
        $sectId = (int)$sect['id'];
        $rep = (int)($sect['sect_reputation'] ?? 1000);
        $relations = $this->fetchRelationsForSect($sectId);
        return [
            'sect_id' => $sectId,
            'sect_name' => (string)($sect['name'] ?? ''),
            'reputation' => $rep,
            'reputation_band' => $this->reputationBandLabel($rep),
            'relations' => $relations,
        ];
    }

    public function reputationBandLabel(int $reputation): string
    {
        if ($reputation >= 1400) {
            return 'Renowned';
        }
        if ($reputation >= 1200) {
            return 'Honored';
        }
        if ($reputation >= 1000) {
            return 'Neutral';
        }
        if ($reputation >= 700) {
            return 'Questionable';
        }
        return 'Notorious';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRelationsForSect(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT r.id, r.lower_sect_id, r.higher_sect_id, r.nap_status, r.nap_proposed_by_sect_id,
                       r.nap_started_at, r.is_rival, r.updated_at,
                       CASE WHEN r.lower_sect_id = ? THEN r.higher_sect_id ELSE r.lower_sect_id END AS other_sect_id,
                       s.name AS other_sect_name
                FROM sect_relations r
                JOIN sects s ON s.id = CASE WHEN r.lower_sect_id = ? THEN r.higher_sect_id ELSE r.lower_sect_id END
                WHERE r.lower_sect_id = ? OR r.higher_sect_id = ?
                ORDER BY s.name ASC
            ");
            $stmt->execute([$sectId, $sectId, $sectId, $sectId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $otherId = (int)$r['other_sect_id'];
                $nap = (string)$r['nap_status'];
                $proposedBy = $r['nap_proposed_by_sect_id'] !== null ? (int)$r['nap_proposed_by_sect_id'] : null;
                $out[] = [
                    'relation_id' => (int)$r['id'],
                    'other_sect_id' => $otherId,
                    'other_sect_name' => (string)$r['other_sect_name'],
                    'nap_status' => $nap,
                    'nap_proposed_by_sect_id' => $proposedBy,
                    'nap_started_at' => $r['nap_started_at'] !== null ? (string)$r['nap_started_at'] : null,
                    'is_rival' => (int)$r['is_rival'] === 1,
                    'updated_at' => (string)$r['updated_at'],
                    'incoming_nap' => $nap === 'pending' && $proposedBy !== null && $proposedBy !== $sectId,
                    'outgoing_nap' => $nap === 'pending' && $proposedBy === $sectId,
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log('DiplomacyService::fetchRelationsForSect ' . $e->getMessage());
            return [];
        }
    }

    public function proposeNonAggressionPact(int $userId, int $targetSectId): array
    {
        $sect = $this->requireLeaderElderSect($userId);
        if ($sect === null) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can propose diplomacy.'];
        }
        $myId = (int)$sect['id'];
        if ($targetSectId <= 0 || $targetSectId === $myId) {
            return ['success' => false, 'message' => 'Invalid target sect.'];
        }
        $target = (new SectService())->getSectById($targetSectId);
        if (!$target) {
            return ['success' => false, 'message' => 'Sect not found.'];
        }

        [$lo, $hi] = $this->normalizePair($myId, $targetSectId);

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $row = $this->getRelationRowLocked($db, $lo, $hi);
            if ($row !== null) {
                if ((string)$row['nap_status'] === 'active') {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'A non-aggression pact is already in effect.'];
                }
                if ((string)$row['nap_status'] === 'pending') {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'A pact proposal is already waiting for a response.'];
                }
                if ((int)$row['is_rival'] === 1) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'End the declared rivalry before proposing a pact.'];
                }
                $db->prepare("
                    UPDATE sect_relations
                    SET nap_status = 'pending', nap_proposed_by_sect_id = ?, nap_started_at = NULL
                    WHERE id = ?
                ")->execute([$myId, (int)$row['id']]);
            } else {
                $db->prepare("
                    INSERT INTO sect_relations (lower_sect_id, higher_sect_id, nap_status, nap_proposed_by_sect_id, is_rival)
                    VALUES (?, ?, 'pending', ?, 0)
                ")->execute([$lo, $hi, $myId]);
            }
            $db->commit();
            return ['success' => true, 'message' => 'Non-aggression pact proposed to ' . (string)$target['name'] . '.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DiplomacyService::proposeNonAggressionPact ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not propose pact.'];
        }
    }

    public function acceptNonAggressionPact(int $userId, int $targetSectId): array
    {
        $sect = $this->requireLeaderElderSect($userId);
        if ($sect === null) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can accept a pact.'];
        }
        $myId = (int)$sect['id'];
        if ($targetSectId <= 0 || $targetSectId === $myId) {
            return ['success' => false, 'message' => 'Invalid sect.'];
        }
        [$lo, $hi] = $this->normalizePair($myId, $targetSectId);

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $row = $this->getRelationRowLocked($db, $lo, $hi);
            if ($row === null || (string)$row['nap_status'] !== 'pending') {
                $db->rollBack();
                return ['success' => false, 'message' => 'There is no pending pact to accept.'];
            }
            $proposer = (int)$row['nap_proposed_by_sect_id'];
            if ($proposer === $myId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You cannot accept your own proposal.'];
            }
            $db->prepare("
                UPDATE sect_relations
                SET nap_status = 'active', nap_proposed_by_sect_id = NULL, nap_started_at = NOW()
                WHERE id = ?
            ")->execute([(int)$row['id']]);
            $this->adjustSectReputation($db, $myId, self::REP_NAP_SIGNED_BOTH);
            $this->adjustSectReputation($db, $proposer, self::REP_NAP_SIGNED_BOTH);
            $db->commit();
            return ['success' => true, 'message' => 'Non-aggression pact is now in effect. Your sects gain diplomatic standing.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DiplomacyService::acceptNonAggressionPact ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not accept pact.'];
        }
    }

    public function withdrawNapProposal(int $userId, int $targetSectId): array
    {
        return $this->clearPendingNap($userId, $targetSectId, true);
    }

    public function declineNapProposal(int $userId, int $targetSectId): array
    {
        return $this->clearPendingNap($userId, $targetSectId, false);
    }

    private function clearPendingNap(int $userId, int $targetSectId, bool $isWithdrawByProposer): array
    {
        $sect = $this->requireLeaderElderSect($userId);
        if ($sect === null) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can respond to proposals.'];
        }
        $myId = (int)$sect['id'];
        if ($targetSectId <= 0 || $targetSectId === $myId) {
            return ['success' => false, 'message' => 'Invalid sect.'];
        }
        [$lo, $hi] = $this->normalizePair($myId, $targetSectId);

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $row = $this->getRelationRowLocked($db, $lo, $hi);
            if ($row === null || (string)$row['nap_status'] !== 'pending') {
                $db->rollBack();
                return ['success' => false, 'message' => 'There is no pending proposal for this sect.'];
            }
            $proposer = (int)$row['nap_proposed_by_sect_id'];
            if ($isWithdrawByProposer) {
                if ($proposer !== $myId) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Only the proposing sect can withdraw this offer.'];
                }
                $this->adjustSectReputation($db, $myId, self::REP_WITHDRAW_NAP_PROPOSAL);
            } else {
                if ($proposer === $myId) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Use withdraw to cancel your own proposal.'];
                }
            }
            $rid = (int)$row['id'];
            $db->prepare("
                UPDATE sect_relations
                SET nap_status = 'none', nap_proposed_by_sect_id = NULL, nap_started_at = NULL
                WHERE id = ?
            ")->execute([$rid]);
            $this->deleteRelationIfEmpty($db, $rid);
            $db->commit();
            return [
                'success' => true,
                'message' => $isWithdrawByProposer ? 'Proposal withdrawn.' : 'Proposal declined.',
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DiplomacyService::clearPendingNap ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not update proposal.'];
        }
    }

    public function declareRivalry(int $userId, int $targetSectId): array
    {
        $sect = $this->requireLeaderElderSect($userId);
        if ($sect === null) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can declare rivalry.'];
        }
        $myId = (int)$sect['id'];
        if ($targetSectId <= 0 || $targetSectId === $myId) {
            return ['success' => false, 'message' => 'Invalid target sect.'];
        }
        $target = (new SectService())->getSectById($targetSectId);
        if (!$target) {
            return ['success' => false, 'message' => 'Sect not found.'];
        }
        [$lo, $hi] = $this->normalizePair($myId, $targetSectId);

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $row = $this->getRelationRowLocked($db, $lo, $hi);
            if ($row !== null && (string)$row['nap_status'] === 'pending') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Resolve the pending pact proposal before declaring rivalry.'];
            }
            $betrayal = $row !== null && (string)$row['nap_status'] === 'active';
            if ($row !== null && (int)$row['is_rival'] === 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You are already declared rivals.'];
            }

            if ($row === null) {
                $db->prepare("
                    INSERT INTO sect_relations (lower_sect_id, higher_sect_id, nap_status, nap_proposed_by_sect_id, nap_started_at, is_rival)
                    VALUES (?, ?, 'none', NULL, NULL, 1)
                ")->execute([$lo, $hi]);
            } else {
                $db->prepare("
                    UPDATE sect_relations
                    SET nap_status = 'none', nap_proposed_by_sect_id = NULL, nap_started_at = NULL, is_rival = 1
                    WHERE id = ?
                ")->execute([(int)$row['id']]);
            }

            if ($betrayal) {
                $otherId = $myId === $lo ? $hi : $lo;
                $this->adjustSectReputation($db, $myId, self::REP_BETRAY_NAP_FOR_RIVALRY);
                $this->adjustSectReputation($db, $otherId, self::REP_BETRAY_VICTIM);
            } else {
                $otherId = $myId === $lo ? $hi : $lo;
                $this->adjustSectReputation($db, $myId, self::REP_DECLARE_RIVAL_INITIATOR);
                $this->adjustSectReputation($db, $otherId, self::REP_DECLARE_RIVAL_TARGET);
            }

            $db->commit();
            $msg = $betrayal
                ? 'You broke the non-aggression pact and declared open rivalry. Your sect is seen as treacherous.'
                : 'Rivalry declared. Both sects deal extra damage to each other in sect wars.';
            return ['success' => true, 'message' => $msg];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DiplomacyService::declareRivalry ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not declare rivalry.'];
        }
    }

    /**
     * Ends an active NAP (breaker loses standing; partner gains) or ends rivalry, or clears pending as breaker flow.
     */
    public function breakAgreement(int $userId, int $targetSectId): array
    {
        $sect = $this->requireLeaderElderSect($userId);
        if ($sect === null) {
            return ['success' => false, 'message' => 'Only sect leaders and elders can break agreements.'];
        }
        $myId = (int)$sect['id'];
        if ($targetSectId <= 0 || $targetSectId === $myId) {
            return ['success' => false, 'message' => 'Invalid sect.'];
        }
        [$lo, $hi] = $this->normalizePair($myId, $targetSectId);
        $otherId = $myId === $lo ? $hi : $lo;

        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $row = $this->getRelationRowLocked($db, $lo, $hi);
            if ($row === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'No diplomatic record with this sect.'];
            }
            $nap = (string)$row['nap_status'];
            $rival = (int)$row['is_rival'] === 1;

            if ($nap === 'active') {
                $started = $row['nap_started_at'] !== null ? strtotime((string)$row['nap_started_at']) : false;
                $longHonor = $started !== false && (time() - $started) >= self::LONG_NAP_DAYS * 86400;
                $this->adjustSectReputation($db, $myId, self::REP_BREAK_NAP_BREAKER);
                $this->adjustSectReputation($db, $otherId, self::REP_BREAK_NAP_KEEPER);
                if ($longHonor) {
                    $this->adjustSectReputation($db, $myId, self::REP_LONG_NAP_EXTRA_BREAKER);
                    $this->adjustSectReputation($db, $otherId, self::REP_LONG_NAP_EXTRA_KEEPER);
                }
                $db->prepare("
                    UPDATE sect_relations
                    SET nap_status = 'none', nap_proposed_by_sect_id = NULL, nap_started_at = NULL
                    WHERE id = ?
                ")->execute([(int)$row['id']]);
                $this->deleteRelationIfEmpty($db, (int)$row['id']);
                $db->commit();
                return [
                    'success' => true,
                    'message' => $longHonor
                        ? 'Non-aggression pact broken after a long peace. Your sect is heavily criticized; your former partner gains honor.'
                        : 'Non-aggression pact broken. Your sect loses standing; your partner is seen as having honored the treaty.',
                ];
            }

            if ($rival) {
                $this->adjustSectReputation($db, $myId, self::REP_END_RIVALRY_EACH);
                $this->adjustSectReputation($db, $otherId, self::REP_END_RIVALRY_EACH);
                $db->prepare("
                    UPDATE sect_relations SET is_rival = 0 WHERE id = ?
                ")->execute([(int)$row['id']]);
                $this->deleteRelationIfEmpty($db, (int)$row['id']);
                $db->commit();
                return ['success' => true, 'message' => 'Rivalry ended. Both sects take a modest diplomatic recovery.'];
            }

            $db->rollBack();
            return ['success' => false, 'message' => 'There is no active pact or rivalry to break. Use withdraw or decline for pending proposals.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DiplomacyService::breakAgreement ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not break agreement.'];
        }
    }

    private function deleteRelationIfEmpty(PDO $db, int $relationId): void
    {
        $stmt = $db->prepare("
            SELECT nap_status, is_rival FROM sect_relations WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$relationId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return;
        }
        if ((string)$r['nap_status'] === 'none' && (int)$r['is_rival'] === 0) {
            $db->prepare('DELETE FROM sect_relations WHERE id = ?')->execute([$relationId]);
        }
    }

    public function adjustSectReputation(PDO $db, int $sectId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $stmt = $db->prepare("
            UPDATE sects
            SET sect_reputation = LEAST(?, GREATEST(?, COALESCE(sect_reputation, 1000) + ?))
            WHERE id = ?
        ");
        $stmt->execute([self::MAX_REPUTATION, self::MIN_REPUTATION, $delta, $sectId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRelationRow(int $sectIdA, int $sectIdB, ?PDO $db = null): ?array
    {
        [$lo, $hi] = $this->normalizePair($sectIdA, $sectIdB);
        $own = $db === null;
        try {
            if ($db === null) {
                $db = Database::getConnection();
            }
            $stmt = $db->prepare("
                SELECT * FROM sect_relations WHERE lower_sect_id = ? AND higher_sect_id = ? LIMIT 1
            ");
            $stmt->execute([$lo, $hi]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            if ($own) {
                error_log('DiplomacyService::getRelationRow ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRelationRowLocked(PDO $db, int $lower, int $higher): ?array
    {
        $stmt = $db->prepare("
            SELECT * FROM sect_relations WHERE lower_sect_id = ? AND higher_sect_id = ? LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$lower, $higher]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireLeaderElderSect(int $userId): ?array
    {
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return null;
        }
        $r = (string)($sect['rank'] ?? $sect['role'] ?? '');
        if (!in_array($r, ['leader', 'elder'], true)) {
            return null;
        }
        return $sect;
    }
}
