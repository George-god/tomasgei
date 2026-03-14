<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/StatCalculator.php';

/**
 * Central stat access facade.
 */
class StatService
{
    private StatCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new StatCalculator();
    }

    public function calculateFinalStats(int $userId): array
    {
        return $this->calculator->calculateFinalStats($userId);
    }

    public function getFinalAttack(int $userId): int
    {
        return $this->calculator->getFinalAttack($userId);
    }

    public function getFinalDefense(int $userId): int
    {
        return $this->calculator->getFinalDefense($userId);
    }

    public function getFinalMaxChi(int $userId): int
    {
        return $this->calculator->getFinalMaxChi($userId);
    }
}
