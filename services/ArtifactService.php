<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/EventService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Artifacts: equip slots (3), active aura slots (2), rarity, evolution, drops from bosses/dungeons/events.
 */
class ArtifactService
{
    public const MAX_EQUIP_SLOTS = 3;

    public const MAX_ACTIVE_SLOTS = 2;

    /** Per tier above 1 for evolving artifacts. */
    private const EVOLUTION_SCALE_PER_TIER = 0.055;

    private static ?bool $tablesOk = null;

    private function tablesAvailable(PDO $db): bool
    {
        if (self::$tablesOk !== null) {
            return self::$tablesOk;
        }
        try {
            $db->query('SELECT 1 FROM artifacts LIMIT 1');
            self::$tablesOk = true;
        } catch (PDOException $e) {
            self::$tablesOk = false;
        }
        return self::$tablesOk;
    }

    private function evolutionMultiplier(array $artifactRow, int $tier): float
    {
        if (empty($artifactRow['is_evolving'])) {
            return 1.0;
        }
        $max = max(1, (int)$artifactRow['evolution_max_tier']);
        $t = max(1, min($max, $tier));
        return 1.0 + ($t - 1) * self::EVOLUTION_SCALE_PER_TIER;
    }

    /**
     * @return array<string, float|int>
     */
    public function getAggregatedCombatModifiers(int $userId): array
    {
        $empty = [
            'passive_attack_pct' => 0.0,
            'passive_defense_pct' => 0.0,
            'passive_max_chi_pct' => 0.0,
            'out_pct' => 0.0,
            'taken_reduction_pct' => 0.0,
            'crit' => 0.0,
            'dodge' => 0.0,
            'counter' => 0.0,
            'lifesteal' => 0.0,
        ];
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return $empty;
            }
            $st = $db->prepare(
                "SELECT ua.*, a.* FROM user_artifacts ua
                 INNER JOIN artifacts a ON a.id = ua.artifact_id
                 WHERE ua.user_id = ? AND (ua.equip_slot IS NOT NULL OR ua.active_slot IS NOT NULL)"
            );
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $sum = $empty;
            foreach ($rows as $r) {
                $m = $this->evolutionMultiplier($r, (int)($r['evolution_tier'] ?? 1));
                $sum['passive_attack_pct'] += (float)$r['passive_attack_pct'] * $m;
                $sum['passive_defense_pct'] += (float)$r['passive_defense_pct'] * $m;
                $sum['passive_max_chi_pct'] += (float)$r['passive_max_chi_pct'] * $m;
                $sum['out_pct'] += (float)$r['combat_out_pct'] * $m;
                $sum['taken_reduction_pct'] += (float)$r['combat_taken_reduction_pct'] * $m;
                $sum['crit'] += (float)$r['combat_crit_bonus'] * $m;
                $sum['dodge'] += (float)$r['combat_dodge_bonus'] * $m;
                $sum['counter'] += (float)$r['combat_counter_bonus'] * $m;
                $sum['lifesteal'] += (float)$r['combat_lifesteal_bonus_pct'] * $m;
            }
            return $sum;
        } catch (PDOException $e) {
            error_log('ArtifactService::getAggregatedCombatModifiers ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Once per local day: if a scheduled event matches an artifact drop_event_tag, roll drop_event_bp.
     */
    public function tryDailyEventArtifactRoll(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return;
            }
            $eventName = (new EventService())->getActiveEvent();
            if ($eventName === null || $eventName === '') {
                return;
            }
            $today = date('Y-m-d');
            $chk = $db->prepare('SELECT 1 FROM user_event_artifact_daily WHERE user_id = ? AND roll_date = ? LIMIT 1');
            $chk->execute([$userId, $today]);
            if ($chk->fetch()) {
                return;
            }
            $arts = $db->query(
                "SELECT * FROM artifacts WHERE drop_event_tag IS NOT NULL AND drop_event_tag != '' AND drop_event_bp > 0 ORDER BY id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($arts as $a) {
                $tag = (string)$a['drop_event_tag'];
                if ($tag !== '' && stripos($eventName, $tag) === false) {
                    continue;
                }
                $bp = min(10000, (int)$a['drop_event_bp']);
                if ($bp > 0 && random_int(1, 10000) <= $bp) {
                    $this->grantArtifact($db, $userId, (int)$a['id'], 'event');
                }
            }
            $db->prepare('INSERT IGNORE INTO user_event_artifact_daily (user_id, roll_date) VALUES (?, ?)')
                ->execute([$userId, $today]);
        } catch (\Throwable $e) {
            error_log('ArtifactService::tryDailyEventArtifactRoll ' . $e->getMessage());
        }
    }

    public function rollWorldBossArtifact(PDO $db, int $userId, int $rank): void
    {
        if (!$this->tablesAvailable($db) || $userId < 1) {
            return;
        }
        $mult = $rank <= 1 ? 3.5 : ($rank <= 3 ? 2.5 : ($rank <= 10 ? 1.8 : 1.0));
        $baseBp = (int)min(650, 80 + (21 - min($rank, 20)) * 28);
        $roll = random_int(1, 10000);
        if ($roll > (int)min(900, $baseBp * $mult)) {
            return;
        }
        $rows = $db->query(
            "SELECT * FROM artifacts WHERE drop_world_boss_weight > 0 ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }
        $total = 0;
        foreach ($rows as $r) {
            $total += (((int)$r['drop_world_boss_weight']) * (int)round($mult * 100)) / 100;
        }
        $pick = random_int(1, max(1, (int)$total));
        $acc = 0;
        foreach ($rows as $r) {
            $w = (((int)$r['drop_world_boss_weight']) * (int)round($mult * 100)) / 100;
            $acc += max(1, (int)$w);
            if ($pick <= $acc) {
                $this->grantArtifact($db, $userId, (int)$r['id'], 'world_boss');
                return;
            }
        }
        $last = $rows[count($rows) - 1];
        $this->grantArtifact($db, $userId, (int)$last['id'], 'world_boss');
    }

    public function rollDungeonArtifact(PDO $db, int $userId, int $difficulty): void
    {
        if (!$this->tablesAvailable($db) || $userId < 1) {
            return;
        }
        $d = max(1, $difficulty);
        $bp = (int)min(750, 120 + $d * 95);
        if (random_int(1, 10000) > $bp) {
            return;
        }
        $rows = $db->query(
            "SELECT * FROM artifacts WHERE drop_dungeon_weight > 0 ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }
        $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['drop_dungeon_weight'];
        }
        $pick = random_int(1, max(1, $total));
        $acc = 0;
        foreach ($rows as $r) {
            $acc += (int)$r['drop_dungeon_weight'];
            if ($pick <= $acc) {
                $this->grantArtifact($db, $userId, (int)$r['id'], 'dungeon');
                return;
            }
        }
    }

    /**
     * @return bool true if a new row was inserted
     */
    public function grantArtifact(PDO $db, int $userId, int $artifactId, string $source): bool
    {
        if (!$this->tablesAvailable($db) || $artifactId < 1) {
            return false;
        }
        $st = $db->prepare('SELECT * FROM artifacts WHERE id = ? LIMIT 1');
        $st->execute([$artifactId]);
        $def = $st->fetch(PDO::FETCH_ASSOC);
        if (!$def) {
            return false;
        }
        if (!empty($def['is_unique'])) {
            $x = $db->prepare('SELECT id FROM user_artifacts WHERE user_id = ? AND artifact_id = ? LIMIT 1');
            $x->execute([$userId, $artifactId]);
            if ($x->fetch()) {
                return false;
            }
        }
        $ins = $db->prepare(
            'INSERT INTO user_artifacts (user_id, artifact_id, evolution_tier, equip_slot, active_slot, acquired_source)
             VALUES (?, ?, 1, NULL, NULL, ?)'
        );
        $ins->execute([$userId, $artifactId, $source]);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArtifactsPageState(int $userId): array
    {
        $empty = [
            'available' => false,
            'inventory' => [],
            'max_equip' => self::MAX_EQUIP_SLOTS,
            'max_active' => self::MAX_ACTIVE_SLOTS,
        ];
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return $empty;
            }
            $st = $db->prepare(
                'SELECT ua.id AS user_artifact_id, ua.user_id, ua.artifact_id, ua.evolution_tier, ua.equip_slot, ua.active_slot, ua.acquired_source, ua.acquired_at,
                        a.*
                 FROM user_artifacts ua
                 INNER JOIN artifacts a ON a.id = ua.artifact_id
                 WHERE ua.user_id = ?
                 ORDER BY ua.equip_slot IS NULL, ua.equip_slot ASC, ua.active_slot IS NULL, ua.active_slot ASC, ua.id DESC'
            );
            $st->execute([$userId]);
            $inv = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return [
                'available' => true,
                'inventory' => $inv,
                'max_equip' => self::MAX_EQUIP_SLOTS,
                'max_active' => self::MAX_ACTIVE_SLOTS,
                'modifiers_preview' => $this->getAggregatedCombatModifiers($userId),
            ];
        } catch (PDOException $e) {
            error_log('ArtifactService::getArtifactsPageState ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function setEquipSlot(int $userId, int $userArtifactId, ?int $slot): array
    {
        if ($userArtifactId < 1) {
            return ['success' => false, 'error' => 'Invalid artifact.'];
        }
        if ($slot !== null && ($slot < 1 || $slot > self::MAX_EQUIP_SLOTS)) {
            return ['success' => false, 'error' => 'Invalid equip slot.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Artifacts not installed.'];
            }
            $db->beginTransaction();
            $row = $this->lockUserArtifact($db, $userId, $userArtifactId);
            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Artifact not found.'];
            }
            if (empty($row['can_equip'])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This relic cannot be equipped in a gear socket.'];
            }
            if ($slot !== null) {
                $c = $db->prepare('SELECT id FROM user_artifacts WHERE user_id = ? AND equip_slot = ? AND id != ? LIMIT 1 FOR UPDATE');
                $c->execute([$userId, $slot, $userArtifactId]);
                if ($c->fetch()) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'That equip socket is already occupied.'];
                }
            }
            $db->prepare('UPDATE user_artifacts SET equip_slot = ?, active_slot = CASE WHEN ? IS NOT NULL THEN NULL ELSE active_slot END WHERE id = ? AND user_id = ?')
                ->execute([$slot, $slot, $userArtifactId, $userId]);
            $db->commit();
            return ['success' => true, 'message' => $slot === null ? 'Artifact unequipped.' : 'Artifact socketed.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ArtifactService::setEquipSlot ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not update equip.'];
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function setActiveSlot(int $userId, int $userArtifactId, ?int $slot): array
    {
        if ($userArtifactId < 1) {
            return ['success' => false, 'error' => 'Invalid artifact.'];
        }
        if ($slot !== null && ($slot < 1 || $slot > self::MAX_ACTIVE_SLOTS)) {
            return ['success' => false, 'error' => 'Invalid active slot.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Artifacts not installed.'];
            }
            $db->beginTransaction();
            $row = $this->lockUserArtifact($db, $userId, $userArtifactId);
            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Artifact not found.'];
            }
            if (empty($row['can_active'])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This relic cannot occupy an active aura slot.'];
            }
            if ($slot !== null) {
                $c = $db->prepare('SELECT id FROM user_artifacts WHERE user_id = ? AND active_slot = ? AND id != ? LIMIT 1 FOR UPDATE');
                $c->execute([$userId, $slot, $userArtifactId]);
                if ($c->fetch()) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'That active socket is already occupied.'];
                }
            }
            $db->prepare('UPDATE user_artifacts SET active_slot = ?, equip_slot = CASE WHEN ? IS NOT NULL THEN NULL ELSE equip_slot END WHERE id = ? AND user_id = ?')
                ->execute([$slot, $slot, $userArtifactId, $userId]);
            $db->commit();
            return ['success' => true, 'message' => $slot === null ? 'Artifact removed from active aura.' : 'Artifact attuned as active.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ArtifactService::setActiveSlot ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not update active socket.'];
        }
    }

    /**
     * Evolve an evolving artifact instance (gold + spirit stones).
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function evolveUserArtifact(int $userId, int $userArtifactId): array
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Artifacts not installed.'];
            }
            $db->beginTransaction();
            $row = $this->lockUserArtifact($db, $userId, $userArtifactId);
            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Artifact not found.'];
            }
            if (empty($row['is_evolving'])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This relic does not evolve.'];
            }
            $tier = (int)($row['evolution_tier'] ?? 1);
            $max = max(1, (int)$row['evolution_max_tier']);
            if ($tier >= $max) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Already at max evolution.'];
            }
            $goldCost = 900 + $tier * 550;
            $stoneCost = 4 + $tier * 2;
            $uw = $db->prepare('SELECT gold, spirit_stones FROM users WHERE id = ? FOR UPDATE');
            $uw->execute([$userId]);
            $w = $uw->fetch(PDO::FETCH_ASSOC);
            if (!$w || (int)$w['gold'] < $goldCost || (int)$w['spirit_stones'] < $stoneCost) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Need ' . $goldCost . ' gold and ' . $stoneCost . ' spirit stones.'];
            }
            $db->prepare('UPDATE users SET gold = gold - ?, spirit_stones = spirit_stones - ? WHERE id = ?')
                ->execute([$goldCost, $stoneCost, $userId]);
            $db->prepare('UPDATE user_artifacts SET evolution_tier = evolution_tier + 1 WHERE id = ? AND user_id = ?')
                ->execute([$userArtifactId, $userId]);
            $db->commit();
            return ['success' => true, 'message' => (string)($row['art_name'] ?? 'Relic') . ' evolves (tier ' . ($tier + 1) . '/' . $max . ').'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ArtifactService::evolveUserArtifact ' . $e->getMessage());
            return ['success' => false, 'error' => 'Evolution failed.'];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockUserArtifact(PDO $db, int $userId, int $userArtifactId): ?array
    {
        $st = $db->prepare(
            'SELECT ua.id, ua.user_id, ua.artifact_id, ua.evolution_tier, ua.equip_slot, ua.active_slot,
                    a.can_equip, a.can_active, a.is_evolving, a.evolution_max_tier, a.name AS art_name
             FROM user_artifacts ua
             INNER JOIN artifacts a ON a.id = ua.artifact_id
             WHERE ua.id = ? AND ua.user_id = ?
             FOR UPDATE'
        );
        $st->execute([$userArtifactId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
