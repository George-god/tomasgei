<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDOException;

/**
 * Cultivation service.
 * Server-side only. Cooldown 10s. Chi gain: random(20–40) + (level × 3).
 * On level up: level++, attack/defense/max_chi up, max_chi *= 1.25, carry remaining chi.
 * No realm, breakthrough, professions, or world systems.
 */
class CultivationService
{
    private const COOLDOWN_SECONDS = 10;
    private const CHI_RAND_MIN = 20;
    private const CHI_RAND_MAX = 40;
    private const LEVEL_CHI_FACTOR = 3;
    private const MAX_CHI_MULTIPLIER = 1.25;
    private const STAT_GAIN_PER_LEVEL = 2;

    /**
     * Process cultivation. Server-side validation only.
     *
     * @return array success, error?, chi_gained, chi_before, chi_after, max_chi, level_up?, new_level?, new_max_chi?, cooldown_remaining
     */
    public function cultivate(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $user = $this->fetchUser($db, $userId);
            if (!$user) {
                $db->rollBack();
                return $this->fail('User not found.');
            }

            $cooldown = $this->checkCooldown($user);
            if (!$cooldown['can_cultivate']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => $cooldown['error'],
                    'cooldown_remaining' => $cooldown['cooldown_remaining']
                ];
            }

            $level = (int)$user['level'];
            $currentChi = (int)$user['chi'];
            $maxChi = (int)$user['max_chi'];

            $chiGain = $this->calculateChiGain($level);
            $sectService = new SectService();
            $sectBonuses = $sectService->getBonusesForUser($userId);
            $chiGain = max(1, (int)floor($chiGain * (1.0 + $sectBonuses['cultivation_speed'])));
            $newChi = min(max(0, $currentChi) + $chiGain, max(0, $maxChi));
            $newChi = max(0, $newChi);
            $actualGain = $newChi - max(0, $currentChi);

            $stmt = $db->prepare("UPDATE users SET chi = GREATEST(0, ?), last_cultivation_at = NOW() WHERE id = ?");
            $stmt->execute([$newChi, $userId]);

            $levelUpResult = $this->tryLevelUp($db, $userId, $newChi, $maxChi, $level, $newChi);

            $db->commit();

            $out = [
                'success' => true,
                'chi_gained' => $actualGain,
                'chi_before' => $currentChi,
                'chi_after' => $levelUpResult['chi_after'],
                'max_chi' => $levelUpResult['max_chi'],
                'level_up' => $levelUpResult['leveled_up'],
                'new_level' => $levelUpResult['new_level'],
                'new_max_chi' => $levelUpResult['new_max_chi'],
                'cooldown_remaining' => 0
            ];
            return $out;
        } catch (\Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("CultivationService::cultivate " . $e->getMessage());
            return $this->fail('Cultivation failed. Please try again.');
        }
    }

    /**
     * Chi gain: random(20–40) + (level × 3).
     */
    private function calculateChiGain(int $level): int
    {
        $randomPart = mt_rand(self::CHI_RAND_MIN, self::CHI_RAND_MAX);
        $levelPart = $level * self::LEVEL_CHI_FACTOR;
        return $randomPart + $levelPart;
    }

    private function checkCooldown(array $user): array
    {
        $last = $user['last_cultivation_at'];
        if ($last === null) {
            return ['can_cultivate' => true, 'error' => null, 'cooldown_remaining' => 0];
        }
        $elapsed = time() - (int)strtotime($last);
        $remaining = self::COOLDOWN_SECONDS - $elapsed;
        if ($remaining > 0) {
            return [
                'can_cultivate' => false,
                'error' => 'You must wait before cultivating again.',
                'cooldown_remaining' => $remaining
            ];
        }
        return ['can_cultivate' => true, 'error' => null, 'cooldown_remaining' => 0];
    }

    /**
     * On level up: level++, attack/defense +STAT_GAIN, max_chi *= 1.25, carry remaining chi.
     */
    private function tryLevelUp(\PDO $db, int $userId, int $currentChi, int $maxChi, int $level, int $chiAfterCultivate): array
    {
        if ($currentChi < $maxChi) {
            return [
                'leveled_up' => false,
                'new_level' => null,
                'new_max_chi' => null,
                'chi_after' => $chiAfterCultivate,
                'max_chi' => $maxChi
            ];
        }

        $newLevel = $level + 1;
        $newMaxChi = max(0, (int)floor($maxChi * self::MAX_CHI_MULTIPLIER));
        $chiToKeep = max(0, $chiAfterCultivate);

        $stmt = $db->prepare("
            UPDATE users
            SET level = ?, max_chi = GREATEST(0, ?), chi = GREATEST(0, ?),
                attack = GREATEST(0, attack + ?), defense = GREATEST(0, defense + ?)
            WHERE id = ?
        ");
        $stmt->execute([
            $newLevel,
            $newMaxChi,
            $chiToKeep,
            self::STAT_GAIN_PER_LEVEL,
            self::STAT_GAIN_PER_LEVEL,
            $userId
        ]);

        return [
            'leveled_up' => true,
            'new_level' => $newLevel,
            'new_max_chi' => $newMaxChi,
            'chi_after' => $chiToKeep,
            'max_chi' => $newMaxChi
        ];
    }

    private function fetchUser(\PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare("SELECT id, level, chi, max_chi, attack, defense, last_cultivation_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function fail(string $error, int $cooldownRemaining = 0): array
    {
        return [
            'success' => false,
            'error' => $error,
            'cooldown_remaining' => $cooldownRemaining
        ];
    }

    /**
     * Get cooldown status for UI. Returns cooldown_remaining (seconds) when blocked.
     */
    public function getCooldownStatus(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT last_cultivation_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || $user['last_cultivation_at'] === null) {
                return [
                    'can_cultivate' => true,
                    'cooldown_remaining' => 0,
                    'next_cultivation_at' => null
                ];
            }

            $elapsed = time() - (int)strtotime($user['last_cultivation_at']);
            $remaining = max(0, self::COOLDOWN_SECONDS - $elapsed);

            return [
                'can_cultivate' => $remaining === 0,
                'cooldown_remaining' => $remaining,
                'next_cultivation_at' => $remaining > 0
                    ? date('Y-m-d H:i:s', (int)strtotime($user['last_cultivation_at']) + self::COOLDOWN_SECONDS)
                    : null
            ];
        } catch (PDOException $e) {
            error_log("CultivationService::getCooldownStatus " . $e->getMessage());
            return [
                'can_cultivate' => false,
                'cooldown_remaining' => self::COOLDOWN_SECONDS,
                'next_cultivation_at' => null
            ];
        }
    }

    /**
     * Expected chi gain range for current level (for UI). Formula: random(20–40) + (level × 3).
     */
    public function getCultivationEfficiency(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT level FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                return ['min_gain' => 0, 'max_gain' => 0, 'average_gain' => 0, 'level' => 1];
            }
            $level = (int)$user['level'];
            $minGain = self::CHI_RAND_MIN + $level * self::LEVEL_CHI_FACTOR;
            $maxGain = self::CHI_RAND_MAX + $level * self::LEVEL_CHI_FACTOR;
            return [
                'min_gain' => $minGain,
                'max_gain' => $maxGain,
                'average_gain' => (int)round(($minGain + $maxGain) / 2),
                'level' => $level
            ];
        } catch (PDOException $e) {
            return ['min_gain' => 0, 'max_gain' => 0, 'average_gain' => 0, 'level' => 1];
        }
    }
}
