<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/CultivationManualService.php';
require_once __DIR__ . '/PvEBattleService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * World map exploration. Lightweight cooldown-based outcomes with optional PvE encounter.
 */
class ExplorationService
{
    private const EXPLORE_COOLDOWN_SECONDS = 60;
    private const RUNE_FRAGMENT_TEMPLATE_ID = 56;

    public function getRegionsForUser(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT realm_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $userRealmId = (int)($stmt->fetchColumn() ?: 1);

            $stmt = $db->prepare("
                SELECT ul.region_id, ul.last_explore_at, r.name, r.resource_type, r.exploration_encounters, r.hidden_dungeon_chance
                FROM user_location ul
                JOIN world_regions r ON r.id = ul.region_id
                WHERE ul.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmt = $db->query("
                SELECT wr.id, wr.name, wr.difficulty, wr.description, wr.min_realm_id, wr.resource_type, wr.exploration_encounters, wr.hidden_dungeon_chance,
                       r.name AS min_realm_name
                FROM world_regions wr
                LEFT JOIN realms r ON r.id = wr.min_realm_id
                ORDER BY wr.min_realm_id ASC, wr.difficulty ASC, wr.id ASC
            ");
            $regions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $cooldownRemaining = $this->getCooldownRemaining($userId);

            foreach ($regions as &$region) {
                $region['locked'] = $userRealmId < (int)$region['min_realm_id'];
                $region['is_current'] = $location && (int)$location['region_id'] === (int)$region['id'];
            }
            unset($region);

            return [
                'regions' => $regions,
                'user_realm_id' => $userRealmId,
                'current_location' => $location,
                'cooldown_remaining' => $cooldownRemaining,
            ];
        } catch (PDOException $e) {
            error_log('ExplorationService::getRegionsForUser ' . $e->getMessage());
            return [
                'regions' => [],
                'user_realm_id' => 1,
                'current_location' => null,
                'cooldown_remaining' => 0,
            ];
        }
    }

    public function getCooldownRemaining(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT last_explore_at FROM user_location WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $lastAt = $stmt->fetchColumn();
            if ($lastAt === false || $lastAt === null || $lastAt === '') {
                return 0;
            }
            $elapsed = time() - (int)strtotime((string)$lastAt);
            return max(0, self::EXPLORE_COOLDOWN_SECONDS - $elapsed);
        } catch (PDOException $e) {
            error_log('ExplorationService::getCooldownRemaining ' . $e->getMessage());
            return 0;
        }
    }

    public function exploreRegion(int $userId, int $regionId): array
    {
        $region = $this->getRegionById($regionId);
        if (!$region) {
            return ['success' => false, 'message' => 'Region not found.'];
        }

        $userRealmId = $this->getUserRealmId($userId);
        if ($userRealmId < (int)$region['min_realm_id']) {
            return ['success' => false, 'message' => 'Your realm is too low for this region.'];
        }

        $cooldownRemaining = $this->reserveExploration($userId, $regionId);
        if ($cooldownRemaining > 0) {
            return [
                'success' => false,
                'message' => 'You must wait before exploring again.',
                'cooldown_remaining' => $cooldownRemaining,
            ];
        }

        $dungeonChance = max(0.0, (float)($region['hidden_dungeon_chance'] ?? 1.0));
        $dungeonRoll = mt_rand(1, 10000) / 100.0;
        if ($dungeonRoll <= $dungeonChance) {
            return $this->handleDungeonDiscovery($userId, $region);
        }

        $roll = mt_rand(1, 100);
        if ($roll <= 40) {
            return $this->handleEncounter($userId, $region);
        }
        if ($roll <= 65) {
            return $this->handleHerbsFound($userId, $region);
        }
        if ($roll <= 85) {
            return $this->handleMaterialsFound($userId, $region);
        }
        if ($roll <= 95) {
            return [
                'success' => true,
                'event_type' => 'nothing',
                'message' => 'You searched the area but found nothing of value.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
            ];
        }
        return $this->handleRareDiscovery($userId, $region);
    }

    private function handleDungeonDiscovery(int $userId, array $region): array
    {
        $dungeon = $this->pickDungeonForRegion((int)$region['id']);
        if (!$dungeon) {
            return $this->handleRareDiscovery($userId, $region);
        }

        $locked = $this->getUserRealmId($userId) < (int)$dungeon['min_realm_id'];
        return [
            'success' => true,
            'event_type' => 'dungeon_discovery',
            'message' => 'A hidden dungeon entrance revealed itself!',
            'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
            'region_name' => (string)$region['name'],
            'resource_type' => (string)($region['resource_type'] ?? ''),
            'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
            'data' => [
                'dungeon' => [
                    'id' => (int)$dungeon['id'],
                    'name' => (string)$dungeon['name'],
                    'difficulty' => (int)$dungeon['difficulty'],
                    'boss_name' => (string)$dungeon['boss_name'],
                    'locked' => $locked,
                    'min_realm_id' => (int)$dungeon['min_realm_id'],
                    'min_realm_name' => (string)($dungeon['min_realm_name'] ?? 'Qi Refining'),
                ],
            ],
        ];
    }

    private function handleEncounter(int $userId, array $region): array
    {
        $npcId = $this->pickEncounterNpcId($userId, (int)$region['difficulty']);
        if ($npcId === null) {
            return [
                'success' => true,
                'event_type' => 'nothing',
                'message' => 'You sensed danger nearby, but nothing emerged.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
            ];
        }

        $battleService = new PvEBattleService();
        $result = $battleService->simulateBattle($userId, $npcId);
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['error'] ?? 'Exploration encounter failed.'];
        }

        $payload = $this->finalizeEncounter($userId, $result);
        return [
            'success' => true,
            'event_type' => 'encounter',
            'message' => $payload['winner'] === 'user'
                ? 'You encountered an enemy and won the battle.'
                : 'You encountered an enemy but were forced to retreat.',
            'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
            'region_name' => (string)$region['name'],
            'resource_type' => (string)($region['resource_type'] ?? ''),
            'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
            'data' => $payload,
        ];
    }

    private function handleHerbsFound(int $userId, array $region): array
    {
        $template = $this->pickRandomTemplateByType('herb');
        if (!$template) {
            return [
                'success' => true,
                'event_type' => 'nothing',
                'message' => 'You searched for herbs but found none.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
            ];
        }

        $itemService = new ItemService();
        $add = $itemService->addItemToInventory($userId, (int)$template['id'], 1);
        if (!$add['success']) {
            return ['success' => false, 'message' => $add['message'] ?? 'Could not add herb to inventory.'];
        }

        return [
            'success' => true,
            'event_type' => 'herbs',
            'message' => 'You gathered herbs while exploring.',
            'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
            'region_name' => (string)$region['name'],
            'resource_type' => (string)($region['resource_type'] ?? ''),
            'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
            'data' => [
                'item' => [
                    'id' => (int)$template['id'],
                    'name' => (string)$template['name'],
                    'type' => (string)$template['type'],
                    'quantity' => 1,
                ],
            ],
        ];
    }

    private function handleMaterialsFound(int $userId, array $region): array
    {
        $template = $this->pickMaterialTemplateForDifficulty((int)$region['difficulty']);
        if (!$template) {
            return [
                'success' => true,
                'event_type' => 'nothing',
                'message' => 'You inspected the ground but found no useful materials.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
            ];
        }

        $itemService = new ItemService();
        $add = $itemService->addItemToInventory($userId, (int)$template['id'], 1);
        if (!$add['success']) {
            return ['success' => false, 'message' => $add['message'] ?? 'Could not add material to inventory.'];
        }

        return [
            'success' => true,
            'event_type' => 'materials',
            'message' => 'You uncovered useful crafting materials.',
            'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
            'region_name' => (string)$region['name'],
            'resource_type' => (string)($region['resource_type'] ?? ''),
            'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
            'data' => [
                'item' => [
                    'id' => (int)$template['id'],
                    'name' => (string)$template['name'],
                    'type' => (string)$template['type'],
                    'quantity' => 1,
                ],
            ],
        ];
    }

    private function handleRareDiscovery(int $userId, array $region): array
    {
        $manualService = new CultivationManualService();
        $manual = $manualService->awardAncientRuinsManual($userId, $region);
        if ($manual !== null) {
            return [
                'success' => true,
                'event_type' => 'manual_discovery',
                'message' => 'Ancient ruins yielded a forgotten cultivation manual.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
                'resource_type' => (string)($region['resource_type'] ?? ''),
                'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
                'data' => [
                    'manual' => [
                        'id' => (int)$manual['id'],
                        'name' => (string)$manual['name'],
                        'rarity' => (string)$manual['rarity'],
                    ],
                ],
            ];
        }

        $template = $this->getTemplateById(self::RUNE_FRAGMENT_TEMPLATE_ID);
        $quantity = 1;
        if (!$template) {
            $template = $this->pickMaterialTemplateForDifficulty(max(3, (int)$region['difficulty']));
            $quantity = 2;
        }
        if (!$template) {
            return [
                'success' => true,
                'event_type' => 'nothing',
                'message' => 'You sensed a hidden treasure, but it slipped away.',
                'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
                'region_name' => (string)$region['name'],
            ];
        }

        $itemService = new ItemService();
        $add = $itemService->addItemToInventory($userId, (int)$template['id'], $quantity);
        if (!$add['success']) {
            return ['success' => false, 'message' => $add['message'] ?? 'Could not add rare discovery to inventory.'];
        }

        return [
            'success' => true,
            'event_type' => 'rare_discovery',
            'message' => 'Rare discovery! You found something uncommon.',
            'cooldown_remaining' => self::EXPLORE_COOLDOWN_SECONDS,
            'region_name' => (string)$region['name'],
            'resource_type' => (string)($region['resource_type'] ?? ''),
            'exploration_encounters' => (string)($region['exploration_encounters'] ?? ''),
            'data' => [
                'item' => [
                    'id' => (int)$template['id'],
                    'name' => (string)$template['name'],
                    'type' => (string)$template['type'],
                    'quantity' => $quantity,
                ],
            ],
        ];
    }

    private function finalizeEncounter(int $userId, array $result): array
    {
        $chiReward = (int)$result['chi_reward'];
        $userChiAfter = (int)$result['user_chi_after'];

        $statCalc = new StatCalculator();
        $finalStats = $statCalc->calculateFinalStats($userId);
        $userMaxChi = (int)$finalStats['final']['max_chi'];

        if ($result['winner'] === 'user' && $chiReward > 0) {
            $newChi = min($userMaxChi, max(0, $userChiAfter + $chiReward));
            $db = Database::getConnection();
            $db->prepare('UPDATE users SET chi = GREATEST(0, LEAST(?, ?)) WHERE id = ?')
                ->execute([$userMaxChi, $newChi, $userId]);
            $userChiAfter = $newChi;
        }

        $db = Database::getConnection();
        $db->prepare('UPDATE users SET active_scroll_type = NULL WHERE id = ?')->execute([$userId]);

        return [
            'winner' => $result['winner'],
            'battle_log' => $result['battle_log'],
            'user_chi_after' => $userChiAfter,
            'user_max_chi' => $userMaxChi,
            'npc_hp_max' => (int)$result['npc_hp_max'],
            'chi_reward' => $chiReward,
            'npc_name' => $result['npc_name'],
            'dropped_item' => $result['dropped_item'] ?? null,
            'herb_dropped' => $result['herb_dropped'] ?? null,
            'material_dropped' => $result['material_dropped'] ?? null,
            'rune_fragment_dropped' => $result['rune_fragment_dropped'] ?? null,
            'gold_gained' => (int)($result['gold_gained'] ?? 0),
            'spirit_stone_gained' => (int)($result['spirit_stone_gained'] ?? 0),
        ];
    }

    private function reserveExploration(int $userId, int $regionId): int
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT region_id, last_explore_at FROM user_location WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['last_explore_at'])) {
                $elapsed = time() - (int)strtotime((string)$row['last_explore_at']);
                $remaining = max(0, self::EXPLORE_COOLDOWN_SECONDS - $elapsed);
                if ($remaining > 0) {
                    $db->rollBack();
                    return $remaining;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO user_location (user_id, region_id, last_explore_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE region_id = VALUES(region_id), last_explore_at = VALUES(last_explore_at)
            ");
            $stmt->execute([$userId, $regionId]);

            $db->commit();
            return 0;
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ExplorationService::reserveExploration ' . $e->getMessage());
            return self::EXPLORE_COOLDOWN_SECONDS;
        }
    }

    private function getRegionById(int $regionId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, name, difficulty, description, min_realm_id, resource_type, exploration_encounters, hidden_dungeon_chance FROM world_regions WHERE id = ? LIMIT 1');
            $stmt->execute([$regionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('ExplorationService::getRegionById ' . $e->getMessage());
            return null;
        }
    }

    private function getUserRealmId(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT realm_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            return (int)($stmt->fetchColumn() ?: 1);
        } catch (PDOException $e) {
            error_log('ExplorationService::getUserRealmId ' . $e->getMessage());
            return 1;
        }
    }

    private function pickEncounterNpcId(int $userId, int $difficulty): ?int
    {
        try {
            $userRealmId = $this->getUserRealmId($userId);
            $targetLevel = max(1, $difficulty * 2);
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id
                FROM npcs
                WHERE realm_id <= ?
                ORDER BY ABS(level - ?) ASC, realm_id DESC, level ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$userRealmId, $targetLevel]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (PDOException $e) {
            error_log('ExplorationService::pickEncounterNpcId ' . $e->getMessage());
            return null;
        }
    }

    private function pickRandomTemplateByType(string $type): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, name, type FROM item_templates WHERE type = ? ORDER BY id ASC');
            $stmt->execute([$type]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                return null;
            }
            return $rows[array_rand($rows)];
        } catch (PDOException $e) {
            error_log('ExplorationService::pickRandomTemplateByType ' . $e->getMessage());
            return null;
        }
    }

    private function pickMaterialTemplateForDifficulty(int $difficulty): ?array
    {
        $tier = $difficulty >= 4 ? 3 : ($difficulty >= 3 ? 2 : 1);
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id, name, type
                FROM item_templates
                WHERE type = 'material' AND material_tier = ? AND id <> ?
                ORDER BY id ASC
            ");
            $stmt->execute([$tier, self::RUNE_FRAGMENT_TEMPLATE_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                $stmt = $db->prepare("
                    SELECT id, name, type
                    FROM item_templates
                    WHERE type = 'material' AND id <> ?
                    ORDER BY id ASC
                ");
                $stmt->execute([self::RUNE_FRAGMENT_TEMPLATE_ID]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            if ($rows === []) {
                return null;
            }
            return $rows[array_rand($rows)];
        } catch (PDOException $e) {
            error_log('ExplorationService::pickMaterialTemplateForDifficulty ' . $e->getMessage());
            return null;
        }
    }

    private function getTemplateById(int $templateId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id, name, type FROM item_templates WHERE id = ? LIMIT 1');
            $stmt->execute([$templateId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('ExplorationService::getTemplateById ' . $e->getMessage());
            return null;
        }
    }

    private function pickDungeonForRegion(int $regionId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT d.id, d.name, d.difficulty, d.min_realm_id, d.boss_name, r.name AS min_realm_name
                FROM dungeons d
                LEFT JOIN realms r ON r.id = d.min_realm_id
                WHERE d.region_id = ?
                ORDER BY d.difficulty ASC, d.id ASC
                LIMIT 1
            ");
            $stmt->execute([$regionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('ExplorationService::pickDungeonForRegion ' . $e->getMessage());
            return null;
        }
    }
}
