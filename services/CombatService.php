<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/BattleService.php';
require_once __DIR__ . '/PvEBattleService.php';

/**
 * Central combat orchestration facade.
 * Keeps battle rules in the existing specialized services.
 */
class CombatService
{
    private BattleService $battleService;
    private PvEBattleService $pveBattleService;

    public function __construct()
    {
        $this->battleService = new BattleService();
        $this->pveBattleService = new PvEBattleService();
    }

    public function simulatePvp(int $attackerId, int $defenderId, array $options = []): array
    {
        return $this->battleService->simulateBattle($attackerId, $defenderId, $options);
    }

    public function simulatePve(int $userId, int $npcId, bool $useDaoTechniques = true): array
    {
        return $this->pveBattleService->simulateBattle($userId, $npcId, $useDaoTechniques);
    }

    public function simulateCustomEncounter(
        int $userId,
        string $enemyName,
        int $hp,
        int $attack,
        int $defense,
        int $rewardChi = 0,
        bool $useDaoTechniques = true
    ): array {
        return $this->pveBattleService->simulateCustomBattle(
            $userId,
            $enemyName,
            $hp,
            $attack,
            $defense,
            $rewardChi,
            $useDaoTechniques
        );
    }
}
