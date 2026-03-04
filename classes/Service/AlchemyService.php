<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 2 Alchemy: validate materials, success chance from profession level, consume/grant, EXP and level up.
 */
class AlchemyService
{
    private const SUCCESS_CAP = 0.95;
    private const EXP_PER_LEVEL = 100;

    /**
     * Get all recipes with result item name.
     */
    public function getRecipes(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT r.id, r.name, r.result_item_template_id, r.required_herbs, r.gold_cost,
                       r.base_success_rate, r.exp_reward,
                       t.name AS result_item_name
                FROM alchemy_recipes r
                JOIN item_templates t ON t.id = r.result_item_template_id
                ORDER BY r.id
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("AlchemyService::getRecipes " . $e->getMessage());
            return [];
        }
    }

    /**
     * Total herb count in user inventory (all herb-type items).
     */
    public function getHerbCount(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(i.quantity), 0) AS total
                FROM inventory i
                JOIN item_templates t ON t.id = i.item_template_id
                WHERE i.user_id = ? AND t.type = 'herb'
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("AlchemyService::getHerbCount " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Craft: validate materials, success = base_success_rate + (level * 0.01), cap 95%.
     * Consume herbs and gold; on success add pill and grant EXP; handle level up (required_exp = 100 * level).
     */
    public function craft(int $userId, int $recipeId): array
    {
        $professionService = new ProfessionService();
        $prof = $professionService->getUserProfession($userId, 1);
        if (!$prof) {
            return ['success' => false, 'message' => 'You must be an Alchemist (main or secondary) to craft.'];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, result_item_template_id, required_herbs, gold_cost, base_success_rate, exp_reward FROM alchemy_recipes WHERE id = ?");
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe) {
            return ['success' => false, 'message' => 'Recipe not found.'];
        }

        $requiredHerbs = (int)$recipe['required_herbs'];
        $goldCost = (int)$recipe['gold_cost'];
        $baseRate = (float)$recipe['base_success_rate'];
        $expReward = (int)$recipe['exp_reward'];
        $resultTemplateId = (int)$recipe['result_item_template_id'];

        $herbCount = $this->getHerbCount($userId);
        if ($herbCount < $requiredHerbs) {
            return ['success' => false, 'message' => "Not enough herbs. Need {$requiredHerbs}, have {$herbCount}."];
        }

        $goldStmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
        $goldStmt->execute([$userId]);
        $userGold = (int)($goldStmt->fetch()['gold'] ?? 0);
        if ($userGold < $goldCost) {
            return ['success' => false, 'message' => "Not enough gold. Need {$goldCost}."];
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
            $this->consumeHerbs($db, $userId, $requiredHerbs);

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
            $db->prepare("UPDATE user_professions SET experience = ?, level = ? WHERE user_id = ? AND profession_id = 1")->execute([$newExp, $newLevel, $userId]);

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
            error_log("AlchemyService::craft " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Consume N herbs from user inventory (any herb stacks).
     */
    private function consumeHerbs(PDO $db, int $userId, int $n): void
    {
        $stmt = $db->prepare("
            SELECT i.id, i.quantity
            FROM inventory i
            JOIN item_templates t ON t.id = i.item_template_id
            WHERE i.user_id = ? AND t.type = 'herb'
            ORDER BY i.id
        ");
        $stmt->execute([$userId]);
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
