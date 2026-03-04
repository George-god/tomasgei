<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/ItemService.php';

use Game\Config\Database;
use PDOException;

/**
 * Breakthrough Service - realm tier advancement with failure chance and optional pill.
 *
 * - Can attempt when: level >= next realm required_level, not at max realm.
 * - Base success 85%; pill adds bonus (cap 98%); pill consumed when used.
 * - Success: realm_id = next, chi = 0, breakthrough_attempts = 0.
 * - Failure: chi reduced by 15%, breakthrough_attempts++.
 * - No tribulation, no cooldown, no max-chi requirement. Modular for future expansion.
 */
class BreakthroughService
{
    private const BASE_SUCCESS_CHANCE = 0.85;
    private const MAX_SUCCESS_CHANCE = 0.98;
    private const FAILURE_CHI_REDUCTION = 0.15; // 15% reduction (keep 85% of current chi)

    /**
     * Attempt realm breakthrough. Optional pill: add template.breakthrough_bonus (%) to chance, cap 98%, consume one.
     *
     * @param int $userId User ID
     * @param int|null $pillInventoryId Inventory ID of breakthrough pill to use, or null
     * @return array success, message/error, success_chance?, chi_after?, breakthrough_attempts?
     */
    public function attemptBreakthrough(int $userId, ?int $pillInventoryId = null): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $user = $this->fetchUserWithRealm($db, $userId);
            if (!$user) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }

            $currentRealmId = (int)$user['realm_id'];
            $currentLevel = (int)$user['level'];
            $currentChi = (int)$user['chi'];

            $nextRealm = $this->getNextRealmByRequiredLevel($db, $currentRealmId);
            if (!$nextRealm) {
                $db->rollBack();
                return ['success' => false, 'error' => 'You have reached the highest realm.'];
            }

            $requiredLevel = (int)($nextRealm['required_level'] ?? $nextRealm['min_level'] ?? 0);
            if ($currentLevel < $requiredLevel) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => "Reach level {$requiredLevel} to attempt breakthrough to {$nextRealm['name']}.",
                ];
            }

            $successChance = self::BASE_SUCCESS_CHANCE;
            $pillBonus = 0;

            if ($pillInventoryId !== null && $pillInventoryId > 0) {
                $itemService = new ItemService();
                $inv = $itemService->getInventoryRow($userId, $pillInventoryId);
                if (!$inv || (int)$inv['quantity'] < 1) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Invalid or missing pill.'];
                }
                $template = $itemService->getTemplateById((int)$inv['item_template_id']);
                if (!$template || (int)($template['breakthrough_bonus'] ?? 0) <= 0) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'That item is not a breakthrough pill.'];
                }
                $pillBonus = (int)$template['breakthrough_bonus'];
                $successChance = min(self::MAX_SUCCESS_CHANCE, $successChance + $pillBonus / 100.0);
                $itemService->consumeOne($userId, $pillInventoryId);
            }

            if (isset($user['active_scroll_type']) && $user['active_scroll_type'] === 'focus') {
                $successChance = min(self::MAX_SUCCESS_CHANCE, $successChance + 0.05);
            }
            $sectService = new SectService();
            $sectBonuses = $sectService->getBonusesForUser($userId);
            $successChance = min(self::MAX_SUCCESS_CHANCE, $successChance + $sectBonuses['breakthrough']);

            $roll = mt_rand(1, 10000) / 10000.0;
            $success = $roll <= $successChance;

            if ($success) {
                $sectService->addSectExp($userId, 25);
                $sectService->addSectContribution($userId, 10);
                $db->prepare("UPDATE users SET realm_id = ?, chi = 0, breakthrough_attempts = 0, active_scroll_type = NULL WHERE id = ?")
                    ->execute([(int)$nextRealm['id'], $userId]);
                $db->commit();
                return [
                    'success' => true,
                    'message' => "Breakthrough successful! You have ascended to {$nextRealm['name']}.",
                    'realm_id' => (int)$nextRealm['id'],
                    'realm_name' => (string)$nextRealm['name'],
                    'success_chance' => $successChance,
                    'chi_after' => 0,
                    'breakthrough_attempts' => 0,
                ];
            }

            $newChi = (int)floor($currentChi * (1 - self::FAILURE_CHI_REDUCTION));
            $attempts = (int)($user['breakthrough_attempts'] ?? 0) + 1;
            $db->prepare("UPDATE users SET chi = GREATEST(0, ?), breakthrough_attempts = ?, active_scroll_type = NULL WHERE id = ?")
                ->execute([$newChi, $attempts, $userId]);
            $db->commit();

            return [
                'success' => false,
                'error' => 'Breakthrough failed. Your chi has been reduced. Try again when ready.',
                'success_chance' => $successChance,
                'chi_after' => $newChi,
                'breakthrough_attempts' => $attempts,
            ];
        } catch (\Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("BreakthroughService::attemptBreakthrough " . $e->getMessage());
            return ['success' => false, 'error' => 'Breakthrough failed. Please try again.'];
        }
    }

    /**
     * Get next realm by required_level (used for tier breakthrough). Fallback to min_level if column missing.
     */
    private function getNextRealmByRequiredLevel(\PDO $db, int $currentRealmId): ?array
    {
        $col = 'required_level';
        try {
            $stmt = $db->query("SHOW COLUMNS FROM realms LIKE 'required_level'");
            if (!$stmt->fetch()) {
                $col = 'min_level';
            }
        } catch (\Throwable $e) {
            $col = 'min_level';
        }

        $stmt = $db->prepare("SELECT id, name, min_level, $col as required_level FROM realms WHERE id = ? LIMIT 1");
        $stmt->execute([$currentRealmId]);
        $current = $stmt->fetch();
        if (!$current) {
            return null;
        }
        $currentRequired = (int)($current['required_level'] ?? $current['min_level'] ?? 0);
        $stmt = $db->prepare("SELECT id, name, min_level, $col as required_level FROM realms WHERE $col > ? ORDER BY $col ASC LIMIT 1");
        $stmt->execute([$currentRequired]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function fetchUserWithRealm(\PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare("SELECT u.*, r.name as realm_name FROM users u LEFT JOIN realms r ON u.realm_id = r.id WHERE u.id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get breakthrough status for UI: can attempt, next realm, base success chance, available pills.
     */
    public function getBreakthroughStatus(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $user = $this->fetchUserWithRealm($db, $userId);
            if (!$user) {
                return ['can_attempt' => false, 'error' => 'User not found.'];
            }

            $currentLevel = (int)$user['level'];
            $currentRealmId = (int)$user['realm_id'];
            $nextRealm = $this->getNextRealmByRequiredLevel($db, $currentRealmId);

            if (!$nextRealm) {
                return [
                    'can_attempt' => false,
                    'error' => 'You have reached the highest realm.',
                    'success_chance' => null,
                    'next_realm' => null,
                    'pills' => [],
                ];
            }

            $requiredLevel = (int)($nextRealm['required_level'] ?? $nextRealm['min_level'] ?? 0);
            $canAttempt = $currentLevel >= $requiredLevel;

            $itemService = new ItemService();
            $pills = $itemService->getBreakthroughPills($userId);

            return [
                'can_attempt' => $canAttempt,
                'error' => $canAttempt ? null : "Reach level {$requiredLevel} to attempt breakthrough.",
                'success_chance' => self::BASE_SUCCESS_CHANCE,
                'success_chance_max' => self::MAX_SUCCESS_CHANCE,
                'next_realm' => [
                    'id' => (int)$nextRealm['id'],
                    'name' => (string)$nextRealm['name'],
                    'required_level' => $requiredLevel,
                ],
                'level_current' => $currentLevel,
                'pills' => $pills,
            ];
        } catch (PDOException $e) {
            error_log("BreakthroughService::getBreakthroughStatus " . $e->getMessage());
            return ['can_attempt' => false, 'error' => 'Unable to load status.'];
        }
    }
}
