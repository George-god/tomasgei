<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 2.3 Rune Engraver: craft scrolls from Rune Fragments.
 * Success = base_success_rate + (profession_level × 0.01), cap 95%.
 */
class RuneService
{
    private const SUCCESS_CAP = 0.95;
    private const EXP_PER_LEVEL = 100;
    private const RUNE_ENGRAVER_PROFESSION_ID = 4;
    private const RUNE_FRAGMENT_TEMPLATE_ID = 56;

    /**
     * Get all rune recipes with result item name.
     */
    public function getRecipes(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT r.id, r.name, r.result_item_template_id, r.required_materials, r.gold_cost,
                       r.base_success_rate, r.exp_reward,
                       t.name AS result_item_name
                FROM rune_recipes r
                JOIN item_templates t ON t.id = r.result_item_template_id
                ORDER BY r.id
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("RuneService::getRecipes " . $e->getMessage());
            return [];
        }
    }

    /**
     * Total Rune Fragment count in user inventory (item_template_id = 56).
     */
    public function getRuneFragmentCount(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(quantity), 0) AS total
                FROM inventory
                WHERE user_id = ? AND item_template_id = ?
            ");
            $stmt->execute([$userId, self::RUNE_FRAGMENT_TEMPLATE_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("RuneService::getRuneFragmentCount " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Craft: validate profession 4 (main or secondary), materials and gold.
     * Success = base_success_rate + (effective_level × 0.01), cap 95%.
     * Consume Rune Fragments and gold; on success add one scroll and grant EXP; level up (100 × level).
     */
    public function craft(int $userId, int $recipeId): array
    {
        $professionService = new ProfessionService();
        $prof = $professionService->getUserProfession($userId, self::RUNE_ENGRAVER_PROFESSION_ID);
        if (!$prof) {
            return ['success' => false, 'message' => 'You must be a Rune Engraver (main or secondary) to craft.'];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, result_item_template_id, required_materials, gold_cost, base_success_rate, exp_reward FROM rune_recipes WHERE id = ?");
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe) {
            return ['success' => false, 'message' => 'Recipe not found.'];
        }

        $requiredMaterials = (int)$recipe['required_materials'];
        $goldCost = (int)$recipe['gold_cost'];
        $baseRate = (float)$recipe['base_success_rate'];
        $expReward = (int)$recipe['exp_reward'];
        $resultTemplateId = (int)$recipe['result_item_template_id'];

        $fragmentCount = $this->getRuneFragmentCount($userId);
        if ($fragmentCount < $requiredMaterials) {
            return ['success' => false, 'message' => "Not enough Rune Fragments. Need {$requiredMaterials}, have {$fragmentCount}."];
        }

        $level = (int)$prof['level'];
        $experience = (int)$prof['experience'];
        $role = (string)($prof['role'] ?? 'main');
        $effectiveLevel = ProfessionService::getEffectiveLevel($level, $role);
        $successRate = min(self::SUCCESS_CAP, $baseRate + $effectiveLevel * 0.01);
        $roll = mt_rand(1, 10000) / 10000;
        $craftSuccess = $roll <= $successRate;

        try {
            $db->beginTransaction();
            $lock = $db->prepare("SELECT gold FROM users WHERE id = ? FOR UPDATE");
            $lock->execute([$userId]);
            $currentGold = (int)($lock->fetchColumn() ?: 0);
            if ($currentGold < $goldCost) {
                $db->rollBack();
                return ['success' => false, 'message' => "Not enough gold. Need {$goldCost}."];
            }
            $db->prepare("UPDATE users SET gold = GREATEST(0, gold - ?) WHERE id = ?")->execute([$goldCost, $userId]);
            $this->consumeRuneFragments($db, $userId, $requiredMaterials);

            if ($craftSuccess) {
                $itemService = new ItemService();
                $add = $itemService->addItemToInventory($userId, $resultTemplateId, 1);
                if (!$add['success']) {
                    $db->rollBack();
                    return ['success' => false, 'message' => $add['message'] ?? 'Could not add result.'];
                }
            }

            $newExp = $experience + $expReward;
            $requiredExp = self::EXP_PER_LEVEL * $level;
            $newLevel = $level;
            while ($newExp >= $requiredExp && $requiredExp > 0) {
                $newExp -= $requiredExp;
                $newLevel++;
                $requiredExp = self::EXP_PER_LEVEL * $newLevel;
            }
            $db->prepare("UPDATE user_professions SET experience = ?, level = ? WHERE user_id = ? AND profession_id = ?")->execute([$newExp, $newLevel, $userId, self::RUNE_ENGRAVER_PROFESSION_ID]);

            $db->commit();

            return [
                'success' => true,
                'craft_success' => $craftSuccess,
                'message' => $craftSuccess ? 'Craft successful!' : 'Craft failed. Materials consumed.',
                'data' => [
                    'craft_success' => $craftSuccess,
                    'success_rate_used' => round($successRate * 100, 1),
                    'exp_gained' => $expReward,
                    'new_level' => $newLevel,
                    'new_experience' => $newExp,
                ]
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("RuneService::craft " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Consume N Rune Fragments from user inventory (item_template_id = 56).
     */
    private function consumeRuneFragments(PDO $db, int $userId, int $n): void
    {
        $stmt = $db->prepare("
            SELECT id, quantity
            FROM inventory
            WHERE user_id = ? AND item_template_id = ?
            ORDER BY id
        ");
        $stmt->execute([$userId, self::RUNE_FRAGMENT_TEMPLATE_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $remaining = $n;
        foreach ($rows as $row) {
            if ($remaining <= 0) break;
            $id = (int)$row['id'];
            $qty = (int)$row['quantity'];
            $take = min($remaining, $qty);
            $remaining -= $take;
            if ($take >= $qty) {
                $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
            } else {
                $db->prepare("UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$take, $id, $userId]);
            }
        }
    }
}
