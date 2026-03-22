<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/ItemService.php';
require_once __DIR__ . '/CultivationManualService.php';
require_once __DIR__ . '/TitleService.php';

use Game\Config\Database;
use PDOException;

/**
 * Stat Calculator - centralized stat calculation.
 */
class StatCalculator
{
    /**
     * Calculate final combat stats for a user.
     * Order: base stats -> equipment -> realm -> scroll -> Dao Path.
     *
     * @param int $userId User ID
     * @return array ['user_id', 'base' => array, 'final' => array, 'modifiers' => ['equipment_bonus' => array]]
     */
    public function calculateFinalStats(int $userId): array
    {
        $baseStats = $this->getBaseStats($userId);
        if (!$baseStats) {
            throw new \Exception("User not found");
        }

        $afterEquipment = $this->applyEquippedItemBonuses($baseStats, $userId);
        $finalStats = $this->applyCultivationManualBonuses($this->applyRealmMultipliers($afterEquipment), $userId);
        $finalStats = $this->applyTitleBonuses($finalStats, $userId);
        $equipmentBonus = $this->getEquippedItemBonusesSummary($userId);

        return [
            'user_id' => $userId,
            'base' => $baseStats,
            'final' => $finalStats,
            'modifiers' => [
                'equipment_bonus' => $equipmentBonus
            ]
        ];
    }

    /**
     * Get base stats from database plus Dao Path metadata.
     */
    private function getBaseStats(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT u.id, u.realm_id, u.level, u.chi, u.max_chi, u.attack, u.defense, u.active_scroll_type,
                       d.path_key AS dao_path_key, d.name AS dao_path_name, d.alignment AS dao_alignment,
                       d.element AS dao_element, d.attack_bonus_pct, d.defense_bonus_pct, d.max_chi_bonus_pct,
                       d.dodge_bonus_pct, d.bonus_damage_pct, d.heal_on_hit_pct, d.reflect_damage_pct,
                       d.self_damage_pct, d.favored_tribulation
                FROM users u
                LEFT JOIN dao_paths d ON d.id = u.dao_path_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                return null;
            }
            return [
                'attack' => (int)$user['attack'],
                'defense' => (int)$user['defense'],
                'chi' => (int)$user['chi'],
                'max_chi' => (int)$user['max_chi'],
                'level' => (int)$user['level'],
                'realm_id' => (int)$user['realm_id'],
                'active_scroll_type' => isset($user['active_scroll_type']) && $user['active_scroll_type'] !== '' ? (string)$user['active_scroll_type'] : null,
                'dao_path_key' => !empty($user['dao_path_key']) ? (string)$user['dao_path_key'] : null,
                'dao_path_name' => !empty($user['dao_path_name']) ? (string)$user['dao_path_name'] : null,
                'dao_alignment' => !empty($user['dao_alignment']) ? (string)$user['dao_alignment'] : null,
                'dao_element' => !empty($user['dao_element']) ? (string)$user['dao_element'] : null,
                'dao_attack_bonus_pct' => (float)($user['attack_bonus_pct'] ?? 0.0),
                'dao_defense_bonus_pct' => (float)($user['defense_bonus_pct'] ?? 0.0),
                'dao_max_chi_bonus_pct' => (float)($user['max_chi_bonus_pct'] ?? 0.0),
                'dao_dodge_bonus' => (float)($user['dodge_bonus_pct'] ?? 0.0),
                'dao_bonus_damage_pct' => (float)($user['bonus_damage_pct'] ?? 0.0),
                'dao_heal_on_hit_pct' => (float)($user['heal_on_hit_pct'] ?? 0.0),
                'dao_reflect_damage_pct' => (float)($user['reflect_damage_pct'] ?? 0.0),
                'dao_self_damage_pct' => (float)($user['self_damage_pct'] ?? 0.0),
                'dao_favored_tribulation' => !empty($user['favored_tribulation']) ? (string)$user['favored_tribulation'] : null,
            ];
        } catch (PDOException $e) {
            error_log("StatCalculator::getBaseStats " . $e->getMessage());
            return null;
        }
    }

    /**
     * Apply equipped item bonuses (flat bonuses from item_templates). Does not modify base stats in DB.
     */
    private function applyEquippedItemBonuses(array $stats, int $userId): array
    {
        $itemService = new ItemService();
        $bonuses = $itemService->getEquippedItemBonuses($userId);
        return [
            'attack' => $stats['attack'] + $bonuses['attack'],
            'defense' => $stats['defense'] + $bonuses['defense'],
            'chi' => $stats['chi'],
            'max_chi' => $stats['max_chi'] + $bonuses['hp'],
            'level' => $stats['level'],
            'realm_id' => $stats['realm_id'],
            'active_scroll_type' => $stats['active_scroll_type'] ?? null,
            'dao_path_key' => $stats['dao_path_key'] ?? null,
            'dao_path_name' => $stats['dao_path_name'] ?? null,
            'dao_alignment' => $stats['dao_alignment'] ?? null,
            'dao_element' => $stats['dao_element'] ?? null,
            'dao_attack_bonus_pct' => (float)($stats['dao_attack_bonus_pct'] ?? 0.0),
            'dao_defense_bonus_pct' => (float)($stats['dao_defense_bonus_pct'] ?? 0.0),
            'dao_max_chi_bonus_pct' => (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0),
            'dao_dodge_bonus' => (float)($stats['dao_dodge_bonus'] ?? 0.0),
            'dao_bonus_damage_pct' => (float)($stats['dao_bonus_damage_pct'] ?? 0.0),
            'dao_heal_on_hit_pct' => (float)($stats['dao_heal_on_hit_pct'] ?? 0.0),
            'dao_reflect_damage_pct' => (float)($stats['dao_reflect_damage_pct'] ?? 0.0),
            'dao_self_damage_pct' => (float)($stats['dao_self_damage_pct'] ?? 0.0),
            'dao_favored_tribulation' => $stats['dao_favored_tribulation'] ?? null,
        ];
    }

    /**
     * Apply realm tier multiplier (single value) to attack, defense, max_chi after equipment. Chi (current) unchanged.
     */
    private function applyRealmMultipliers(array $stats): array
    {
        $realmId = (int)($stats['realm_id'] ?? 1);
        $mult = $this->getRealmMultiplier($realmId);
        $after = [
            'attack' => max(1, (int)round(($stats['attack'] ?? 0) * $mult)),
            'defense' => max(0, (int)round(($stats['defense'] ?? 0) * $mult)),
            'chi' => $stats['chi'],
            'max_chi' => max(1, (int)round(($stats['max_chi'] ?? 1) * $mult)),
            'level' => $stats['level'],
            'realm_id' => $realmId,
            'active_scroll_type' => $stats['active_scroll_type'] ?? null
        ];
        return $this->applyDaoBonuses($this->applyActiveScrollEffect($after));
    }

    /**
     * Phase 2.3: Apply active scroll effect (minor_attack +8%, minor_defense +8%, vitality +10% max HP).
     * Focus rune is applied in BreakthroughService only.
     */
    private function applyActiveScrollEffect(array $stats): array
    {
        $scroll = $stats['active_scroll_type'] ?? null;
        if (!$scroll) {
            return $stats;
        }
        $attack = $stats['attack'];
        $defense = $stats['defense'];
        $maxChi = $stats['max_chi'];
        if ($scroll === 'minor_attack') {
            $attack = max(1, (int)round($attack * 1.08));
        } elseif ($scroll === 'minor_defense') {
            $defense = (int)round($defense * 1.08);
        } elseif ($scroll === 'vitality') {
            $maxChi = max(1, (int)round($maxChi * 1.10));
        }
        return [
            'attack' => $attack,
            'defense' => $defense,
            'chi' => $stats['chi'],
            'max_chi' => $maxChi,
            'level' => $stats['level'],
            'realm_id' => $stats['realm_id'],
            'active_scroll_type' => $scroll,
            'dao_path_key' => $stats['dao_path_key'] ?? null,
            'dao_path_name' => $stats['dao_path_name'] ?? null,
            'dao_alignment' => $stats['dao_alignment'] ?? null,
            'dao_element' => $stats['dao_element'] ?? null,
            'dao_attack_bonus_pct' => (float)($stats['dao_attack_bonus_pct'] ?? 0.0),
            'dao_defense_bonus_pct' => (float)($stats['dao_defense_bonus_pct'] ?? 0.0),
            'dao_max_chi_bonus_pct' => (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0),
            'dao_dodge_bonus' => (float)($stats['dao_dodge_bonus'] ?? 0.0),
            'dao_bonus_damage_pct' => (float)($stats['dao_bonus_damage_pct'] ?? 0.0),
            'dao_heal_on_hit_pct' => (float)($stats['dao_heal_on_hit_pct'] ?? 0.0),
            'dao_reflect_damage_pct' => (float)($stats['dao_reflect_damage_pct'] ?? 0.0),
            'dao_self_damage_pct' => (float)($stats['dao_self_damage_pct'] ?? 0.0),
            'dao_favored_tribulation' => $stats['dao_favored_tribulation'] ?? null,
        ];
    }

    private function applyDaoBonuses(array $stats): array
    {
        $attack = max(1, (int)round(($stats['attack'] ?? 0) * (1 + (float)($stats['dao_attack_bonus_pct'] ?? 0.0))));
        $defense = max(0, (int)round(($stats['defense'] ?? 0) * (1 + (float)($stats['dao_defense_bonus_pct'] ?? 0.0))));
        $maxChi = max(1, (int)round(($stats['max_chi'] ?? 1) * (1 + (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0))));

        return [
            'attack' => $attack,
            'defense' => $defense,
            'chi' => min((int)$stats['chi'], $maxChi),
            'max_chi' => $maxChi,
            'level' => $stats['level'],
            'realm_id' => $stats['realm_id'],
            'active_scroll_type' => $stats['active_scroll_type'] ?? null,
            'dao_path_key' => $stats['dao_path_key'] ?? null,
            'dao_path_name' => $stats['dao_path_name'] ?? null,
            'dao_alignment' => $stats['dao_alignment'] ?? null,
            'dao_element' => $stats['dao_element'] ?? null,
            'dao_attack_bonus_pct' => (float)($stats['dao_attack_bonus_pct'] ?? 0.0),
            'dao_defense_bonus_pct' => (float)($stats['dao_defense_bonus_pct'] ?? 0.0),
            'dao_max_chi_bonus_pct' => (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0),
            'dao_dodge_bonus' => (float)($stats['dao_dodge_bonus'] ?? 0.0),
            'dao_bonus_damage_pct' => (float)($stats['dao_bonus_damage_pct'] ?? 0.0),
            'dao_heal_on_hit_pct' => (float)($stats['dao_heal_on_hit_pct'] ?? 0.0),
            'dao_reflect_damage_pct' => (float)($stats['dao_reflect_damage_pct'] ?? 0.0),
            'dao_self_damage_pct' => (float)($stats['dao_self_damage_pct'] ?? 0.0),
            'dao_favored_tribulation' => $stats['dao_favored_tribulation'] ?? null,
        ];
    }

    private function applyCultivationManualBonuses(array $stats, int $userId): array
    {
        $manualService = new CultivationManualService();
        $effects = $manualService->getActiveEffectsForUser($userId);
        $attack = max(1, (int)round(($stats['attack'] ?? 0) * (1 + (float)$effects['passive_attack_pct'])));
        $defense = max(0, (int)round(($stats['defense'] ?? 0) * (1 + (float)$effects['passive_defense_pct'])));
        $maxChi = max(1, (int)round(($stats['max_chi'] ?? 1) * (1 + (float)$effects['passive_max_chi_pct'])));

        return [
            'attack' => $attack,
            'defense' => $defense,
            'chi' => min((int)$stats['chi'], $maxChi),
            'max_chi' => $maxChi,
            'level' => $stats['level'],
            'realm_id' => $stats['realm_id'],
            'active_scroll_type' => $stats['active_scroll_type'] ?? null,
            'dao_path_key' => $stats['dao_path_key'] ?? null,
            'dao_path_name' => $stats['dao_path_name'] ?? null,
            'dao_alignment' => $stats['dao_alignment'] ?? null,
            'dao_element' => $stats['dao_element'] ?? null,
            'dao_attack_bonus_pct' => (float)($stats['dao_attack_bonus_pct'] ?? 0.0),
            'dao_defense_bonus_pct' => (float)($stats['dao_defense_bonus_pct'] ?? 0.0),
            'dao_max_chi_bonus_pct' => (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0),
            'dao_dodge_bonus' => (float)($stats['dao_dodge_bonus'] ?? 0.0) + (float)$effects['passive_dodge_pct'],
            'dao_bonus_damage_pct' => (float)($stats['dao_bonus_damage_pct'] ?? 0.0),
            'dao_heal_on_hit_pct' => (float)($stats['dao_heal_on_hit_pct'] ?? 0.0),
            'dao_reflect_damage_pct' => (float)($stats['dao_reflect_damage_pct'] ?? 0.0),
            'dao_self_damage_pct' => (float)($stats['dao_self_damage_pct'] ?? 0.0),
            'dao_favored_tribulation' => $stats['dao_favored_tribulation'] ?? null,
            'manual_effects' => $effects,
        ];
    }

    /**
     * Equipped title: small % bonuses to attack, defense, max chi.
     */
    private function applyTitleBonuses(array $stats, int $userId): array
    {
        $titleService = new TitleService();
        $b = $titleService->getEquippedBonuses($userId);
        $atk = (float)($b['attack_pct'] ?? 0.0);
        $def = (float)($b['defense_pct'] ?? 0.0);
        $mc = (float)($b['max_chi_pct'] ?? 0.0);
        $attack = max(1, (int)round(($stats['attack'] ?? 0) * (1 + $atk)));
        $defense = max(0, (int)round(($stats['defense'] ?? 0) * (1 + $def)));
        $maxChi = max(1, (int)round(($stats['max_chi'] ?? 1) * (1 + $mc)));

        return [
            'attack' => $attack,
            'defense' => $defense,
            'chi' => min((int)$stats['chi'], $maxChi),
            'max_chi' => $maxChi,
            'level' => $stats['level'],
            'realm_id' => $stats['realm_id'],
            'active_scroll_type' => $stats['active_scroll_type'] ?? null,
            'dao_path_key' => $stats['dao_path_key'] ?? null,
            'dao_path_name' => $stats['dao_path_name'] ?? null,
            'dao_alignment' => $stats['dao_alignment'] ?? null,
            'dao_element' => $stats['dao_element'] ?? null,
            'dao_attack_bonus_pct' => (float)($stats['dao_attack_bonus_pct'] ?? 0.0),
            'dao_defense_bonus_pct' => (float)($stats['dao_defense_bonus_pct'] ?? 0.0),
            'dao_max_chi_bonus_pct' => (float)($stats['dao_max_chi_bonus_pct'] ?? 0.0),
            'dao_dodge_bonus' => (float)($stats['dao_dodge_bonus'] ?? 0.0),
            'dao_bonus_damage_pct' => (float)($stats['dao_bonus_damage_pct'] ?? 0.0),
            'dao_heal_on_hit_pct' => (float)($stats['dao_heal_on_hit_pct'] ?? 0.0),
            'dao_reflect_damage_pct' => (float)($stats['dao_reflect_damage_pct'] ?? 0.0),
            'dao_self_damage_pct' => (float)($stats['dao_self_damage_pct'] ?? 0.0),
            'dao_favored_tribulation' => $stats['dao_favored_tribulation'] ?? null,
            'manual_effects' => $stats['manual_effects'] ?? [],
        ];
    }

    /**
     * Single realm multiplier (controlled exponential scaling). Fallback 1.0 if column missing.
     */
    private function getRealmMultiplier(int $realmId): float
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM realms WHERE id = ? LIMIT 1");
            $stmt->execute([$realmId]);
            $row = $stmt->fetch();
            return ($row && isset($row['multiplier'])) ? (float)$row['multiplier'] : 1.0;
        } catch (\Throwable $e) {
            error_log("StatCalculator::getRealmMultiplier " . $e->getMessage());
            return 1.0;
        }
    }

    private function getEquippedItemBonusesSummary(int $userId): array
    {
        $itemService = new ItemService();
        $b = $itemService->getEquippedItemBonuses($userId);
        return ['attack' => $b['attack'], 'defense' => $b['defense'], 'hp' => $b['hp']];
    }

    public function getFinalAttack(int $userId): int
    {
        $stats = $this->calculateFinalStats($userId);
        return $stats['final']['attack'];
    }

    public function getFinalDefense(int $userId): int
    {
        $stats = $this->calculateFinalStats($userId);
        return $stats['final']['defense'];
    }

    public function getFinalChi(int $userId): array
    {
        $stats = $this->calculateFinalStats($userId);
        return [
            'chi' => $stats['final']['chi'],
            'max_chi' => $stats['final']['max_chi']
        ];
    }
}
