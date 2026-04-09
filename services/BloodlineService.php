<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/CultivationManualService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Bloodlines: unlock via boss damage, tribulation wins, PvP wins, or dungeon clears.
 * Only one bloodline may be active. Awakening deepens passives and special effects.
 */
class BloodlineService
{
    public const AWAKENING_MAX_DEFAULT = 5;

    /** Effective passives scale: base × (1 + scale × (awakening_level − 1)). */
    private const AWAKENING_PASSIVE_SCALE = 0.12;

    /** Added multiplier per mutation stack after the first template grants a unique strain. */
    private const MUTATION_STACK_PASSIVE_BONUS = 0.012;

    private static ?bool $evolutionOk = null;

    private static ?bool $abilitiesOk = null;

    /** @var array<int, int> */
    private static array $userLevelCache = [];

    /** +0.25% combat scaling per character level, cap +35%. */
    private const LEVEL_SCALE_PER_LEVEL = 0.0025;

    private const LEVEL_SCALE_CAP = 0.35;

    /** Gold / spirit stone costs to awaken (level → next level). */
    private function awakeningCost(int $currentAwakeningLevel): array
    {
        $next = $currentAwakeningLevel + 1;
        $gold = 600 + $currentAwakeningLevel * 450;
        $stones = 5 + (int)floor($currentAwakeningLevel * 1.5);
        return ['gold' => $gold, 'spirit_stones' => $stones, 'from' => $currentAwakeningLevel, 'to' => $next];
    }

    private static ?bool $tablesOk = null;

    private function tablesAvailable(PDO $db): bool
    {
        if (self::$tablesOk !== null) {
            return self::$tablesOk;
        }
        try {
            $st = $db->prepare('SELECT 1 FROM bloodlines LIMIT 1');
            $st->execute();
            self::$tablesOk = true;
        } catch (\Throwable $e) {
            self::$tablesOk = false;
        }
        return self::$tablesOk;
    }

    private function evolutionFeatureAvailable(PDO $db): bool
    {
        if (self::$evolutionOk !== null) {
            return self::$evolutionOk;
        }
        try {
            $s1 = $db->prepare('SELECT 1 FROM bloodline_evolution LIMIT 1');
            $s1->execute();
            $s2 = $db->prepare('SELECT evolution_tier FROM user_bloodlines LIMIT 1');
            $s2->execute();
            self::$evolutionOk = true;
        } catch (\Throwable $e) {
            self::$evolutionOk = false;
        }
        return self::$evolutionOk;
    }

    private function abilitiesFeatureAvailable(PDO $db): bool
    {
        if (self::$abilitiesOk !== null) {
            return self::$abilitiesOk;
        }
        try {
            $st = $db->prepare('SELECT 1 FROM bloodline_abilities LIMIT 1');
            $st->execute();
            self::$abilitiesOk = true;
        } catch (\Throwable $e) {
            self::$abilitiesOk = false;
        }
        return self::$abilitiesOk;
    }

    public function levelScalingMultiplier(int $level): float
    {
        $lv = max(1, $level);
        return 1.0 + min(self::LEVEL_SCALE_CAP, ($lv - 1) * self::LEVEL_SCALE_PER_LEVEL);
    }

    private function fetchUserLevel(int $userId): int
    {
        if (isset(self::$userLevelCache[$userId])) {
            return self::$userLevelCache[$userId];
        }
        try {
            $db = Database::getConnection();
            $st = $db->prepare('SELECT level FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $v = max(1, (int)$st->fetchColumn());
            self::$userLevelCache[$userId] = $v;
            return $v;
        } catch (PDOException $e) {
            return 1;
        }
    }

    /**
     * Awakening × evolution/mutation × character level (no resonance).
     *
     * @param array<string, mixed> $row Active bloodline row
     */
    public function ancestralPowerMultiplier(int $userId, array $row): float
    {
        $lv = $this->fetchUserLevel($userId);
        return $this->awakeningMultiplier((int)($row['awakening_level'] ?? 1))
            * $this->lineagePowerMultiplier($row)
            * $this->levelScalingMultiplier($lv);
    }

    /**
     * Dao element + active manual count resonance when ability is present.
     *
     * @param array<string, mixed> $row Joined active bloodline including ability_* columns
     */
    public function resonanceMultiplier(int $userId, array $row): float
    {
        if (empty($row['ability_key'])) {
            return 1.0;
        }
        $daoBonus = (float)($row['ability_resonance_dao_bonus_pct'] ?? 0.0);
        $manualStep = (float)($row['ability_resonance_manual_bonus_pct'] ?? 0.0);
        $manualCap = (float)($row['ability_resonance_manual_bonus_cap_pct'] ?? 0.0);
        $minManuals = max(0, (int)($row['ability_resonance_min_manuals'] ?? 1));
        $wantEl = isset($row['ability_resonance_dao_element']) && $row['ability_resonance_dao_element'] !== null && $row['ability_resonance_dao_element'] !== ''
            ? (string)$row['ability_resonance_dao_element']
            : null;

        $mult = 1.0;
        if ($wantEl !== null && $daoBonus > 0) {
            try {
                $db = Database::getConnection();
                $st = $db->prepare(
                    'SELECT d.element FROM users u INNER JOIN dao_paths d ON d.id = u.dao_path_id WHERE u.id = ? LIMIT 1'
                );
                $st->execute([$userId]);
                $el = $st->fetchColumn();
                if ($el !== false && (string)$el === $wantEl) {
                    $mult *= 1.0 + $daoBonus;
                }
            } catch (PDOException $e) {
                // ignore
            }
        }

        $manualSvc = new CultivationManualService();
        $fx = $manualSvc->getActiveEffectsForUser($userId);
        $manualCount = isset($fx['manuals']) && is_array($fx['manuals']) ? count($fx['manuals']) : 0;
        if ($manualCount >= $minManuals && $manualStep > 0 && $manualCap > 0) {
            $extra = ($manualCount - $minManuals + 1) * $manualStep;
            $mult *= 1.0 + min($manualCap, $extra);
        }

        return $mult;
    }

    /**
     * Scaled unique ability combat stats (after ancestral power × resonance).
     *
     * @return array{
     *   damage_out_pct: float,
     *   damage_taken_reduction_pct: float,
     *   crit_chance_bonus: float,
     *   dodge_bonus: float,
     *   counter_bonus: float,
     *   lifesteal_bonus_pct: float
     * }
     */
    public function getScaledAbilityCombat(int $userId): array
    {
        $empty = [
            'damage_out_pct' => 0.0,
            'damage_taken_reduction_pct' => 0.0,
            'crit_chance_bonus' => 0.0,
            'dodge_bonus' => 0.0,
            'counter_bonus' => 0.0,
            'lifesteal_bonus_pct' => 0.0,
        ];
        $row = $this->getActiveBloodline($userId);
        if (!$row || empty($row['ability_key'])) {
            return $empty;
        }
        $scale = $this->ancestralPowerMultiplier($userId, $row) * $this->resonanceMultiplier($userId, $row);
        return [
            'damage_out_pct' => (float)($row['ability_combat_damage_out_pct'] ?? 0) * $scale,
            'damage_taken_reduction_pct' => (float)($row['ability_combat_damage_taken_reduction_pct'] ?? 0) * $scale,
            'crit_chance_bonus' => (float)($row['ability_combat_crit_chance_bonus'] ?? 0) * $scale,
            'dodge_bonus' => (float)($row['ability_combat_dodge_bonus'] ?? 0) * $scale,
            'counter_bonus' => (float)($row['ability_combat_counter_bonus'] ?? 0) * $scale,
            'lifesteal_bonus_pct' => (float)($row['ability_combat_lifesteal_bonus_pct'] ?? 0) * $scale,
        ];
    }

    public function getMatchupOutgoingMultiplier(int $attackerUserId, int $defenderUserId): float
    {
        if ($attackerUserId < 1 || $defenderUserId < 1 || $attackerUserId === $defenderUserId) {
            return 1.0;
        }
        try {
            $db = Database::getConnection();
            if (!$this->abilitiesFeatureAvailable($db)) {
                return 1.0;
            }
            $a = $this->getActiveBloodlineId($db, $attackerUserId);
            $d = $this->getActiveBloodlineId($db, $defenderUserId);
            if ($a === null || $d === null) {
                return 1.0;
            }
            $st = $db->prepare(
                'SELECT matchup_outgoing_mult FROM bloodline_interactions WHERE attacker_bloodline_id = ? AND defender_bloodline_id = ? LIMIT 1'
            );
            $st->execute([$a, $d]);
            $m = $st->fetchColumn();
            if ($m === false) {
                return 1.0;
            }
            return max(0.5, min(1.35, (float)$m));
        } catch (PDOException $e) {
            return 1.0;
        }
    }

    private function getActiveBloodlineId(PDO $db, int $userId): ?int
    {
        try {
            $st = $db->prepare('SELECT bloodline_id FROM user_bloodlines WHERE user_id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$userId]);
            $v = $st->fetchColumn();
            if ($v === false) {
                return null;
            }
            $id = (int)$v;
            return $id > 0 ? $id : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    private function evolutionTierMultiplier(string $tier): float
    {
        return match ($tier) {
            'evolved' => 1.08,
            'transcendent' => 1.18,
            'mythic' => 1.30,
            default => 1.0,/* awakened */
        };
    }

    /**
     * Lineage power from evolution tier × optional mutation (applies to passives and bloodline special effects).
     *
     * @param array<string, mixed> $row Active or unlocked bloodline row (join mutation_passive_bonus_mult when available).
     */
    private function lineagePowerMultiplier(array $row): float
    {
        $tier = isset($row['evolution_tier']) ? (string)$row['evolution_tier'] : 'awakened';
        $t = $this->evolutionTierMultiplier($tier);
        $mutId = isset($row['mutation_template_id']) ? (int)$row['mutation_template_id'] : 0;
        if ($mutId < 1) {
            return $t;
        }
        $bonus = (float)($row['mutation_passive_bonus_mult'] ?? 0.0);
        $stack = max(0, (int)($row['mutation_stack'] ?? 0));
        $m = 1.0 + $bonus + $stack * self::MUTATION_STACK_PASSIVE_BONUS;
        return $t * $m;
    }

    /**
     * Load catalog rows without chaining query()->fetchAll() (query() can return false; Error is not PDOException).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadBloodlinesCatalogRows(PDO $db, bool $withAbilities): array
    {
        if ($withAbilities) {
            $sql = 'SELECT b.*, ba.ability_key AS catalog_ability_key, ba.name AS catalog_ability_name, ba.description AS catalog_ability_description,
                            ba.resonance_dao_element AS catalog_resonance_element
                     FROM bloodlines b
                     LEFT JOIN bloodline_abilities ba ON ba.bloodline_id = b.id
                     ORDER BY b.sort_order ASC, b.id ASC';
        } else {
            $sql = 'SELECT * FROM bloodlines ORDER BY sort_order ASC, id ASC';
        }
        $st = $db->prepare($sql);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Refresh unlocks from live stats. Returns number of newly granted bloodlines.
     */
    public function syncUnlocksForUser(int $userId): int
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return 0;
            }

            $progress = $this->fetchProgress($db, $userId);
            $stmt = $db->prepare('SELECT id FROM user_bloodlines WHERE user_id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$userId]);
            $hasActive = (bool)$stmt->fetch();

            $catalog = $this->loadBloodlinesCatalogRows($db, false);
            $newCount = 0;

            foreach ($catalog as $bl) {
                $bid = (int)$bl['id'];
                $chk = $db->prepare('SELECT 1 FROM user_bloodlines WHERE user_id = ? AND bloodline_id = ? LIMIT 1');
                $chk->execute([$userId, $bid]);
                if ($chk->fetch()) {
                    continue;
                }
                $need = (int)$bl['unlock_value'];
                $type = (string)$bl['unlock_type'];
                $ok = match ($type) {
                    'boss_damage' => $progress['boss_damage'] >= $need,
                    'tribulation_wins' => $progress['tribulation_wins'] >= $need,
                    'pvp_wins' => $progress['pvp_wins'] >= $need,
                    'dungeon_clears' => $progress['dungeon_clears'] >= $need,
                    default => false,
                };
                if (!$ok) {
                    continue;
                }

                $makeActive = !$hasActive ? 1 : 0;
                $ins = $db->prepare(
                    'INSERT INTO user_bloodlines (user_id, bloodline_id, awakening_level, is_active) VALUES (?, ?, 1, ?)'
                );
                $ins->execute([$userId, $bid, $makeActive]);
                $newCount++;
                if ($makeActive === 1) {
                    $hasActive = true;
                }
            }

            return $newCount;
        } catch (\Throwable $e) {
            error_log('BloodlineService::syncUnlocksForUser ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return array{boss_damage: int, tribulation_wins: int, pvp_wins: int, dungeon_clears: int}
     */
    public function fetchProgress(PDO $db, int $userId): array
    {
        $boss = 0;
        try {
            $s = $db->prepare('SELECT COALESCE(SUM(damage_dealt), 0) FROM boss_damage_log WHERE user_id = ?');
            $s->execute([$userId]);
            $boss = (int)$s->fetchColumn();
        } catch (PDOException $e) {
            $boss = 0;
        }

        $trib = 0;
        try {
            $s = $db->prepare('SELECT COUNT(*) FROM tribulations WHERE user_id = ? AND success = 1');
            $s->execute([$userId]);
            $trib = (int)$s->fetchColumn();
        } catch (PDOException $e) {
            $trib = 0;
        }

        $pvp = 0;
        try {
            $s = $db->prepare('SELECT wins FROM users WHERE id = ? LIMIT 1');
            $s->execute([$userId]);
            $pvp = (int)$s->fetchColumn();
        } catch (PDOException $e) {
            $pvp = 0;
        }

        $dung = 0;
        try {
            $s = $db->prepare('SELECT COUNT(*) FROM dungeon_runs WHERE user_id = ? AND is_completed = 1');
            $s->execute([$userId]);
            $dung = (int)$s->fetchColumn();
        } catch (PDOException $e) {
            $dung = 0;
        }

        return [
            'boss_damage' => $boss,
            'tribulation_wins' => $trib,
            'pvp_wins' => $pvp,
            'dungeon_clears' => $dung,
        ];
    }

    /**
     * Active bloodline row joined with definition, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveBloodline(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return null;
            }
            $evo = $this->evolutionFeatureAvailable($db);
            $join = $evo
                ? ' LEFT JOIN bloodline_mutation_templates mut ON mut.id = ub.mutation_template_id'
                : '';
            $extra = $evo
                ? ', mut.mutation_key AS mutation_key, mut.display_name AS mutation_display_name, mut.description AS mutation_description, mut.passive_bonus_mult AS mutation_passive_bonus_mult'
                : '';
            $abJoin = '';
            $abSel = '';
            if ($this->abilitiesFeatureAvailable($db)) {
                $abJoin = ' LEFT JOIN bloodline_abilities ab ON ab.bloodline_id = b.id';
                $abSel = ', ab.ability_key AS ability_key, ab.name AS ability_name, ab.description AS ability_description,
                    ab.combat_damage_out_pct AS ability_combat_damage_out_pct,
                    ab.combat_damage_taken_reduction_pct AS ability_combat_damage_taken_reduction_pct,
                    ab.combat_crit_chance_bonus AS ability_combat_crit_chance_bonus,
                    ab.combat_dodge_bonus AS ability_combat_dodge_bonus,
                    ab.combat_counter_bonus AS ability_combat_counter_bonus,
                    ab.combat_lifesteal_bonus_pct AS ability_combat_lifesteal_bonus_pct,
                    ab.resonance_dao_element AS ability_resonance_dao_element,
                    ab.resonance_dao_bonus_pct AS ability_resonance_dao_bonus_pct,
                    ab.resonance_min_manuals AS ability_resonance_min_manuals,
                    ab.resonance_manual_bonus_pct AS ability_resonance_manual_bonus_pct,
                    ab.resonance_manual_bonus_cap_pct AS ability_resonance_manual_bonus_cap_pct';
            }
            $stmt = $db->prepare(
                "SELECT ub.*, b.*{$extra}{$abSel}
                 FROM user_bloodlines ub
                 INNER JOIN bloodlines b ON b.id = ub.bloodline_id{$join}{$abJoin}
                 WHERE ub.user_id = ? AND ub.is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('BloodlineService::getActiveBloodline ' . $e->getMessage());
            return null;
        }
    }

    private function awakeningMultiplier(int $awakeningLevel): float
    {
        $lv = max(1, $awakeningLevel);
        return 1.0 + self::AWAKENING_PASSIVE_SCALE * ($lv - 1);
    }

    /**
     * Scaled passive % bonuses for the active bloodline.
     *
     * @return array{attack_pct: float, defense_pct: float, max_chi_pct: float, cultivation_pct: float, breakthrough_pct: float}
     */
    public function getPassiveBonuses(int $userId): array
    {
        $empty = [
            'attack_pct' => 0.0,
            'defense_pct' => 0.0,
            'max_chi_pct' => 0.0,
            'cultivation_pct' => 0.0,
            'breakthrough_pct' => 0.0,
        ];
        $row = $this->getActiveBloodline($userId);
        if (!$row) {
            return $empty;
        }
        $m = $this->ancestralPowerMultiplier($userId, $row) * $this->resonanceMultiplier($userId, $row);
        return [
            'attack_pct' => (float)$row['base_attack_pct'] * $m,
            'defense_pct' => (float)$row['base_defense_pct'] * $m,
            'max_chi_pct' => (float)$row['base_max_chi_pct'] * $m,
            'cultivation_pct' => (float)$row['base_cultivation_pct'] * $m,
            'breakthrough_pct' => (float)$row['base_breakthrough_pct'] * $m,
        ];
    }

    public function getWorldBossDamageMultiplier(int $userId): float
    {
        $row = $this->getActiveBloodline($userId);
        if (!$row || empty($row['effect_key']) || (string)$row['effect_key'] !== 'world_boss_damage') {
            return 1.0;
        }
        $m = $this->ancestralPowerMultiplier($userId, $row) * $this->resonanceMultiplier($userId, $row);
        $bonus = (float)$row['effect_value'] * $m;
        return max(1.0, 1.0 + $bonus);
    }

    /** Additional tribulation damage reduction (added before cap). */
    public function getTribulationExtraMitigation(int $userId): float
    {
        $row = $this->getActiveBloodline($userId);
        if (!$row || empty($row['effect_key']) || (string)$row['effect_key'] !== 'tribulation_mitigation') {
            return 0.0;
        }
        $m = $this->ancestralPowerMultiplier($userId, $row) * $this->resonanceMultiplier($userId, $row);
        return min(0.05, (float)$row['effect_value'] * $m * 1.1);
    }

    public function getDungeonGoldMultiplier(int $userId): float
    {
        $row = $this->getActiveBloodline($userId);
        if (!$row || empty($row['effect_key']) || (string)$row['effect_key'] !== 'dungeon_gold_reward') {
            return 1.0;
        }
        $m = $this->ancestralPowerMultiplier($userId, $row) * $this->resonanceMultiplier($userId, $row);
        return max(1.0, 1.0 + (float)$row['effect_value'] * $m);
    }

    /**
     * Full UI payload: catalog progress, unlocked list, active id, awakening costs.
     *
     * @return array<string, mixed>
     */
    public function getBloodlinePageState(int $userId): array
    {
        $empty = [
            'available' => false,
            'progress' => [],
            'catalog' => [],
            'unlocked' => [],
            'active_bloodline_id' => null,
            'evolution_enabled' => false,
            'evolution_previews' => [],
            'abilities_enabled' => false,
            'player_level' => 1,
            'dao_element' => null,
            'active_scaling' => null,
        ];
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return $empty;
            }

            $this->syncUnlocksForUser($userId);
            $progress = $this->fetchProgress($db, $userId);
            $abilitiesOn = $this->abilitiesFeatureAvailable($db);
            $catalog = $this->loadBloodlinesCatalogRows($db, $abilitiesOn);

            $playerLevel = 1;
            $daoElement = null;
            try {
                $pst = $db->prepare(
                    'SELECT u.level, d.element AS dao_element FROM users u
                     LEFT JOIN dao_paths d ON d.id = u.dao_path_id
                     WHERE u.id = ? LIMIT 1'
                );
                $pst->execute([$userId]);
                $pr = $pst->fetch(PDO::FETCH_ASSOC);
                if ($pr) {
                    $playerLevel = max(1, (int)$pr['level']);
                    if (!empty($pr['dao_element'])) {
                        $daoElement = (string)$pr['dao_element'];
                    }
                }
            } catch (PDOException $e) {
                $playerLevel = 1;
            }

            $evoOn = $this->evolutionFeatureAvailable($db);
            $sel = 'SELECT ub.bloodline_id, ub.awakening_level, ub.is_active, ub.unlocked_at';
            if ($evoOn) {
                $sel .= ', ub.evolution_tier, ub.mutation_template_id, ub.mutation_stack';
            }
            $sel .= ', b.*';
            if ($evoOn) {
                $sel .= ', mut.display_name AS mutation_display_name, mut.passive_bonus_mult AS mutation_passive_bonus_mult, mut.description AS mutation_description';
            }
            if ($abilitiesOn) {
                $sel .= ', abu.name AS unlocked_ability_name, abu.description AS unlocked_ability_description, abu.ability_key AS unlocked_ability_key';
            }
            $from = ' FROM user_bloodlines ub INNER JOIN bloodlines b ON b.id = ub.bloodline_id';
            if ($evoOn) {
                $from .= ' LEFT JOIN bloodline_mutation_templates mut ON mut.id = ub.mutation_template_id';
            }
            if ($abilitiesOn) {
                $from .= ' LEFT JOIN bloodline_abilities abu ON abu.bloodline_id = ub.bloodline_id';
            }
            $ustmt = $db->prepare($sel . $from . ' WHERE ub.user_id = ? ORDER BY b.sort_order ASC, b.id ASC');
            $ustmt->execute([$userId]);
            $unlocked = $ustmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $evolutionPreviews = [];
            if ($evoOn) {
                foreach ($unlocked as $u) {
                    $evBid = (int)$u['bloodline_id'];
                    $tier = (string)($u['evolution_tier'] ?? 'awakened');
                    $preview = $this->buildEvolutionAttemptPreview($db, $userId, $evBid, $tier);
                    if ($preview !== null) {
                        $evolutionPreviews[$evBid] = $preview;
                    }
                }
            }

            $activeId = null;
            foreach ($unlocked as $u) {
                if ((int)($u['is_active'] ?? 0) === 1) {
                    $activeId = (int)$u['bloodline_id'];
                    break;
                }
            }

            $enriched = [];
            foreach ($catalog as $bl) {
                $bid = (int)$bl['id'];
                $type = (string)$bl['unlock_type'];
                $need = (int)$bl['unlock_value'];
                $cur = $progress[$type] ?? 0;
                $enriched[] = array_merge($bl, [
                    'progress_current' => $cur,
                    'progress_needed' => $need,
                    'progress_met' => $cur >= $need,
                ]);
            }

            $activeRow = $activeId !== null ? $this->getActiveBloodline($userId) : null;
            $activeScaling = null;
            if ($activeRow !== null) {
                $aw = $this->awakeningMultiplier((int)($activeRow['awakening_level'] ?? 1));
                $lin = $this->lineagePowerMultiplier($activeRow);
                $lvM = $this->levelScalingMultiplier($playerLevel);
                $res = $this->resonanceMultiplier($userId, $activeRow);
                $activeScaling = [
                    'level' => $playerLevel,
                    'awakening_mult' => $aw,
                    'lineage_mult' => $lin,
                    'level_scale_mult' => $lvM,
                    'resonance_mult' => $res,
                    'passive_total_mult' => $aw * $lin * $lvM * $res,
                    'ability_combat' => $this->getScaledAbilityCombat($userId),
                ];
            }

            return [
                'available' => true,
                'progress' => $progress,
                'catalog' => $enriched,
                'unlocked' => $unlocked,
                'active_bloodline_id' => $activeId,
                'passive_preview' => $this->getPassiveBonuses($userId),
                'evolution_enabled' => $evoOn,
                'evolution_previews' => $evolutionPreviews,
                'abilities_enabled' => $abilitiesOn,
                'player_level' => $playerLevel,
                'dao_element' => $daoElement,
                'active_scaling' => $activeScaling,
            ];
        } catch (PDOException $e) {
            error_log('BloodlineService::getBloodlinePageState ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function setActiveBloodline(int $userId, int $bloodlineId): array
    {
        if ($bloodlineId < 1) {
            return ['success' => false, 'error' => 'Invalid bloodline.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Bloodlines are not available yet.'];
            }
            $own = $db->prepare('SELECT 1 FROM user_bloodlines WHERE user_id = ? AND bloodline_id = ? LIMIT 1');
            $own->execute([$userId, $bloodlineId]);
            if (!$own->fetch()) {
                return ['success' => false, 'error' => 'You have not awakened that bloodline yet.'];
            }
            $db->prepare('UPDATE user_bloodlines SET is_active = 0 WHERE user_id = ?')->execute([$userId]);
            $db->prepare('UPDATE user_bloodlines SET is_active = 1 WHERE user_id = ? AND bloodline_id = ?')
                ->execute([$userId, $bloodlineId]);
            return ['success' => true, 'message' => 'Your active bloodline has been switched. Passives and bloodline effects now follow this lineage.'];
        } catch (PDOException $e) {
            error_log('BloodlineService::setActiveBloodline ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not update active bloodline.'];
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string, data?: array<string, int>}
     */
    public function awakenBloodline(int $userId, int $bloodlineId): array
    {
        if ($bloodlineId < 1) {
            return ['success' => false, 'error' => 'Invalid bloodline.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Bloodlines are not available yet.'];
            }
            $db->beginTransaction();

            $stmt = $db->prepare(
                'SELECT ub.awakening_level, b.awakening_max, b.name
                 FROM user_bloodlines ub
                 INNER JOIN bloodlines b ON b.id = ub.bloodline_id
                 WHERE ub.user_id = ? AND ub.bloodline_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$userId, $bloodlineId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Bloodline not found.'];
            }
            $current = max(1, (int)$row['awakening_level']);
            $maxAw = max(1, (int)$row['awakening_max']);
            if ($current >= $maxAw) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This bloodline is already fully awakened.'];
            }
            $cost = $this->awakeningCost($current);

            $u = $db->prepare('SELECT gold, spirit_stones FROM users WHERE id = ? FOR UPDATE');
            $u->execute([$userId]);
            $wallet = $u->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }
            if ((int)$wallet['gold'] < $cost['gold'] || (int)$wallet['spirit_stones'] < $cost['spirit_stones']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Need ' . $cost['gold'] . ' gold and ' . $cost['spirit_stones'] . ' spirit stones to awaken further.',
                ];
            }

            $db->prepare('UPDATE users SET gold = gold - ?, spirit_stones = spirit_stones - ? WHERE id = ?')
                ->execute([$cost['gold'], $cost['spirit_stones'], $userId]);
            $db->prepare('UPDATE user_bloodlines SET awakening_level = awakening_level + 1 WHERE user_id = ? AND bloodline_id = ?')
                ->execute([$userId, $bloodlineId]);

            $db->commit();
            return [
                'success' => true,
                'message' => (string)$row['name'] . ' stirs deeper in your veins (awakening ' . $cost['to'] . ').',
                'data' => ['awakening_level' => $cost['to']],
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('BloodlineService::awakenBloodline ' . $e->getMessage());
            return ['success' => false, 'error' => 'Awakening failed.'];
        }
    }

    public function awakeningCostPreview(int $bloodlineId, int $currentAwakeningLevel, int $awakeningMax): ?array
    {
        if ($currentAwakeningLevel >= $awakeningMax) {
            return null;
        }
        return $this->awakeningCost($currentAwakeningLevel);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildEvolutionAttemptPreview(PDO $db, int $userId, int $bloodlineId, string $currentTier): ?array
    {
        if ($currentTier === 'mythic') {
            return null;
        }
        $st = $db->prepare(
            'SELECT be.*, rt.name AS required_title_name, it.name AS required_item_name
             FROM bloodline_evolution be
             LEFT JOIN titles rt ON rt.id = be.required_title_id
             LEFT JOIN item_templates it ON it.id = be.required_item_template_id
             WHERE be.bloodline_id = ? AND be.from_tier = ?
             LIMIT 1'
        );
        $st->execute([$bloodlineId, $currentTier]);
        $rule = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return null;
        }

        $reqMatTier = (int)$rule['required_material_tier'];
        $reqMatQty = (int)$rule['required_material_qty'];
        $matHave = $this->totalMaterialQuantity($db, $userId, $reqMatTier);

        $itemTid = (int)($rule['required_item_template_id'] ?? 0);
        $reqItemQty = (int)$rule['required_item_qty'];
        $itemHave = $reqItemQty > 0 ? $this->totalItemTemplateQuantity($db, $userId, $itemTid) : 0;

        $titleMet = true;
        if (!empty($rule['required_title_id'])) {
            $tid = (int)$rule['required_title_id'];
            $chk = $db->prepare('SELECT 1 FROM user_titles WHERE user_id = ? AND title_id = ? LIMIT 1');
            $chk->execute([$userId, $tid]);
            $titleMet = (bool)$chk->fetch();
        }

        $g = $db->prepare('SELECT gold, spirit_stones FROM users WHERE id = ? LIMIT 1');
        $g->execute([$userId]);
        $w = $g->fetch(PDO::FETCH_ASSOC) ?: ['gold' => 0, 'spirit_stones' => 0];
        $gold = (int)$w['gold'];
        $stones = (int)$w['spirit_stones'];
        $needGold = (int)$rule['required_gold'];
        $needStones = (int)$rule['required_spirit_stones'];
        $failGold = (int)$rule['failure_extra_gold'];

        $matOk = $reqMatQty <= 0 || $matHave >= $reqMatQty;
        $itemOk = $reqItemQty <= 0 || $itemHave >= $reqItemQty;

        return [
            'next_tier' => (string)$rule['to_tier'],
            'success_chance_pct' => (float)$rule['success_chance_pct'],
            'mutation_chance_pct' => (float)$rule['mutation_chance_pct'],
            'required_gold' => $needGold,
            'required_spirit_stones' => $needStones,
            'failure_extra_gold' => $failGold,
            'failure_chi_loss_pct' => (float)$rule['failure_chi_loss_pct'],
            'failure_awakening_levels' => (int)$rule['failure_awakening_levels'],
            'required_title_name' => (string)($rule['required_title_name'] ?? ''),
            'required_item_name' => (string)($rule['required_item_name'] ?? ''),
            'required_material_tier' => $reqMatTier,
            'required_material_qty' => $reqMatQty,
            'material_have' => $matHave,
            'required_item_qty' => $reqItemQty,
            'item_have' => $itemHave,
            'title_met' => $titleMet,
            'gold_have' => $gold,
            'stones_have' => $stones,
            'can_attempt' => $titleMet && $matOk && $itemOk && $gold >= $needGold + $failGold && $stones >= $needStones,
        ];
    }

    private function totalMaterialQuantity(PDO $db, int $userId, int $tier): int
    {
        if ($tier < 1) {
            return 0;
        }
        try {
            $st = $db->prepare(
                "SELECT COALESCE(SUM(i.quantity), 0) FROM inventory i
                 INNER JOIN item_templates t ON t.id = i.item_template_id
                 WHERE i.user_id = ? AND i.is_equipped = 0 AND t.type = 'material' AND t.material_tier = ?"
            );
            $st->execute([$userId, $tier]);
            return (int)$st->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function totalItemTemplateQuantity(PDO $db, int $userId, int $templateId): int
    {
        if ($templateId < 1) {
            return 0;
        }
        try {
            $st = $db->prepare(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE user_id = ? AND item_template_id = ? AND is_equipped = 0'
            );
            $st->execute([$userId, $templateId]);
            return (int)$st->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $evolutionRule from bloodline_evolution
     */
    private function consumeMaterialsFromInventory(PDO $db, int $userId, int $tier, int $need): void
    {
        if ($need <= 0) {
            return;
        }
        $st = $db->prepare(
            "SELECT i.id, i.quantity FROM inventory i
             INNER JOIN item_templates t ON t.id = i.item_template_id
             WHERE i.user_id = ? AND i.is_equipped = 0 AND t.type = 'material' AND t.material_tier = ?
             ORDER BY i.id ASC FOR UPDATE"
        );
        $st->execute([$userId, $tier]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $left = $need;
        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }
            $q = (int)$r['quantity'];
            $id = (int)$r['id'];
            $take = min($left, $q);
            $newQ = $q - $take;
            if ($newQ <= 0) {
                $db->prepare('DELETE FROM inventory WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            } else {
                $db->prepare('UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([$newQ, $id, $userId]);
            }
            $left -= $take;
        }
        if ($left > 0) {
            throw new \RuntimeException('Not enough materials to consume.');
        }
    }

    private function consumeItemsByTemplate(PDO $db, int $userId, int $templateId, int $need): void
    {
        if ($need <= 0) {
            return;
        }
        $st = $db->prepare(
            'SELECT id, quantity FROM inventory WHERE user_id = ? AND item_template_id = ? AND is_equipped = 0 ORDER BY id ASC FOR UPDATE'
        );
        $st->execute([$userId, $templateId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $left = $need;
        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }
            $q = (int)$r['quantity'];
            $id = (int)$r['id'];
            $take = min($left, $q);
            $newQ = $q - $take;
            if ($newQ <= 0) {
                $db->prepare('DELETE FROM inventory WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            } else {
                $db->prepare('UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([$newQ, $id, $userId]);
            }
            $left -= $take;
        }
        if ($left > 0) {
            throw new \RuntimeException('Not enough special items to consume.');
        }
    }

    private function rollMutationTemplateId(PDO $db): int
    {
        try {
            $st = $db->prepare('SELECT id FROM bloodline_mutation_templates ORDER BY id ASC');
            $st->execute();
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                return 1;
            }
            /** @var array<int, string> $ids */
            return (int)$ids[array_rand($ids)];
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * Attempt tier evolution: consumes cost up front; success roll advances tier and may mutate lineage.
     * On failure: extra gold, chi loss, awakening loss (materials and catalyst already spent).
     *
     * @return array{success: bool, message?: string, error?: string, data?: array<string, mixed>}
     */
    public function attemptBloodlineEvolution(int $userId, int $bloodlineId): array
    {
        if ($bloodlineId < 1) {
            return ['success' => false, 'error' => 'Invalid bloodline.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db) || !$this->evolutionFeatureAvailable($db)) {
                return ['success' => false, 'error' => 'Bloodline evolution is not available. Run database_bloodline_evolution.sql (or reinstall bloodlines schema).'];
            }

            $db->beginTransaction();

            $lockUser = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lockUser->execute([$userId]);
            if (!$lockUser->fetch()) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }

            $ubStmt = $db->prepare(
                'SELECT ub.*, b.name AS bloodline_name FROM user_bloodlines ub
                 INNER JOIN bloodlines b ON b.id = ub.bloodline_id
                 WHERE ub.user_id = ? AND ub.bloodline_id = ?
                 FOR UPDATE'
            );
            $ubStmt->execute([$userId, $bloodlineId]);
            $ubRow = $ubStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ubRow) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Bloodline not found.'];
            }

            $currentTier = (string)($ubRow['evolution_tier'] ?? 'awakened');
            if ($currentTier === 'mythic') {
                $db->rollBack();
                return ['success' => false, 'error' => 'This bloodline has reached mythic tier.'];
            }

            $ruleStmt = $db->prepare(
                'SELECT * FROM bloodline_evolution WHERE bloodline_id = ? AND from_tier = ? LIMIT 1 FOR UPDATE'
            );
            $ruleStmt->execute([$bloodlineId, $currentTier]);
            $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                $db->rollBack();
                return ['success' => false, 'error' => 'No evolution path is defined for this tier.'];
            }

            $preview = $this->buildEvolutionAttemptPreview($db, $userId, $bloodlineId, $currentTier);
            if ($preview === null || empty($preview['can_attempt'])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'You do not meet the requirements for this evolution (titles, materials, Lineage Catalyst, gold including backlash reserve, or spirit stones).'];
            }

            $needGold = (int)$rule['required_gold'];
            $needStones = (int)$rule['required_spirit_stones'];
            $failGold = (int)$rule['failure_extra_gold'];
            $reqMatTier = (int)$rule['required_material_tier'];
            $reqMatQty = (int)$rule['required_material_qty'];
            $itemTid = (int)($rule['required_item_template_id'] ?? 0);
            $reqItemQty = (int)$rule['required_item_qty'];

            $u = $db->prepare('SELECT gold, spirit_stones, chi, max_chi FROM users WHERE id = ? FOR UPDATE');
            $u->execute([$userId]);
            $wallet = $u->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }
            if ((int)$wallet['gold'] < $needGold + $failGold || (int)$wallet['spirit_stones'] < $needStones) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Insufficient gold (include backlash reserve) or spirit stones.'];
            }

            if (!empty($rule['required_title_id'])) {
                $tid = (int)$rule['required_title_id'];
                $chk = $db->prepare('SELECT 1 FROM user_titles WHERE user_id = ? AND title_id = ? LIMIT 1');
                $chk->execute([$userId, $tid]);
                if (!$chk->fetch()) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Required title achievement not earned.'];
                }
            }

            if ($reqMatQty > 0 && $this->totalMaterialQuantity($db, $userId, $reqMatTier) < $reqMatQty) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Not enough materials of the required tier.'];
            }
            if ($reqItemQty > 0 && ($itemTid < 1 || $this->totalItemTemplateQuantity($db, $userId, $itemTid) < $reqItemQty)) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Not enough special items for this ritual.'];
            }

            $db->prepare('UPDATE users SET gold = gold - ?, spirit_stones = spirit_stones - ? WHERE id = ?')
                ->execute([$needGold, $needStones, $userId]);
            $this->consumeMaterialsFromInventory($db, $userId, $reqMatTier, $reqMatQty);
            if ($reqItemQty > 0 && $itemTid > 0) {
                $this->consumeItemsByTemplate($db, $userId, $itemTid, $reqItemQty);
            }

            $chanceBp = (int)round((float)$rule['success_chance_pct'] * 100);
            $chanceBp = max(0, min(10000, $chanceBp));
            $roll = random_int(1, 10000);
            $ok = $roll <= $chanceBp;

            if (!$ok) {
                $db->prepare('UPDATE users SET gold = gold - ? WHERE id = ?')->execute([$failGold, $userId]);
                $maxChi = max(1, (int)$wallet['max_chi']);
                $chiLossPct = (float)$rule['failure_chi_loss_pct'];
                $chiDrop = (int)floor($maxChi * $chiLossPct / 100);
                if ($chiDrop > 0) {
                    $db->prepare('UPDATE users SET chi = GREATEST(0, chi - ?) WHERE id = ?')->execute([$chiDrop, $userId]);
                }
                $awLose = (int)$rule['failure_awakening_levels'];
                if ($awLose > 0) {
                    $db->prepare(
                        'UPDATE user_bloodlines SET awakening_level = GREATEST(1, awakening_level - ?) WHERE user_id = ? AND bloodline_id = ?'
                    )->execute([$awLose, $userId, $bloodlineId]);
                }
                $db->commit();
                return [
                    'success' => false,
                    'error' => 'Evolution failed—the lineage recoils. Materials and catalyst are lost, gold suffers extra backlash, chi wavers, and awakening may slip.',
                    'data' => ['failure' => true, 'chi_lost' => $chiDrop, 'awakening_lost' => $awLose],
                ];
            }

            $nextTier = (string)$rule['to_tier'];
            $db->prepare('UPDATE user_bloodlines SET evolution_tier = ? WHERE user_id = ? AND bloodline_id = ?')
                ->execute([$nextTier, $userId, $bloodlineId]);

            $mutBp = (int)round((float)$rule['mutation_chance_pct'] * 100);
            $mutBp = max(0, min(10000, $mutBp));
            $mutMsg = '';
            if ($mutBp > 0 && random_int(1, 10000) <= $mutBp) {
                $curMut = (int)($ubRow['mutation_template_id'] ?? 0);
                if ($curMut < 1) {
                    $newMut = $this->rollMutationTemplateId($db);
                    $db->prepare(
                        'UPDATE user_bloodlines SET mutation_template_id = ?, mutation_stack = 0 WHERE user_id = ? AND bloodline_id = ?'
                    )->execute([$newMut, $userId, $bloodlineId]);
                    $nm = $db->prepare('SELECT display_name FROM bloodline_mutation_templates WHERE id = ? LIMIT 1');
                    $nm->execute([$newMut]);
                    $mutName = (string)($nm->fetchColumn() ?: 'Unique strain');
                    $mutMsg = ' Mutation awakens: ' . $mutName . '—this lineage is now uniquely yours.';
                } else {
                    $db->prepare(
                        'UPDATE user_bloodlines SET mutation_stack = LEAST(mutation_stack + 1, 50) WHERE user_id = ? AND bloodline_id = ?'
                    )->execute([$userId, $bloodlineId]);
                    $mutMsg = ' Your existing mutation deepens, amplifying ancestral power further.';
                }
            }

            $db->commit();
            $name = (string)$ubRow['bloodline_name'];
            return [
                'success' => true,
                'message' => $name . ' advances to ' . ucfirst($nextTier) . ' tier.' . $mutMsg,
                'data' => ['evolution_tier' => $nextTier],
            ];
        } catch (\RuntimeException $re) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => $re->getMessage()];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('BloodlineService::attemptBloodlineEvolution ' . $e->getMessage());
            return ['success' => false, 'error' => 'Evolution ritual failed.'];
        }
    }
}
