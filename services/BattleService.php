<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/DaoTechniqueService.php';
require_once __DIR__ . '/DaoRecord.php';
require_once __DIR__ . '/../core/Cache.php';

use Game\Config\Database;
use Game\Core\Cache;
use PDOException;

/**
 * Battle service for server-side battle simulation
 * All calculations happen server-side - never trust client values
 * 
 * Features:
 * - Passive procs: critical strike, lifesteal, dodge, counterattack
 * - Realm-based mechanics via RealmEffects class
 * - PvP chi loss penalty (5% on defeat)
 * - Uses StatCalculator for final stats
 */
class BattleService
{
    private const CRITICAL_STRIKE_CHANCE = 0.10; // 10% chance
    private const CRITICAL_DAMAGE_MULTIPLIER = 1.5; // 1.5x damage
    private const LIFESTEAL_CHANCE = 0.15; // 15% chance
    private const LIFESTEAL_PERCENTAGE = 0.30; // 30% of damage
    private const DODGE_CHANCE_BASE = 0.08; // 8% base dodge chance
    private const COUNTERATTACK_CHANCE = 0.12; // 12% chance
    private const DAMAGE_VARIANCE = 0.10; // ±10% variance
    private const MAX_TURNS = 50; // Prevent infinite battles
    private const PVP_CHI_LOSS_PERCENTAGE = 0.05; // 5% chi loss on defeat

    private StatCalculator $statCalculator;
    private RealmEffects $realmEffects;
    private DaoTechniqueService $daoTechniqueService;

    public function __construct()
    {
        $this->statCalculator = new StatCalculator();
        $this->realmEffects = new RealmEffects();
        $this->daoTechniqueService = new DaoTechniqueService();
    }

    /**
     * Simulate a full battle between two players
     * All calculations happen server-side
     * 
     * @param int $attackerId Attacker user ID
     * @param int $defenderId Defender user ID
     * @return array Battle result with winner, logs, and rating changes
     */
    public function simulateBattle(int $attackerId, int $defenderId, array $options = []): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Fetch both users with their realm data
            $attacker = $this->fetchUserWithRealm($db, $attackerId);
            $defender = $this->fetchUserWithRealm($db, $defenderId);

            if (!$attacker || !$defender) {
                throw new \Exception("One or both users not found");
            }

            // Get final stats using StatCalculator
            $attackerStats = $this->statCalculator->calculateFinalStats($attackerId);
            $defenderStats = $this->statCalculator->calculateFinalStats($defenderId);

            // Store initial values
            $attackerChiStart = (int)$attacker['chi'];
            $defenderChiStart = (int)$defender['chi'];
            $attackerRatingBefore = (float)$attacker['rating'];
            $defenderRatingBefore = (float)$defender['rating'];
            $attackerRealmLevel = (int)$attacker['realm_id'];
            $defenderRealmLevel = (int)$defender['realm_id'];

            // Get realm multipliers
            $attackerAttackMultiplier = (float)$attacker['attack_multiplier'];
            $attackerDefenseMultiplier = (float)$attacker['defense_multiplier'];
            $defenderAttackMultiplier = (float)$defender['attack_multiplier'];
            $defenderDefenseMultiplier = (float)$defender['defense_multiplier'];

            // Get realm effects
            $attackerEffects = $this->realmEffects->getAllEffects($attackerRealmLevel, $defenderRealmLevel);
            $defenderEffects = $this->realmEffects->getAllEffects($defenderRealmLevel, $attackerRealmLevel);
            $attackerTechniqueState = $this->daoTechniqueService->initializeCombatState(
                $attackerId,
                (bool)($options['attacker_use_techniques'] ?? true)
            );
            $defenderTechniqueState = $this->daoTechniqueService->initializeCombatState(
                $defenderId,
                (bool)($options['defender_use_techniques'] ?? true)
            );

            // Battle simulation
            $attackerChi = $attackerChiStart;
            $defenderChi = $defenderChiStart;
            $turn = 0;
            $logs = [];
            $startTime = time();

            // Turn-based battle loop
            while ($attackerChi > 0 && $defenderChi > 0 && $turn < self::MAX_TURNS) {
                $turn++;

                // Attacker's turn
                $attackerTurn = $this->processTurnWithStats(
                    $attackerStats['final'],
                    $defenderStats['final'],
                    $attackerRealmLevel,
                    $defenderRealmLevel,
                    $attackerEffects,
                    $defenderEffects,
                    $attackerChi,
                    $defenderChi,
                    $attackerId,
                    $defenderId,
                    null,
                    $attackerTechniqueState,
                    $defenderTechniqueState
                );

                $defenderChi = $attackerTurn['defender_chi_after'];
                $attackerChi = $attackerTurn['attacker_chi_after'];
                $attackerTurn['turn'] = $turn;
                $logs[] = $attackerTurn;

                if ($defenderChi <= 0) {
                    break; // Attacker wins
                }

                // Defender's turn
                $defenderTurn = $this->processTurnWithStats(
                    $defenderStats['final'],
                    $attackerStats['final'],
                    $defenderRealmLevel,
                    $attackerRealmLevel,
                    $defenderEffects,
                    $attackerEffects,
                    $defenderChi,
                    $attackerChi,
                    $defenderId,
                    $attackerId,
                    null,
                    $defenderTechniqueState,
                    $attackerTechniqueState
                );

                $attackerChi = $defenderTurn['defender_chi_after'];
                $defenderChi = $defenderTurn['attacker_chi_after'];
                $defenderTurn['turn'] = $turn;
                $logs[] = $defenderTurn;
            }

            // Determine winner
            $winnerId = $attackerChi > 0 ? $attackerId : $defenderId;
            $battleDuration = time() - $startTime;

            // Calculate ELO rating changes
            $ratingService = new RankingService();
            $ratingChanges = $ratingService->calculateRatingChange(
                $attackerRatingBefore,
                $defenderRatingBefore,
                $winnerId === $attackerId
            );

            $attackerRatingAfter = $attackerRatingBefore + $ratingChanges['attacker_change'];
            $defenderRatingAfter = $defenderRatingBefore + $ratingChanges['defender_change'];

            // Calculate PvP chi loss (5% of current chi for loser)
            $attackerChiLoss = 0;
            $defenderChiLoss = 0;
            
            if ($winnerId === $attackerId) {
                // Defender lost
                $defenderChiLoss = (int)($defenderChiStart * self::PVP_CHI_LOSS_PERCENTAGE);
            } else {
                // Attacker lost
                $attackerChiLoss = (int)($attackerChiStart * self::PVP_CHI_LOSS_PERCENTAGE);
            }

            // Insert battle record
            $battleId = $this->insertBattle(
                $db,
                $attackerId,
                $defenderId,
                $winnerId,
                $attackerRatingBefore,
                $defenderRatingBefore,
                $attackerRatingAfter,
                $defenderRatingAfter,
                $attackerChiStart,
                $defenderChiStart,
                $attackerChiLoss,
                $defenderChiLoss,
                $turn,
                $battleDuration
            );

            // Insert battle logs
            $this->insertBattleLogs($db, $battleId, $logs);

            // Update user stats
            $this->updateUserStats(
                $db,
                $attackerId,
                $defenderId,
                $winnerId,
                $attackerRatingAfter,
                $defenderRatingAfter,
                $attackerChiLoss,
                $defenderChiLoss
            );

            $winnerRatingAfter = ($winnerId === $attackerId) ? $attackerRatingAfter : $defenderRatingAfter;
            $rewardService = new RewardService();
            $rewardService->applyPvPWinRewards($db, $winnerId, (float)$winnerRatingAfter);

            // Restore chi to max (battles don't consume chi permanently, except PvP loss)
            $this->restoreChi($db, $attackerId, $defenderId, $attackerChiLoss, $defenderChiLoss);

            DaoRecord::log(
                'combat_pvp',
                $attackerId,
                $battleId,
                'You engaged in PvP combat and ' . ($winnerId === $attackerId ? 'claimed victory.' : 'suffered defeat.'),
                [
                    'opponent_user_id' => $defenderId,
                    'winner_user_id' => $winnerId,
                    'turns' => $turn,
                    'attacker_rating_before' => $attackerRatingBefore,
                    'attacker_rating_after' => $attackerRatingAfter,
                ],
                $db
            );
            DaoRecord::log(
                'combat_pvp',
                $defenderId,
                $battleId,
                'You engaged in PvP combat and ' . ($winnerId === $defenderId ? 'claimed victory.' : 'suffered defeat.'),
                [
                    'opponent_user_id' => $attackerId,
                    'winner_user_id' => $winnerId,
                    'turns' => $turn,
                    'defender_rating_before' => $defenderRatingBefore,
                    'defender_rating_after' => $defenderRatingAfter,
                ],
                $db
            );
            Cache::forgetByPrefix('ranking:');

            $db->commit();

            return [
                'success' => true,
                'battle_id' => $battleId,
                'winner_id' => $winnerId,
                'turns' => $turn,
                'logs' => $logs,
                'attacker_rating_change' => $ratingChanges['attacker_change'],
                'defender_rating_change' => $ratingChanges['defender_change'],
                'attacker_chi_loss' => $attackerChiLoss,
                'defender_chi_loss' => $defenderChiLoss
            ];

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Battle simulation failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Battle simulation failed. Please try again.'
            ];
        }
    }

    /**
     * Process a single turn with given stat blocks. Used by PvP and PvE.
     * When defenderNpcId is set, defender revival is disabled (NPCs do not revive).
     *
     * @param array $attackerStats Must contain attack, max_chi
     * @param array $defenderStats Must contain defense, max_chi; may contain dodge_chance for NPC
     * @param int $attackerRealmLevel Attacker realm level
     * @param int $defenderRealmLevel Defender realm level
     * @param array $attackerEffects Realm effects for attacker
     * @param array $defenderEffects Realm effects for defender
     * @param int $attackerChi Current attacker chi/hp
     * @param int $defenderChi Current defender chi/hp
     * @param int|null $attackerId User ID or null
     * @param int|null $defenderId User ID or null for NPC
     * @param int|null $defenderNpcId NPC ID when defender is NPC (disables revival)
     * @return array Turn result for logs
     */
    public function processTurnWithStats(
        array $attackerStats,
        array $defenderStats,
        int $attackerRealmLevel,
        int $defenderRealmLevel,
        array $attackerEffects,
        array $defenderEffects,
        int $attackerChi,
        int $defenderChi,
        ?int $attackerId = null,
        ?int $defenderId = null,
        ?int $defenderNpcId = null,
        array &$attackerTechniqueState = [],
        array &$defenderTechniqueState = []
    ): array {
        $attack = (int)($attackerStats['attack'] ?? 0);
        $defense = (int)($defenderStats['defense'] ?? 0);
        $defenderMaxChi = (int)($defenderStats['max_chi'] ?? 0);
        $attackerMaxChi = (int)($attackerStats['max_chi'] ?? 0);

        $dodgeBonus = $this->consumeTechniqueFloat($defenderTechniqueState, 'next_dodge_bonus');
        $damageReduction = $this->consumeTechniqueFloat($defenderTechniqueState, 'next_damage_reduction');
        $reflectBonus = $this->consumeTechniqueFloat($defenderTechniqueState, 'next_reflect_bonus');
        $dodgeChance = self::DODGE_CHANCE_BASE + ($defenderRealmLevel * 0.005) + (float)($defenderStats['dao_dodge_bonus'] ?? 0.0) + $dodgeBonus;
        $npcDodge = $defenderNpcId !== null ? (float)($defenderStats['dodge_chance'] ?? 0.05) : 0;
        $dodgeChance = min(0.95, $dodgeChance + $npcDodge);
        $isDodged = (mt_rand(1, 10000) / 100) <= ($dodgeChance * 100);

        if ($isDodged) {
            return [
                'attacker_id' => $attackerId,
                'defender_id' => $defenderId,
                'defender_npc_id' => $defenderNpcId,
                'action_type' => 'dodge',
                'damage_dealt' => 0,
                'is_critical' => false,
                'is_dodge' => true,
                'is_lifesteal' => false,
                'is_counterattack' => false,
                'technique_name' => null,
                'attacker_chi_after' => $attackerChi,
                'defender_chi_after' => $defenderChi
            ];
        }

        $baseDamage = $this->calculateDamage($attack, $defense, $attackerEffects, $defenderRealmLevel);
        $technique = $this->daoTechniqueService->selectTechniqueForTurn($attackerTechniqueState, $attackerChi, $attackerMaxChi);
        $techniqueSelfDamage = 0;
        $techniqueHealAmount = 0;
        $actionType = 'attack';
        if ($technique !== null) {
            $actionType = (string)$technique['technique_key'];
            $baseDamage = max(1, (int)round($baseDamage * (float)$technique['damage_multiplier']));
            $techniqueResolution = $this->daoTechniqueService->applyTechniqueCostsAndEffects(
                $technique,
                $baseDamage,
                $attackerChi,
                $attackerMaxChi,
                $attackerTechniqueState
            );
            $attackerChi = $techniqueResolution['attacker_chi_after'];
            $baseDamage = $techniqueResolution['damage'];
            $techniqueSelfDamage = $techniqueResolution['self_damage'];
            $techniqueHealAmount = $techniqueResolution['heal_amount'];
        }
        $isCritical = $this->isCriticalStrike();
        if ($isCritical) {
            $baseDamage = (int)($baseDamage * self::CRITICAL_DAMAGE_MULTIPLIER);
        }
        if ((float)($attackerStats['dao_bonus_damage_pct'] ?? 0.0) > 0) {
            $baseDamage += (int)round($baseDamage * (float)$attackerStats['dao_bonus_damage_pct']);
        }
        if ($damageReduction > 0) {
            $baseDamage = max(1, (int)round($baseDamage * (1 - min(0.75, $damageReduction))));
        }

        $defenderChiAfter = max(0, $defenderChi - $baseDamage);

        $revived = false;
        if ($defenderChiAfter <= 0 && $defenderNpcId === null) {
            $revived = $this->realmEffects->checkRevival($defenderRealmLevel, $defenderChiAfter);
            if ($revived) {
                $defenderChiAfter = max(1, (int)($defenderMaxChi * 0.10));
            }
        }

        $lifestealAmount = 0;
        $isLifesteal = false;
        if ($baseDamage > 0 && !$revived) {
            $lifestealRoll = (mt_rand(1, 10000) / 100);
            if ($lifestealRoll <= (self::LIFESTEAL_CHANCE * 100)) {
                $isLifesteal = true;
                $lifestealAmount = (int)($baseDamage * self::LIFESTEAL_PERCENTAGE);
                $attackerChi = min($attackerMaxChi, $attackerChi + $lifestealAmount);
            }
        }
        $daoHealAmount = 0;
        if ($baseDamage > 0 && !$revived && (float)($attackerStats['dao_heal_on_hit_pct'] ?? 0.0) > 0) {
            $daoHealAmount = (int)round($baseDamage * (float)$attackerStats['dao_heal_on_hit_pct']);
            $attackerChi = min($attackerMaxChi, $attackerChi + $daoHealAmount);
        }
        if ($techniqueHealAmount > 0) {
            $attackerChi = min($attackerMaxChi, $attackerChi + $techniqueHealAmount);
        }
        $daoSelfDamage = 0;
        if ($baseDamage > 0 && (float)($attackerStats['dao_self_damage_pct'] ?? 0.0) > 0) {
            $daoSelfDamage = max(1, (int)round($attackerMaxChi * (float)$attackerStats['dao_self_damage_pct']));
            $attackerChi = max(0, $attackerChi - $daoSelfDamage);
        }

        $counterattackDamage = 0;
        $isCounterattack = false;
        if ($baseDamage > 0 && !$revived) {
            $counterRoll = (mt_rand(1, 10000) / 100);
            if ($counterRoll <= (self::COUNTERATTACK_CHANCE * 100)) {
                $isCounterattack = true;
                $counterattackDamage = (int)($baseDamage * 0.50);
                $attackerChiAfter = max(0, $attackerChi - $counterattackDamage);
            } else {
                $attackerChiAfter = $attackerChi;
            }
        } else {
            $attackerChiAfter = $attackerChi;
        }

        $reflectedDamage = 0;
        if ($baseDamage > 0 && ($attackerEffects['damage_reflection'] ?? 0) > 0) {
            $reflectedDamage = (int)($baseDamage * $attackerEffects['damage_reflection']);
            $attackerChiAfter = max(0, $attackerChiAfter - $reflectedDamage);
        }
        if ($baseDamage > 0 && (float)($defenderStats['dao_reflect_damage_pct'] ?? 0.0) > 0) {
            $reflectedDamage += (int)round($baseDamage * (float)$defenderStats['dao_reflect_damage_pct']);
            $attackerChiAfter = max(0, $attackerChiAfter - (int)round($baseDamage * (float)$defenderStats['dao_reflect_damage_pct']));
        }
        if ($baseDamage > 0 && $reflectBonus > 0) {
            $bonusReflectDamage = (int)round($baseDamage * $reflectBonus);
            $reflectedDamage += $bonusReflectDamage;
            $attackerChiAfter = max(0, $attackerChiAfter - $bonusReflectDamage);
        }

        return [
            'attacker_id' => $attackerId,
            'defender_id' => $defenderId,
            'defender_npc_id' => $defenderNpcId,
            'action_type' => $revived ? 'revival' : ($isCritical && $actionType === 'attack' ? 'critical_attack' : $actionType),
            'damage_dealt' => $baseDamage,
            'is_critical' => $isCritical,
            'is_dodge' => false,
            'is_lifesteal' => $isLifesteal,
            'is_counterattack' => $isCounterattack,
            'lifesteal_amount' => $lifestealAmount,
            'dao_heal_amount' => $daoHealAmount + $techniqueHealAmount,
            'dao_self_damage' => $daoSelfDamage + $techniqueSelfDamage,
            'counterattack_damage' => $counterattackDamage,
            'reflected_damage' => $reflectedDamage,
            'revived' => $revived,
            'technique_name' => $technique['name'] ?? null,
            'attacker_chi_after' => $attackerChiAfter,
            'defender_chi_after' => $defenderChiAfter
        ];
    }

    private function consumeTechniqueFloat(array &$state, string $key): float
    {
        if (!isset($state[$key])) {
            return 0.0;
        }
        $value = (float)$state[$key];
        $state[$key] = 0.0;
        return $value;
    }

    /**
     * Calculate damage with realm multipliers, variance, and realm effects
     * 
     * @param int $attack Attacker's attack stat
     * @param int $defense Defender's defense stat
     * @param array $attackerEffects Realm effects
     * @param int $defenderRealmLevel Defender realm level
     * @return int Calculated damage (minimum 1)
     */
    private function calculateDamage(
        int $attack,
        int $defense,
        array $attackerEffects,
        int $defenderRealmLevel
    ): int {
        // Apply defense penetration
        $effectiveDefense = (int)($defense * (1 - $attackerEffects['defense_penetration']));
        
        // Base damage calculation
        $baseDamage = $attack - $effectiveDefense;
        
        // Apply realm suppression
        $baseDamage = (int)($baseDamage * $attackerEffects['realm_suppression']);
        
        // Apply variance (±10%)
        $variance = (mt_rand(-1000, 1000) / 10000) * self::DAMAGE_VARIANCE;
        $damage = (int)($baseDamage * (1 + $variance));
        
        // Minimum damage is 1
        return max(1, $damage);
    }

    /**
     * Check if attack is a critical strike
     * 
     * @return bool True if critical strike
     */
    private function isCriticalStrike(): bool
    {
        return mt_rand(1, 100) <= (self::CRITICAL_STRIKE_CHANCE * 100);
    }

    /**
     * Fetch user with realm data
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @return array|null User data with realm multipliers
     */
    private function fetchUserWithRealm(\PDO $db, int $userId): ?array
    {
        $sql = "SELECT u.*, r.attack_multiplier, r.defense_multiplier 
                FROM users u 
                LEFT JOIN realms r ON u.realm_id = r.id 
                WHERE u.id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Insert battle record
     * 
     * @param \PDO $db Database connection
     * @param int $attackerId Attacker ID
     * @param int $defenderId Defender ID
     * @param int $winnerId Winner ID
     * @param float $attackerRatingBefore Attacker rating before
     * @param float $defenderRatingBefore Defender rating before
     * @param float $attackerRatingAfter Attacker rating after
     * @param float $defenderRatingAfter Defender rating after
     * @param int $attackerChiStart Attacker chi at start
     * @param int $defenderChiStart Defender chi at start
     * @param int $attackerChiLoss Attacker chi loss
     * @param int $defenderChiLoss Defender chi loss
     * @param int $turns Number of turns
     * @param int $duration Battle duration in seconds
     * @return int Battle ID
     */
    private function insertBattle(
        \PDO $db,
        int $attackerId,
        int $defenderId,
        int $winnerId,
        float $attackerRatingBefore,
        float $defenderRatingBefore,
        float $attackerRatingAfter,
        float $defenderRatingAfter,
        int $attackerChiStart,
        int $defenderChiStart,
        int $attackerChiLoss,
        int $defenderChiLoss,
        int $turns,
        int $duration
    ): int {
        $sql = "INSERT INTO battles (
            attacker_id, defender_id, winner_id,
            attacker_rating_before, defender_rating_before,
            attacker_rating_after, defender_rating_after,
            attacker_chi_start, defender_chi_start,
            attacker_chi_loss, defender_chi_loss,
            turns, battle_duration_seconds
        ) VALUES (
            :attacker_id, :defender_id, :winner_id,
            :attacker_rating_before, :defender_rating_before,
            :attacker_rating_after, :defender_rating_after,
            :attacker_chi_start, :defender_chi_start,
            :attacker_chi_loss, :defender_chi_loss,
            :turns, :battle_duration_seconds
        )";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':attacker_id' => $attackerId,
            ':defender_id' => $defenderId,
            ':winner_id' => $winnerId,
            ':attacker_rating_before' => $attackerRatingBefore,
            ':defender_rating_before' => $defenderRatingBefore,
            ':attacker_rating_after' => $attackerRatingAfter,
            ':defender_rating_after' => $defenderRatingAfter,
            ':attacker_chi_start' => $attackerChiStart,
            ':defender_chi_start' => $defenderChiStart,
            ':attacker_chi_loss' => $attackerChiLoss,
            ':defender_chi_loss' => $defenderChiLoss,
            ':turns' => $turns,
            ':battle_duration_seconds' => $duration
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Insert battle logs
     * 
     * @param \PDO $db Database connection
     * @param int $battleId Battle ID
     * @param array $logs Battle logs
     * @return void
     */
    private function insertBattleLogs(\PDO $db, int $battleId, array $logs): void
    {
        $sql = "INSERT INTO battle_logs (
            battle_id, turn_number, attacker_id, defender_id,
            action_type, damage_dealt, is_critical,
            is_dodge, is_lifesteal, is_counterattack,
            attacker_chi_after, defender_chi_after
        ) VALUES (
            :battle_id, :turn_number, :attacker_id, :defender_id,
            :action_type, :damage_dealt, :is_critical,
            :is_dodge, :is_lifesteal, :is_counterattack,
            :attacker_chi_after, :defender_chi_after
        )";

        $stmt = $db->prepare($sql);
        foreach ($logs as $log) {
            $stmt->execute([
                ':battle_id' => $battleId,
                ':turn_number' => $log['turn'],
                ':attacker_id' => $log['attacker_id'],
                ':defender_id' => $log['defender_id'],
                ':action_type' => $log['action_type'],
                ':damage_dealt' => $log['damage_dealt'],
                ':is_critical' => isset($log['is_critical']) && $log['is_critical'] ? 1 : 0,
                ':is_dodge' => isset($log['is_dodge']) && $log['is_dodge'] ? 1 : 0,
                ':is_lifesteal' => isset($log['is_lifesteal']) && $log['is_lifesteal'] ? 1 : 0,
                ':is_counterattack' => isset($log['is_counterattack']) && $log['is_counterattack'] ? 1 : 0,
                ':attacker_chi_after' => $log['attacker_chi_after'],
                ':defender_chi_after' => $log['defender_chi_after']
            ]);
        }
    }

    /**
     * Update user stats after battle
     * 
     * @param \PDO $db Database connection
     * @param int $attackerId Attacker ID
     * @param int $defenderId Defender ID
     * @param int $winnerId Winner ID
     * @param float $attackerRatingAfter Attacker rating after
     * @param float $defenderRatingAfter Defender rating after
     * @param int $attackerChiLoss Attacker chi loss
     * @param int $defenderChiLoss Defender chi loss
     * @return void
     */
    private function updateUserStats(
        \PDO $db,
        int $attackerId,
        int $defenderId,
        int $winnerId,
        float $attackerRatingAfter,
        float $defenderRatingAfter,
        int $attackerChiLoss,
        int $defenderChiLoss
    ): void {
        // Update attacker
        $attackerWon = ($winnerId === $attackerId);
        $sql = "UPDATE users SET 
                rating = :rating,
                wins = wins + :win_increment,
                losses = losses + :loss_increment,
                active_scroll_type = NULL
                WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rating' => $attackerRatingAfter,
            ':win_increment' => $attackerWon ? 1 : 0,
            ':loss_increment' => $attackerWon ? 0 : 1,
            ':id' => $attackerId
        ]);

        // Update defender
        $defenderWon = ($winnerId === $defenderId);
        $stmt->execute([
            ':rating' => $defenderRatingAfter,
            ':win_increment' => $defenderWon ? 1 : 0,
            ':loss_increment' => $defenderWon ? 0 : 1,
            ':id' => $defenderId
        ]);
    }

    /**
     * Restore chi to max after battle (minus PvP loss)
     * 
     * @param \PDO $db Database connection
     * @param int $attackerId Attacker ID
     * @param int $defenderId Defender ID
     * @param int $attackerChiLoss Attacker chi loss
     * @param int $defenderChiLoss Defender chi loss
     * @return void
     */
    private function restoreChi(\PDO $db, int $attackerId, int $defenderId, int $attackerChiLoss, int $defenderChiLoss): void
    {
        // Restore chi to max, then subtract PvP loss
        $sql = "UPDATE users SET chi = GREATEST(0, max_chi - :chi_loss) WHERE id = :id";
        $stmt = $db->prepare($sql);
        
        // Update attacker
        $stmt->execute([
            ':chi_loss' => $attackerChiLoss,
            ':id' => $attackerId
        ]);
        
        // Update defender
        $stmt->execute([
            ':chi_loss' => $defenderChiLoss,
            ':id' => $defenderId
        ]);
    }

    /**
     * Get battle logs for replay
     * 
     * @param int $battleId Battle ID
     * @return array Battle logs
     */
    public function getBattleLogs(int $battleId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM battle_logs WHERE battle_id = ? ORDER BY turn_number ASC, id ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$battleId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to fetch battle logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get battle details
     * 
     * @param int $battleId Battle ID
     * @return array|null Battle details
     */
    public function getBattle(int $battleId): ?array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT b.*, 
                    u1.username as attacker_username,
                    u2.username as defender_username,
                    u3.username as winner_username
                    FROM battles b
                    LEFT JOIN users u1 ON b.attacker_id = u1.id
                    LEFT JOIN users u2 ON b.defender_id = u2.id
                    LEFT JOIN users u3 ON b.winner_id = u3.id
                    WHERE b.id = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$battleId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Failed to fetch battle: " . $e->getMessage());
            return null;
        }
    }
}
