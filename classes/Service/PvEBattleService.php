<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/BattleEngine.php';

use Game\Config\Database;
use PDOException;

/**
 * PvE battle service - Phase 1.
 * Simple turn-based loop. No crit, dodge, or special effects.
 * Uses StatCalculator for user stats. NPC stats scaled by player level (not stored in DB).
 * No permanent penalties for losing (chi only granted on win).
 * Structured for future rarity multipliers via scaleNpcStats().
 */
class PvEBattleService
{
    private const MAX_TURNS = 50;

    /** Scaling factors per player level (balance: ~3–6 turns, ~70–80% player win rate). */
    private const SCALE_HP_PER_LEVEL = 12;
    private const SCALE_ATTACK_PER_LEVEL = 3;
    private const SCALE_DEFENSE_PER_LEVEL = 2;

    /**
     * Scale NPC stats by player level. Base stats are never modified in DB.
     * Extend with optional $rarityMultiplier for future rarity tiers.
     *
     * @param array $npc NPC row with base_hp, base_attack, base_defense
     * @param int $playerLevel Player's level
     * @param float $rarityMultiplier Optional future multiplier (default 1.0)
     * @return array ['hp' => int, 'attack' => int, 'defense' => int]
     */
    public function scaleNpcStats(array $npc, int $playerLevel, float $rarityMultiplier = 1.0): array
    {
        $baseHp = (int)$npc['base_hp'];
        $baseAttack = (int)$npc['base_attack'];
        $baseDefense = (int)$npc['base_defense'];

        $hp = (int)round(($baseHp + $playerLevel * self::SCALE_HP_PER_LEVEL) * $rarityMultiplier);
        $attack = (int)round(($baseAttack + $playerLevel * self::SCALE_ATTACK_PER_LEVEL) * $rarityMultiplier);
        $defense = (int)round(($baseDefense + $playerLevel * self::SCALE_DEFENSE_PER_LEVEL) * $rarityMultiplier);

        return [
            'hp' => max(1, $hp),
            'attack' => max(1, $attack),
            'defense' => max(0, $defense)
        ];
    }

    /**
     * Simulate PvE battle. NPC stats scaled by player level. Simple damage = max(1, attack - defense).
     *
     * @return array success, winner ('user'|'npc'), battle_log, user_chi_after, npc_hp_max (scaled), chi_reward, npc_name, error?
     */
    public function simulateBattle(int $userId, int $npcId): array
    {
        try {
            $statCalc = new StatCalculator();
            $userStats = $statCalc->calculateFinalStats($userId);
            $userAttack = (int)$userStats['final']['attack'];
            $userDefense = (int)$userStats['final']['defense'];
            $userMaxChi = (int)$userStats['final']['max_chi'];
            $playerLevel = (int)($userStats['final']['level'] ?? 1);

            $db = Database::getConnection();
            $userRow = $this->getUserRow($db, $userId);
            if (!$userRow) {
                return $this->fail('User not found.');
            }
            $userChi = min((int)$userRow['chi'], $userMaxChi);

            $npc = $this->getNpc($db, $npcId);
            if (!$npc) {
                return $this->fail('NPC not found.');
            }
            $npcName = (string)$npc['name'];
            $rewardChi = (int)$npc['reward_chi'];

            $scaled = $this->scaleNpcStats($npc, $playerLevel);
            $npcHp = $scaled['hp'];
            $npcHpMax = $npcHp;
            $npcAttack = $scaled['attack'];
            $npcDefense = $scaled['defense'];

            $battleLog = [];
            $turn = 0;

            while ($userChi > 0 && $npcHp > 0 && $turn < self::MAX_TURNS) {
                $turn++;

                $userDamage = BattleEngine::simpleDamage($userAttack, $npcDefense);
                $npcHp = max(0, $npcHp - $userDamage);
                $battleLog[] = [
                    'turn' => $turn,
                    'attacker' => 'user',
                    'damage' => $userDamage,
                    'user_chi' => $userChi,
                    'npc_hp' => $npcHp
                ];
                if ($npcHp <= 0) {
                    break;
                }

                $npcDamage = BattleEngine::simpleDamage($npcAttack, $userDefense);
                $userChi = max(0, $userChi - $npcDamage);
                $battleLog[] = [
                    'turn' => $turn,
                    'attacker' => 'npc',
                    'damage' => $npcDamage,
                    'user_chi' => $userChi,
                    'npc_hp' => $npcHp
                ];
            }

            $winner = $userChi > 0 ? 'user' : 'npc';
            $droppedItem = null;
            $goldGained = 0;
            $spiritStoneGained = 0;

            $herbDropped = null;
            $materialDropped = null;
            $runeFragmentDropped = null;
            if ($winner === 'user') {
                $droppedItem = $this->rollAndGrantDrop($userId);
                $goldBase = mt_rand(15, 30) + ($playerLevel * 2);
                $spiritStoneGained = (mt_rand(1, 100) <= 8) ? 1 : 0;
                $rewardService = new RewardService();
                $granted = $rewardService->applyPvEWinRewards($db, $userId, $goldBase, $spiritStoneGained);
                $goldGained = $granted['gold_gained'];
                $spiritStoneGained = $granted['spirit_stone_gained'];
                if (mt_rand(1, 100) <= 25) {
                    $herbDropped = $this->rollAndGrantHerbDrop($userId);
                }
                if (mt_rand(1, 100) <= 30) {
                    $userRealmId = (int)($this->getUserRealmId($db, $userId) ?? 1);
                    $materialDropped = $this->rollAndGrantMaterialDrop($userId, $userRealmId);
                }
                if (mt_rand(1, 100) <= 20) {
                    $runeFragmentDropped = $this->rollAndGrantRuneFragmentDrop($userId);
                }
            }

            return [
                'success' => true,
                'winner' => $winner,
                'battle_log' => $battleLog,
                'user_chi_after' => $userChi,
                'npc_hp_max' => $npcHpMax,
                'chi_reward' => $winner === 'user' ? $rewardChi : 0,
                'npc_name' => $npcName,
                'dropped_item' => $droppedItem,
                'herb_dropped' => $herbDropped,
                'material_dropped' => $materialDropped,
                'rune_fragment_dropped' => $runeFragmentDropped,
                'gold_gained' => $goldGained,
                'spirit_stone_gained' => $spiritStoneGained,
                'error' => null
            ];
        } catch (\Exception $e) {
            error_log("PvEBattleService::simulateBattle " . $e->getMessage());
            return $this->fail($e->getMessage());
        }
    }

    private function getUserRow(\PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare("SELECT chi FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getNpc(\PDO $db, int $npcId): ?array
    {
        $stmt = $db->prepare("SELECT id, name, level, base_hp, base_attack, base_defense, reward_chi FROM npcs WHERE id = ? LIMIT 1");
        $stmt->execute([$npcId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Roll drop chance for one random droppable template. On success, add to inventory.
     * Modular: no rarity or procedural stats yet.
     *
     * @return array|null ['id', 'name', 'type', 'attack_bonus', 'defense_bonus', 'hp_bonus'] or null if no drop
     */
    private function rollAndGrantDrop(int $userId): ?array
    {
        $templates = $this->getDroppableTemplates();
        if (empty($templates)) {
            return null;
        }
        $idx = array_rand($templates);
        $template = $templates[$idx];
        $chance = (float)($template['drop_chance'] ?? 0);
        if ($chance <= 0) {
            return null;
        }
        $roll = mt_rand(1, 10000) / 10000;
        if ($roll > $chance) {
            return null;
        }
        $itemService = new ItemService();
        $result = $itemService->addItemToInventory($userId, (int)$template['id'], 1);
        if (!$result['success']) {
            return null;
        }
        return [
            'id' => (int)$template['id'],
            'name' => (string)$template['name'],
            'type' => (string)$template['type'],
            'attack_bonus' => (int)$template['attack_bonus'],
            'defense_bonus' => (int)$template['defense_bonus'],
            'hp_bonus' => (int)$template['hp_bonus']
        ];
    }

    /**
     * Get all item templates with drop_chance > 0.
     */
    private function getDroppableTemplates(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance FROM item_templates WHERE drop_chance > 0");
            $rows = $stmt->fetchAll();
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("PvEBattleService::getDroppableTemplates " . $e->getMessage());
            return [];
        }
    }

    /**
     * 25% chance to drop one random herb (type='herb'). Independent of equipment drop.
     * @return array|null ['id', 'name', 'type'] or null
     */
    private function rollAndGrantHerbDrop(int $userId): ?array
    {
        $templates = $this->getHerbTemplates();
        if (empty($templates)) {
            return null;
        }
        $template = $templates[array_rand($templates)];
        $itemService = new ItemService();
        $result = $itemService->addItemToInventory($userId, (int)$template['id'], 1);
        if (!$result['success']) {
            return null;
        }
        return [
            'id' => (int)$template['id'],
            'name' => (string)$template['name'],
            'type' => (string)$template['type']
        ];
    }

    private function getHerbTemplates(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT id, name, type FROM item_templates WHERE type = 'herb'");
            $rows = $stmt->fetchAll();
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("PvEBattleService::getHerbTemplates " . $e->getMessage());
            return [];
        }
    }

    /**
     * 30% chance to drop one random material. Tier by realm: lower=Iron, mid=chance Refined, higher=chance Spirit Steel.
     */
    private function rollAndGrantMaterialDrop(int $userId, int $userRealmId): ?array
    {
        $tier = $this->getMaterialTierForRealm($userRealmId);
        $templates = $this->getMaterialTemplatesByTier($tier);
        if (empty($templates)) {
            $templates = $this->getMaterialTemplatesByTier(1);
        }
        if (empty($templates)) {
            return null;
        }
        $template = $templates[array_rand($templates)];
        $itemService = new ItemService();
        $result = $itemService->addItemToInventory($userId, (int)$template['id'], 1);
        if (!$result['success']) {
            return null;
        }
        return [
            'id' => (int)$template['id'],
            'name' => (string)$template['name'],
            'type' => (string)$template['type']
        ];
    }

    /** Rune Fragment item_template_id (Phase 2.3). */
    private const RUNE_FRAGMENT_TEMPLATE_ID = 56;

    private function rollAndGrantRuneFragmentDrop(int $userId): ?array
    {
        $itemService = new ItemService();
        $result = $itemService->addItemToInventory($userId, self::RUNE_FRAGMENT_TEMPLATE_ID, 1);
        if (!$result['success']) {
            return null;
        }
        return [
            'id' => self::RUNE_FRAGMENT_TEMPLATE_ID,
            'name' => 'Rune Fragment',
            'type' => 'material'
        ];
    }

    private function getUserRealmId(\PDO $db, int $userId): ?int
    {
        $stmt = $db->prepare("SELECT realm_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['realm_id'] : null;
    }

    /** Realm 1-2: tier 1. Realm 3: 80% tier 1, 20% tier 2. Realm 4: 60/30/10. Realm 5: 50/30/20. */
    private function getMaterialTierForRealm(int $realmId): int
    {
        if ($realmId <= 2) {
            return 1;
        }
        $roll = mt_rand(1, 100);
        if ($realmId === 3) {
            return $roll <= 80 ? 1 : 2;
        }
        if ($realmId === 4) {
            if ($roll <= 60) return 1;
            if ($roll <= 90) return 2;
            return 3;
        }
        if ($realmId >= 5) {
            if ($roll <= 50) return 1;
            if ($roll <= 80) return 2;
            return 3;
        }
        return 1;
    }

    private function getMaterialTemplatesByTier(int $tier): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, name, type FROM item_templates WHERE type = 'material' AND material_tier = ?");
            $stmt->execute([$tier]);
            $rows = $stmt->fetchAll();
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("PvEBattleService::getMaterialTemplatesByTier " . $e->getMessage());
            return [];
        }
    }

    private function fail(string $error): array
    {
        return [
            'success' => false,
            'winner' => 'npc',
            'battle_log' => [],
            'user_chi_after' => 0,
            'npc_hp_max' => 0,
            'chi_reward' => 0,
            'npc_name' => '',
            'dropped_item' => null,
            'herb_dropped' => null,
            'material_dropped' => null,
            'rune_fragment_dropped' => null,
            'gold_gained' => 0,
            'spirit_stone_gained' => 0,
            'error' => $error
        ];
    }

    /**
     * Get all NPCs for arena list.
     */
    public function getAllNpcs(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT id, name, level, base_hp, base_attack, base_defense, reward_chi FROM npcs ORDER BY level ASC");
            $rows = $stmt->fetchAll();
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("PvEBattleService::getAllNpcs " . $e->getMessage());
            return [];
        }
    }
}
