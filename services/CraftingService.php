<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 2 Blacksmith crafting: validate materials, success from profession level, consume/grant, EXP and level up.
 */
class CraftingService
{
    private const SUCCESS_CAP = 0.95;
    private const EXP_PER_LEVEL = 100;
    private const BLACKSMITH_PROFESSION_ID = 2;

    public function getRecipes(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT r.id, r.name, r.result_item_template_id, r.result_item_template_id_excellent,
                       r.required_material_tier, r.required_materials, r.gold_cost,
                       r.base_success_rate, r.exp_reward, r.required_profession_level,
                       t.name AS result_item_name, t.type AS result_item_type, t.gear_tier,
                       t.attack_bonus, t.defense_bonus, t.hp_bonus
                FROM crafting_recipes r
                JOIN item_templates t ON t.id = r.result_item_template_id
                ORDER BY r.required_profession_level ASC, r.id
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("CraftingService::getRecipes " . $e->getMessage());
            return [];
        }
    }

    /** Total material count (all tiers). */
    public function getMaterialCount(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(i.quantity), 0) AS total
                FROM inventory i
                JOIN item_templates t ON t.id = i.item_template_id
                WHERE i.user_id = ? AND t.type = 'material'
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("CraftingService::getMaterialCount " . $e->getMessage());
            return 0;
        }
    }

    /** Material count for a specific tier (1=Iron, 2=Refined, 3=Spirit Steel). */
    public function getMaterialCountByTier(int $userId, int $tier): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(i.quantity), 0) AS total
                FROM inventory i
                JOIN item_templates t ON t.id = i.item_template_id
                WHERE i.user_id = ? AND t.type = 'material' AND t.material_tier = ?
            ");
            $stmt->execute([$userId, $tier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("CraftingService::getMaterialCountByTier " . $e->getMessage());
            return 0;
        }
    }

    public function craft(int $userId, int $recipeId): array
    {
        $professionService = new ProfessionService();
        $prof = $professionService->getUserProfession($userId, self::BLACKSMITH_PROFESSION_ID);
        if (!$prof) {
            return ['success' => false, 'message' => 'You must be a Blacksmith (main or secondary) to craft.'];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, result_item_template_id, result_item_template_id_excellent, required_material_tier, required_materials, gold_cost, base_success_rate, exp_reward, required_profession_level FROM crafting_recipes WHERE id = ?");
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe) {
            return ['success' => false, 'message' => 'Recipe not found.'];
        }

        $requiredMaterialTier = (int)($recipe['required_material_tier'] ?? 1);
        $requiredMaterials = (int)$recipe['required_materials'];
        $goldCost = (int)$recipe['gold_cost'];
        $baseRate = (float)$recipe['base_success_rate'];
        $expReward = (int)$recipe['exp_reward'];
        $requiredLevel = (int)($recipe['required_profession_level'] ?? 1);
        $resultTemplateId = (int)$recipe['result_item_template_id'];
        $resultTemplateIdExcellent = !empty($recipe['result_item_template_id_excellent']) ? (int)$recipe['result_item_template_id_excellent'] : null;

        $level = (int)$prof['level'];
        $experience = (int)$prof['experience'];
        $role = (string)($prof['role'] ?? 'main');
        $effectiveLevel = ProfessionService::getEffectiveLevel($level, $role);

        if ($level < $requiredLevel) {
            return ['success' => false, 'message' => "Blacksmith level {$requiredLevel} required for this recipe."];
        }

        if ($this->getMaterialCountByTier($userId, $requiredMaterialTier) < $requiredMaterials) {
            $tierName = [1 => 'Iron Ore', 2 => 'Refined Iron', 3 => 'Spirit Steel'][$requiredMaterialTier] ?? "tier {$requiredMaterialTier}";
            return ['success' => false, 'message' => "Not enough {$tierName}. Need {$requiredMaterials}."];
        }

        $successRate = min(self::SUCCESS_CAP, $baseRate + $effectiveLevel * 0.01);
        $craftSuccess = (mt_rand(1, 10000) / 10000) <= $successRate;
        $isExcellent = false;

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
            $this->consumeMaterialsByTier($db, $userId, $requiredMaterialTier, $requiredMaterials);

            if ($craftSuccess) {
                $itemService = new ItemService();
                $isExcellent = $resultTemplateIdExcellent && (mt_rand(1, 100) <= 20);
                $templateToAdd = $isExcellent ? $resultTemplateIdExcellent : $resultTemplateId;
                $add = $itemService->addItemToInventory($userId, $templateToAdd, 1);
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
            $db->prepare("UPDATE user_professions SET experience = ?, level = ? WHERE user_id = ? AND profession_id = ?")
                ->execute([$newExp, $newLevel, $userId, self::BLACKSMITH_PROFESSION_ID]);
            $db->commit();

            return [
                'success' => true,
                'craft_success' => $craftSuccess,
                'message' => $craftSuccess ? 'Craft successful!' : 'Craft failed. Materials consumed.',
                'data' => [
                    'craft_success' => $craftSuccess,
                    'quality' => $craftSuccess ? ($isExcellent ? 'excellent' : 'normal') : null,
                    'success_rate_used' => round($successRate * 100, 1),
                    'exp_gained' => $expReward,
                    'new_level' => $newLevel,
                    'new_experience' => $newExp,
                ]
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("CraftingService::craft " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    private function consumeMaterialsByTier(PDO $db, int $userId, int $tier, int $n): void
    {
        $stmt = $db->prepare("
            SELECT i.id, i.quantity
            FROM inventory i
            JOIN item_templates t ON t.id = i.item_template_id
            WHERE i.user_id = ? AND t.type = 'material' AND t.material_tier = ?
            ORDER BY i.id
        ");
        $stmt->execute([$userId, $tier]);
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
