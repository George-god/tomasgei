<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/ItemService.php';

use Game\Config\Database;
use PDOException;

/**
 * Stat Calculator - Phase 1
 * Centralized stat calculation. Base stats + equipped item bonuses only.
 * No profession or world bonuses.
 */
class StatCalculator
{
    /**
     * Calculate final combat stats for a user.
     * Order: Base stats + Equipped item bonuses = Final stats.
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
        $finalStats = $this->applyRealmMultipliers($afterEquipment);
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
     * Get base stats from database (chi, max_chi, level, attack, defense, realm_id).
     */
    private function getBaseStats(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, realm_id, level, chi, max_chi, attack, defense, active_scroll_type FROM users WHERE id = ? LIMIT 1");
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
                'active_scroll_type' => isset($user['active_scroll_type']) && $user['active_scroll_type'] !== '' ? (string)$user['active_scroll_type'] : null
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
            'active_scroll_type' => $stats['active_scroll_type'] ?? null
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
        return $this->applyActiveScrollEffect($after);
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
            'active_scroll_type' => $scroll
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
