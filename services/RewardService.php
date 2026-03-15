<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Centralized reward logic for Phase 2: PvP/PvE win rewards, sect EXP and contribution.
 * Single place for gold/spirit stone grants with sect gold bonus. No negative values.
 */
class RewardService
{
    private const PVE_SECT_EXP = 3;
    private const PVE_SECT_CONTRIBUTION = 2;
    private const PVP_GOLD_BASE = 25;
    private const PVP_SECT_EXP = 10;
    private const PVP_SECT_CONTRIBUTION = 5;

    /**
     * Grant gold and spirit stones to user. Applies sect gold bonus to gold only.
     * Ensures no negative: UPDATE uses GREATEST(0, gold + amount). Caller must pass non-negative amounts.
     */
    public function grantCurrency(PDO $db, int $userId, int $gold, int $spiritStones): void
    {
        if ($gold <= 0 && $spiritStones <= 0) {
            return;
        }
        $sectService = new SectService();
        $bonuses = $sectService->getBonusesForUser($userId);
        $goldFinal = $gold > 0 ? max(0, (int)round($gold * (1.0 + $bonuses['gold_gain']))) : 0;
        $spiritFinal = max(0, $spiritStones);

        $sets = [];
        $params = [':id' => $userId];
        if ($goldFinal > 0) {
            $sets[] = 'gold = GREATEST(0, gold + :gold)';
            $params[':gold'] = $goldFinal;
        }
        if ($spiritFinal > 0) {
            $sets[] = 'spirit_stones = GREATEST(0, spirit_stones + :spirit)';
            $params[':spirit'] = $spiritFinal;
        }
        if ($sets === []) {
            return;
        }
        $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Apply full PvE win rewards: currency (with sect gold bonus) + sect EXP + contribution.
     * Returns [gold_gained, spirit_stone_gained] for response (amounts actually granted).
     */
    public function applyPvEWinRewards(PDO $db, int $userId, int $goldBase, int $spiritStonesBase): array
    {
        $this->grantCurrency($db, $userId, $goldBase, $spiritStonesBase);
        $sectService = new SectService();
        $sectService->addSectExp($userId, self::PVE_SECT_EXP);
        $sectService->addSectContribution($userId, self::PVE_SECT_CONTRIBUTION);

        $bonuses = $sectService->getBonusesForUser($userId);
        $goldGained = $goldBase > 0 ? max(0, (int)round($goldBase * (1.0 + $bonuses['gold_gain']))) : 0;
        return [
            'gold_gained' => $goldGained,
            'spirit_stone_gained' => max(0, $spiritStonesBase),
        ];
    }

    /**
     * Apply full PvP win rewards: gold (25 + rating/100, sect bonus) + sect EXP + contribution.
     */
    public function applyPvPWinRewards(PDO $db, int $winnerId, float $winnerRatingAfter): void
    {
        $goldBase = self::PVP_GOLD_BASE + (int)floor($winnerRatingAfter / 100);
        if ($goldBase > 0) {
            $this->grantCurrency($db, $winnerId, $goldBase, 0);
        }
        $sectService = new SectService();
        $sectService->addSectExp($winnerId, self::PVP_SECT_EXP);
        $sectService->addSectContribution($winnerId, self::PVP_SECT_CONTRIBUTION);
    }
}
