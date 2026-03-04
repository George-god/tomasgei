<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * PvP stamina: anti-spam control.
 * Max 5, regen 1 every 30 minutes. Each PvP fight costs 1 (challenger pays when challenge is sent).
 */
class PvpStaminaService
{
    private const MAX_STAMINA = 5;
    private const REGEN_INTERVAL_SECONDS = 1800; // 30 minutes

    /**
     * Get current stamina for user, applying regeneration if applicable.
     * Call this on user load (dashboard, battles page) to refresh stamina.
     *
     * @return array{stamina: int, max_stamina: int, can_fight: bool, next_regen_in_seconds: int|null}
     */
    public function getStamina(int $userId): array
    {
        $this->regenIfNeeded($userId);
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT pvp_stamina, last_stamina_regen FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return [
                    'stamina' => 0,
                    'max_stamina' => self::MAX_STAMINA,
                    'can_fight' => false,
                    'next_regen_in_seconds' => null
                ];
            }
            $stamina = (int)$row['pvp_stamina'];
            $lastRegen = $row['last_stamina_regen'] ? strtotime($row['last_stamina_regen']) : null;
            $nextIn = null;
            if ($stamina < self::MAX_STAMINA && $lastRegen !== null) {
                $nextRegenAt = $lastRegen + self::REGEN_INTERVAL_SECONDS;
                $nextIn = max(0, (int)($nextRegenAt - time()));
            }
            return [
                'stamina' => $stamina,
                'max_stamina' => self::MAX_STAMINA,
                'can_fight' => $stamina > 0,
                'next_regen_in_seconds' => $nextIn
            ];
        } catch (PDOException $e) {
            error_log("PvpStaminaService::getStamina " . $e->getMessage());
            return [
                'stamina' => 0,
                'max_stamina' => self::MAX_STAMINA,
                'can_fight' => false,
                'next_regen_in_seconds' => null
            ];
        }
    }

    /**
     * Whether the user can start a PvP fight (has at least 1 stamina).
     */
    public function canFight(int $userId): bool
    {
        return $this->getStamina($userId)['can_fight'];
    }

    /**
     * Deduct 1 stamina for a PvP fight. Call when challenger sends a challenge.
     *
     * @return array{success: bool, message?: string, stamina_after?: int}
     */
    public function deductStamina(int $userId): array
    {
        $this->regenIfNeeded($userId);
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT pvp_stamina FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['success' => false, 'message' => 'User not found.'];
            }
            $current = (int)$row['pvp_stamina'];
            if ($current < 1) {
                return ['success' => false, 'message' => 'Not enough PvP stamina.'];
            }
            $newStamina = max(0, $current - 1);
            $db->prepare("UPDATE users SET pvp_stamina = GREATEST(0, LEAST(5, ?)), last_stamina_regen = COALESCE(last_stamina_regen, NOW()) WHERE id = ?")->execute([$newStamina, $userId]);
            return ['success' => true, 'stamina_after' => $newStamina];
        } catch (PDOException $e) {
            error_log("PvpStaminaService::deductStamina " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Apply regeneration: 1 stamina every 30 minutes, cap at MAX_STAMINA.
     * Updates users.pvp_stamina and last_stamina_regen.
     */
    public function regenIfNeeded(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT pvp_stamina, last_stamina_regen FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return;
            }
            $current = (int)$row['pvp_stamina'];
            if ($current >= self::MAX_STAMINA) {
                return;
            }
            $lastRegen = $row['last_stamina_regen'];
            $now = time();
            $from = $lastRegen ? strtotime($lastRegen) : $now;
            $elapsed = $now - $from;
            $intervals = (int)floor($elapsed / self::REGEN_INTERVAL_SECONDS);
            if ($intervals < 1) {
                return;
            }
            $add = min($intervals, self::MAX_STAMINA - $current);
            $newStamina = min(self::MAX_STAMINA, max(0, $current + $add));
            $newLastRegen = date('Y-m-d H:i:s', $from + $intervals * self::REGEN_INTERVAL_SECONDS);
            $db->prepare("UPDATE users SET pvp_stamina = GREATEST(0, LEAST(5, ?)), last_stamina_regen = ? WHERE id = ?")
                ->execute([$newStamina, $newLastRegen, $userId]);
        } catch (PDOException $e) {
            error_log("PvpStaminaService::regenIfNeeded " . $e->getMessage());
        }
    }
}
