<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Spirit Herbalist: one herb plot per user, 30 min growth, yield = 2 + floor(effective_level/3).
 * Main = 100% effect, secondary = 50% effect.
 */
class HerbalistService
{
    private const GROWTH_MINUTES = 30;
    private const BASE_YIELD = 2;
    private const HERBALIST_PROFESSION_ID = 3;
    /** Default herb template for harvest (Spirit Grass). */
    private const DEFAULT_HERB_TEMPLATE_ID = 10;

    /**
     * Get user's herb plot (one row per user; may not exist until first plant).
     */
    public function getPlot(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, user_id, planted_at, ready_at, is_harvested FROM herb_plots WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("HerbalistService::getPlot " . $e->getMessage());
            return null;
        }
    }

    /**
     * Plant: must have Spirit Herbalist (main or secondary), no active plot. Growth 30 min.
     */
    public function plant(int $userId): array
    {
        $professionService = new ProfessionService();
        $prof = $professionService->getUserProfession($userId, self::HERBALIST_PROFESSION_ID);
        if (!$prof) {
            return ['success' => false, 'message' => 'You must be a Spirit Herbalist (main or secondary) to use the herb plot.'];
        }

        $plot = $this->getPlot($userId);
        if ($plot && (int)$plot['is_harvested'] === 0) {
            return ['success' => false, 'message' => 'You already have an active plot. Harvest it first.'];
        }

        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $readyAt = date('Y-m-d H:i:s', strtotime($now . ' +' . self::GROWTH_MINUTES . ' minutes'));

            if ($plot) {
                $db->prepare("UPDATE herb_plots SET planted_at = ?, ready_at = ?, is_harvested = 0, updated_at = NOW() WHERE user_id = ?")
                    ->execute([$now, $readyAt, $userId]);
            } else {
                $db->prepare("INSERT INTO herb_plots (user_id, planted_at, ready_at, is_harvested) VALUES (?, ?, ?, 0)")
                    ->execute([$userId, $now, $readyAt]);
            }
            return [
                'success' => true,
                'message' => 'Planted. Ready in ' . self::GROWTH_MINUTES . ' minutes.',
                'data' => ['ready_at' => $readyAt]
            ];
        } catch (PDOException $e) {
            error_log("HerbalistService::plant " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Harvest: plot must be ready (ready_at <= NOW()). Yield = 2 + floor(effective_level/3).
     */
    public function harvest(int $userId): array
    {
        $professionService = new ProfessionService();
        $prof = $professionService->getUserProfession($userId, self::HERBALIST_PROFESSION_ID);
        if (!$prof) {
            return ['success' => false, 'message' => 'You must be a Spirit Herbalist to harvest.'];
        }

        $plot = $this->getPlot($userId);
        if (!$plot || (int)$plot['is_harvested'] === 1) {
            return ['success' => false, 'message' => 'No active plot to harvest.'];
        }

        $readyAt = strtotime($plot['ready_at']);
        if ($readyAt > time()) {
            return ['success' => false, 'message' => 'Plot is not ready yet.'];
        }

        $level = (int)$prof['level'];
        $role = (string)($prof['role'] ?? 'main');
        $effectiveLevel = ProfessionService::getEffectiveLevel($level, $role);
        $yield = self::BASE_YIELD + (int)floor($effectiveLevel / 3);
        $yield = max(1, $yield);

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $db->prepare("UPDATE herb_plots SET is_harvested = 1, updated_at = NOW() WHERE user_id = ?")->execute([$userId]);

            $itemService = new ItemService();
            $add = $itemService->addItemToInventory($userId, self::DEFAULT_HERB_TEMPLATE_ID, $yield);
            if (!$add['success']) {
                $db->rollBack();
                return ['success' => false, 'message' => $add['message'] ?? 'Could not add herbs.'];
            }

            $db->commit();
            return [
                'success' => true,
                'message' => "Harvested {$yield} herbs.",
                'data' => ['yield' => $yield]
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("HerbalistService::harvest " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }
}
