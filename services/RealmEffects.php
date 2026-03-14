<?php
declare(strict_types=1);

namespace Game\Service;

/**
 * Realm Effects Service
 * 
 * Handles realm-based combat mechanics.
 * No hardcoded realm IDs - all effects are calculated dynamically based on realm level.
 * 
 * Realm Mechanics:
 * - Defense penetration (higher realm ignores some defense)
 * - Damage reflection (reflects percentage of damage back)
 * - Revival (chance to survive fatal blow)
 * - Realm suppression (higher realm deals more damage to lower realm)
 */
class RealmEffects
{
    /**
     * Calculate defense penetration
     * Higher realm penetrates more defense
     * 
     * @param int $attackerRealmLevel Attacker's realm level
     * @param int $defenderRealmLevel Defender's realm level
     * @return float Defense penetration percentage (0.0 to 1.0)
     */
    public function getDefensePenetration(int $attackerRealmLevel, int $defenderRealmLevel): float
    {
        $realmDiff = $attackerRealmLevel - $defenderRealmLevel;
        
        if ($realmDiff <= 0) {
            return 0.0; // No penetration if same or lower realm
        }
        
        // 5% penetration per realm level difference, max 30%
        $penetration = min(0.30, $realmDiff * 0.05);
        return $penetration;
    }

    /**
     * Calculate damage reflection
     * Higher realm reflects more damage
     * 
     * @param int $defenderRealmLevel Defender's realm level
     * @param int $attackerRealmLevel Attacker's realm level
     * @return float Reflection percentage (0.0 to 1.0)
     */
    public function getDamageReflection(int $defenderRealmLevel, int $attackerRealmLevel): float
    {
        $realmDiff = $defenderRealmLevel - $attackerRealmLevel;
        
        if ($realmDiff <= 0) {
            return 0.0; // No reflection if same or lower realm
        }
        
        // 3% reflection per realm level difference, max 20%
        $reflection = min(0.20, $realmDiff * 0.03);
        return $reflection;
    }

    /**
     * Check if revival triggers
     * Higher realm has better revival chance
     * 
     * @param int $realmLevel Realm level
     * @param int $chiAfterDamage Chi after taking damage
     * @return bool True if revival triggers
     */
    public function checkRevival(int $realmLevel, int $chiAfterDamage): bool
    {
        if ($chiAfterDamage > 0) {
            return false; // Only triggers on fatal blow
        }
        
        // Base 5% chance + 2% per realm level, max 25%
        $revivalChance = min(0.25, 0.05 + ($realmLevel * 0.02));
        $roll = mt_rand(1, 10000) / 100;
        
        return $roll <= ($revivalChance * 100);
    }

    /**
     * Calculate realm suppression multiplier
     * Higher realm deals more damage to lower realm
     * 
     * @param int $attackerRealmLevel Attacker's realm level
     * @param int $defenderRealmLevel Defender's realm level
     * @return float Damage multiplier
     */
    public function getRealmSuppression(int $attackerRealmLevel, int $defenderRealmLevel): float
    {
        $realmDiff = $attackerRealmLevel - $defenderRealmLevel;
        
        if ($realmDiff <= 0) {
            return 1.0; // No suppression if same or lower realm
        }
        
        // 5% damage increase per realm level difference, max 50%
        $suppression = min(1.50, 1.0 + ($realmDiff * 0.05));
        return $suppression;
    }

    /**
     * Get all realm effects for a combat scenario
     * 
     * @param int $attackerRealmLevel Attacker's realm level
     * @param int $defenderRealmLevel Defender's realm level
     * @return array All realm effects
     */
    public function getAllEffects(int $attackerRealmLevel, int $defenderRealmLevel): array
    {
        return [
            'defense_penetration' => $this->getDefensePenetration($attackerRealmLevel, $defenderRealmLevel),
            'damage_reflection' => $this->getDamageReflection($defenderRealmLevel, $attackerRealmLevel),
            'realm_suppression' => $this->getRealmSuppression($attackerRealmLevel, $defenderRealmLevel)
        ];
    }
}
