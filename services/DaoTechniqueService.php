<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/CultivationManualService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Loads Dao techniques and applies their battle-only effects.
 */
class DaoTechniqueService
{
    private const COMBAT_STAMINA_MAX = 100;
    private const CORRUPTION_OVERLOAD_THRESHOLD = 100;

    private CultivationManualService $manualService;

    public function __construct()
    {
        $this->manualService = new CultivationManualService();
    }

    public function getTechniquesForUser(int $userId, ?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->prepare(
                "SELECT t.*
                 FROM users u
                 JOIN dao_techniques t ON t.dao_path_id = u.dao_path_id
                 WHERE u.id = ?
                 ORDER BY FIELD(t.tier, 'ultimate', 'advanced', 'basic'), t.id ASC"
            );
            $stmt->execute([$userId]);
            $techniques = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($techniques === []) {
                return [];
            }

            $manualEffects = $this->manualService->getActiveEffectsForUser($userId, $db);
            $unlockedTiers = array_fill_keys(array_merge(['basic'], $manualEffects['unlocked_tiers']), true);
            $unlockedKeys = array_fill_keys($manualEffects['unlocked_technique_keys'], true);
            $upgradePct = (float)($manualEffects['technique_upgrade_pct'] ?? 0.0);
            $cooldownReduction = (int)($manualEffects['cooldown_reduction_turns'] ?? 0);

            $filtered = [];
            foreach ($techniques as $technique) {
                $tier = (string)($technique['tier'] ?? 'basic');
                $key = (string)($technique['technique_key'] ?? '');
                if (!isset($unlockedTiers[$tier]) && !isset($unlockedKeys[$key])) {
                    continue;
                }

                $technique['damage_multiplier'] = (float)$technique['damage_multiplier'] * (1 + $upgradePct);
                $technique['cooldown_turns'] = max(0, (int)$technique['cooldown_turns'] - $cooldownReduction);
                $filtered[] = $technique;
            }

            return $filtered;
        } catch (PDOException $e) {
            error_log('DaoTechniqueService::getTechniquesForUser ' . $e->getMessage());
            return [];
        }
    }

    public function initializeCombatState(int $userId, bool $enabled = true, ?PDO $db = null): array
    {
        $techniques = $enabled ? $this->getTechniquesForUser($userId, $db) : [];
        $cooldowns = [];
        foreach ($techniques as $technique) {
            $cooldowns[(string)$technique['technique_key']] = 0;
        }

        return [
            'enabled' => $enabled && !empty($techniques),
            'techniques' => $techniques,
            'cooldowns' => $cooldowns,
            'combat_stamina' => self::COMBAT_STAMINA_MAX,
            'corruption' => 0.0,
            'next_dodge_bonus' => 0.0,
            'next_damage_reduction' => 0.0,
            'next_reflect_bonus' => 0.0,
        ];
    }

    public function selectTechniqueForTurn(array &$combatState, int $currentChi, int $maxChi): ?array
    {
        if (empty($combatState['enabled']) || empty($combatState['techniques'])) {
            return null;
        }

        foreach ($combatState['cooldowns'] as $key => $remaining) {
            if ($remaining > 0) {
                $combatState['cooldowns'][$key] = max(0, (int)$remaining - 1);
            }
        }

        foreach ($combatState['techniques'] as $technique) {
            $key = (string)$technique['technique_key'];
            if (($combatState['cooldowns'][$key] ?? 0) > 0) {
                continue;
            }

            if (!$this->canAffordTechnique($technique, $combatState, $currentChi, $maxChi)) {
                continue;
            }

            $combatState['cooldowns'][$key] = (int)$technique['cooldown_turns'];
            return $technique;
        }

        return null;
    }

    public function applyTechniqueCostsAndEffects(
        array $technique,
        int $damage,
        int $currentChi,
        int $maxChi,
        array &$combatState
    ): array {
        $selfDamage = 0;
        $healAmount = 0;
        $costType = (string)$technique['cost_type'];
        $costValue = (float)$technique['cost_value'];

        if ($costType === 'stamina') {
            $combatState['combat_stamina'] = max(0, (int)$combatState['combat_stamina'] - (int)round($costValue));
        } elseif ($costType === 'hp') {
            $selfDamage = max(1, (int)round($maxChi * ($costValue / 100)));
            $currentChi = max(0, $currentChi - $selfDamage);
        } elseif ($costType === 'corruption') {
            $combatState['corruption'] = (float)$combatState['corruption'] + $costValue;
            if ($combatState['corruption'] >= self::CORRUPTION_OVERLOAD_THRESHOLD) {
                $overflow = (float)$combatState['corruption'] - self::CORRUPTION_OVERLOAD_THRESHOLD;
                $overloadDamage = max(1, (int)round($maxChi * (0.03 + ($overflow / 1000))));
                $selfDamage += $overloadDamage;
                $currentChi = max(0, $currentChi - $overloadDamage);
            }
        }

        $effectValue = (float)($technique['effect_value'] ?? 0.0);
        switch ((string)($technique['special_effect'] ?? 'none')) {
            case 'burn':
                $damage += (int)round($damage * $effectValue);
                break;
            case 'heal':
                $healAmount += (int)round($damage * $effectValue);
                break;
            case 'windstep':
                $combatState['next_dodge_bonus'] = max((float)$combatState['next_dodge_bonus'], $effectValue);
                break;
            case 'stone_guard':
                $combatState['next_damage_reduction'] = max((float)$combatState['next_damage_reduction'], $effectValue);
                break;
            case 'reflect':
                $combatState['next_reflect_bonus'] = max((float)$combatState['next_reflect_bonus'], $effectValue);
                break;
        }

        return [
            'damage' => max(1, $damage),
            'self_damage' => $selfDamage,
            'heal_amount' => $healAmount,
            'attacker_chi_after' => $currentChi,
        ];
    }

    private function canAffordTechnique(array $technique, array $combatState, int $currentChi, int $maxChi): bool
    {
        $costType = (string)$technique['cost_type'];
        $costValue = (float)$technique['cost_value'];

        if ($costType === 'stamina') {
            return (int)($combatState['combat_stamina'] ?? 0) >= (int)ceil($costValue);
        }

        if ($costType === 'hp') {
            $hpCost = max(1, (int)round($maxChi * ($costValue / 100)));
            return $currentChi > $hpCost;
        }

        if ($costType === 'corruption') {
            return $currentChi > max(1, (int)round($maxChi * 0.05));
        }

        return true;
    }
}
