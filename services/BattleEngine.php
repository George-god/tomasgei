<?php
declare(strict_types=1);

namespace Game\Service;

/**
 * Shared battle math for Phase 1. PvE uses simple damage; PvP may extend later.
 * All stat sources (attack/defense) must come from StatCalculator for players.
 */
final class BattleEngine
{
    /**
     * Simple damage formula: at least 1, otherwise attack minus defense.
     * Used by PvE. PvP uses BattleService with realm effects and procs.
     */
    public static function simpleDamage(int $attack, int $defense): int
    {
        return max(1, $attack - $defense);
    }
}
