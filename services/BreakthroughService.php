<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/ItemService.php';
require_once __DIR__ . '/TribulationService.php';
require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDOException;

/**
 * Breakthrough Service - realm tier advancement with major tribulations.
 *
 * - Can attempt when: level >= next realm required_level, not at max realm.
 * - Base success 85%; pill adds bonus (cap 98%); pill consumed when used.
 * - Success roll triggers a 3-phase tribulation.
 * - Final realm gain only happens if the tribulation is survived.
 * - Failure before tribulation reduces chi by 15%; tribulation failure applies a harsher setback.
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
     * @return array success, message/error, success_chance?, chi_after?, breakthrough_attempts?, tribulation_id?
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
                $stmt = $db->prepare("
                    SELECT i.id, i.quantity, i.item_template_id, COALESCE(t.breakthrough_bonus, 0) AS breakthrough_bonus
                    FROM inventory i
                    JOIN item_templates t ON t.id = i.item_template_id
                    WHERE i.id = ? AND i.user_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt->execute([$pillInventoryId, $userId]);
                $inv = $stmt->fetch();
                if (!$inv || (int)$inv['quantity'] < 1) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Invalid or missing pill.'];
                }
                if ((int)($inv['breakthrough_bonus'] ?? 0) <= 0) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'That item is not a breakthrough pill.'];
                }
                $pillBonus = (int)$inv['breakthrough_bonus'];
                $successChance = min(self::MAX_SUCCESS_CHANCE, $successChance + $pillBonus / 100.0);
                if ((int)$inv['quantity'] <= 1) {
                    $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?")->execute([$pillInventoryId, $userId]);
                } else {
                    $db->prepare("UPDATE inventory SET quantity = quantity - 1, updated_at = NOW() WHERE id = ? AND user_id = ?")
                        ->execute([$pillInventoryId, $userId]);
                }
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
                $tribulationService = new TribulationService();
                $tribulationResult = $tribulationService->processTribulation(
                    $userId,
                    $currentRealmId,
                    (int)$nextRealm['id'],
                    [
                        'pill_bonus' => $pillBonus / 100.0,
                        'sect_breakthrough_bonus' => (float)$sectBonuses['breakthrough'],
                        'rune_type' => isset($user['active_scroll_type']) && $user['active_scroll_type'] !== '' ? (string)$user['active_scroll_type'] : null,
                        'breakthrough_attempts' => (int)($user['breakthrough_attempts'] ?? 0),
                    ],
                    $db
                );

                if (($tribulationResult['tribulation_id'] ?? 0) < 1 && empty($tribulationResult['success'])) {
                    $db->rollBack();
                    return ['success' => false, 'error' => $tribulationResult['error'] ?? 'Tribulation processing failed.'];
                }

                if (!empty($tribulationResult['success'])) {
                    $sectService->addSectExp($userId, 25);
                    $sectService->addSectContribution($userId, 10);
                }

                $db->commit();

                return [
                    'success' => (bool)($tribulationResult['success'] ?? false),
                    'message' => !empty($tribulationResult['success'])
                        ? "Breakthrough successful! You endured {$tribulationResult['tribulation_label']} and ascended to {$nextRealm['name']}."
                        : ($tribulationResult['message'] ?? 'The tribulation crushed your breakthrough attempt.'),
                    'error' => !empty($tribulationResult['success']) ? null : ($tribulationResult['message'] ?? 'The tribulation crushed your breakthrough attempt.'),
                    'realm_id' => !empty($tribulationResult['success']) ? (int)$nextRealm['id'] : $currentRealmId,
                    'realm_name' => !empty($tribulationResult['success']) ? (string)$nextRealm['name'] : (string)($user['realm_name'] ?? ''),
                    'success_chance' => $successChance,
                    'chi_after' => (int)($tribulationResult['chi_after'] ?? $currentChi),
                    'breakthrough_attempts' => !empty($tribulationResult['success']) ? 0 : ((int)($user['breakthrough_attempts'] ?? 0) + 1),
                    'tribulation_id' => (int)($tribulationResult['tribulation_id'] ?? 0),
                    'tribulation_label' => (string)($tribulationResult['tribulation_label'] ?? ''),
                    'tribulation_result' => $tribulationResult,
                ];
            }

            $newChi = (int)floor($currentChi * (1 - self::FAILURE_CHI_REDUCTION));
            $attempts = (int)($user['breakthrough_attempts'] ?? 0) + 1;
            $db->prepare("UPDATE users SET chi = GREATEST(0, ?), breakthrough_attempts = ?, active_scroll_type = NULL WHERE id = ?")
                ->execute([$newChi, $attempts, $userId]);
            $db->commit();

            return [
                'success' => false,
                'error' => 'Breakthrough failed before the tribulation could form. Your chi has been reduced. Try again when ready.',
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
     * Get breakthrough status for UI: can attempt, next realm, base success chance, preparation, and available pills.
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

            $activeScrollType = isset($user['active_scroll_type']) && $user['active_scroll_type'] !== '' ? (string)$user['active_scroll_type'] : null;
            $sectService = new SectService();
            $sectBonuses = $sectService->getBonusesForUser($userId);
            $tribulationDifficultyPreview = round(1.0 + max(0, ((int)$nextRealm['id'] - 1) * 0.08) + min(0.36, (int)($user['breakthrough_attempts'] ?? 0) * 0.05), 3);

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
                'breakthrough_attempts' => (int)($user['breakthrough_attempts'] ?? 0),
                'active_scroll_type' => $activeScrollType,
                'sect_breakthrough_bonus' => (float)($sectBonuses['breakthrough'] ?? 0.0),
                'tribulation_difficulty_preview' => $tribulationDifficultyPreview,
                'tribulation_phase_count' => 3,
                'pills' => $pills,
            ];
        } catch (PDOException $e) {
            error_log("BreakthroughService::getBreakthroughStatus " . $e->getMessage());
            return ['can_attempt' => false, 'error' => 'Unable to load status.'];
        }
    }
}
