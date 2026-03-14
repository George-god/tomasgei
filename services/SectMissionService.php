<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/SectService.php';
require_once __DIR__ . '/RewardService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * NPC sect missions assigned to resident NPC disciples.
 */
class SectMissionService
{
    private const MISSION_DEFINITIONS = [
        'herb_gathering' => [
            'label' => 'Herb Gathering',
            'duration_minutes' => 20,
            'base_success_chance' => 72.0,
            'gold_min' => 40,
            'gold_max' => 80,
            'spirit_min' => 0,
            'spirit_max' => 1,
            'item_type' => 'herb',
            'item_qty_min' => 2,
            'item_qty_max' => 4,
        ],
        'ore_mining' => [
            'label' => 'Ore Mining',
            'duration_minutes' => 25,
            'base_success_chance' => 68.0,
            'gold_min' => 60,
            'gold_max' => 100,
            'spirit_min' => 0,
            'spirit_max' => 1,
            'item_type' => 'material',
            'item_qty_min' => 2,
            'item_qty_max' => 4,
        ],
        'beast_hunt' => [
            'label' => 'Beast Hunt',
            'duration_minutes' => 35,
            'base_success_chance' => 62.0,
            'gold_min' => 120,
            'gold_max' => 180,
            'spirit_min' => 1,
            'spirit_max' => 2,
            'item_type' => null,
            'item_qty_min' => 0,
            'item_qty_max' => 0,
        ],
        'scout_territory' => [
            'label' => 'Scout Territory',
            'duration_minutes' => 30,
            'base_success_chance' => 70.0,
            'gold_min' => 80,
            'gold_max' => 140,
            'spirit_min' => 1,
            'spirit_max' => 1,
            'item_type' => null,
            'item_qty_min' => 0,
            'item_qty_max' => 0,
        ],
        'treasure_hunt' => [
            'label' => 'Treasure Hunt',
            'duration_minutes' => 45,
            'base_success_chance' => 55.0,
            'gold_min' => 150,
            'gold_max' => 260,
            'spirit_min' => 2,
            'spirit_max' => 4,
            'item_type' => 'material',
            'item_qty_min' => 1,
            'item_qty_max' => 2,
        ],
    ];

    private const NPC_RANK_SUCCESS_BONUS = [
        'elder' => 15.0,
        'core_disciple' => 12.0,
        'inner_disciple' => 7.0,
        'outer_disciple' => 3.0,
    ];

    public function getMissionDefinitions(): array
    {
        return self::MISSION_DEFINITIONS;
    }

    public function getMissionPageData(int $userId): array
    {
        $this->finalizeDueMissions();

        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return ['sect' => null, 'available_npcs' => [], 'active_missions' => [], 'ready_missions' => [], 'mission_definitions' => self::MISSION_DEFINITIONS];
        }

        $sectId = (int)$sect['id'];
        try {
            $db = Database::getConnection();
            return [
                'sect' => $sect,
                'available_npcs' => $this->getAvailableMissionNpcs($db, $sectId),
                'active_missions' => $this->getMissionsByStatuses($db, $sectId, ['active']),
                'ready_missions' => $this->getMissionsByStatuses($db, $sectId, ['completed', 'failed']),
                'mission_definitions' => self::MISSION_DEFINITIONS,
            ];
        } catch (PDOException $e) {
            error_log("SectMissionService::getMissionPageData " . $e->getMessage());
            return ['sect' => $sect, 'available_npcs' => [], 'active_missions' => [], 'ready_missions' => [], 'mission_definitions' => self::MISSION_DEFINITIONS];
        }
    }

    public function assignMission(int $userId, int $npcId, string $missionType): array
    {
        $this->finalizeDueMissions();
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return ['success' => false, 'message' => 'You must be in a sect to assign missions.'];
        }
        $definition = self::MISSION_DEFINITIONS[$missionType] ?? null;
        if ($definition === null) {
            return ['success' => false, 'message' => 'Invalid mission type.'];
        }

        $sectId = (int)$sect['id'];

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $npc = $this->getMissionNpcForSect($db, $sectId, $npcId, true);
            if (!$npc) {
                $db->rollBack();
                return ['success' => false, 'message' => 'NPC disciple not available for missions.'];
            }

            if ($this->npcHasActiveMission($db, $npcId)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'This NPC is already on a mission.'];
            }

            $successChance = min(95.0, (float)$definition['base_success_chance'] + $this->getNpcRankBonus((string)$npc['npc_rank']));
            $reward = $this->rollMissionReward($db, $definition);

            $startTime = date('Y-m-d H:i:s');
            $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' + ' . (int)$definition['duration_minutes'] . ' minutes'));

            $db->prepare("
                INSERT INTO sect_missions (
                    sect_id, npc_id, assigned_by_user_id, mission_type, status, start_time, end_time,
                    success_chance, reward_gold, reward_spirit_stones, reward_item_template_id, reward_quantity, created_at
                )
                VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $sectId,
                $npcId,
                $userId,
                $missionType,
                $startTime,
                $endTime,
                $successChance,
                $reward['gold'],
                $reward['spirit_stones'],
                $reward['item_template_id'],
                $reward['quantity'],
            ]);

            $db->commit();

            return ['success' => true, 'message' => $npc['npc_name'] . ' departed on ' . $definition['label'] . '.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectMissionService::assignMission " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not assign mission.'];
        }
    }

    public function collectMission(int $userId, int $missionId): array
    {
        $this->finalizeDueMissions();
        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return ['success' => false, 'message' => 'You must be in a sect to collect mission rewards.'];
        }

        $sectId = (int)$sect['id'];
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT m.*, n.npc_name, n.npc_rank
                FROM sect_missions m
                JOIN sect_npcs n ON n.id = m.npc_id
                WHERE m.id = ? AND m.sect_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$missionId, $sectId]);
            $mission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mission) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Mission not found.'];
            }

            $status = (string)$mission['status'];
            if ($status === 'active') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Mission is still in progress.'];
            }
            if ($status === 'claimed') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Mission rewards already collected.'];
            }

            if ($status === 'completed') {
                (new RewardService())->grantCurrency($db, $userId, (int)$mission['reward_gold'], (int)$mission['reward_spirit_stones']);
                if (!empty($mission['reward_item_template_id']) && (int)$mission['reward_quantity'] > 0) {
                    $this->grantItemReward($db, $userId, (int)$mission['reward_item_template_id'], (int)$mission['reward_quantity']);
                }
            }

            $db->prepare("UPDATE sect_missions SET status = 'claimed', collected_at = NOW() WHERE id = ?")->execute([$missionId]);
            $db->commit();

            return ['success' => true, 'message' => (string)($mission['result_message'] ?? 'Mission resolved.')];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectMissionService::collectMission " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not collect mission reward.'];
        }
    }

    public function finalizeDueMissions(): void
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("
                SELECT m.id, m.mission_type, m.success_chance, m.reward_gold, m.reward_spirit_stones, m.reward_item_template_id, m.reward_quantity,
                       n.npc_name
                FROM sect_missions m
                JOIN sect_npcs n ON n.id = m.npc_id
                WHERE m.status = 'active' AND m.end_time <= ?
            ");
            $stmt->execute([$now]);
            $missions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($missions as $mission) {
                $db->beginTransaction();
                $lock = $db->prepare("SELECT id, status FROM sect_missions WHERE id = ? LIMIT 1 FOR UPDATE");
                $lock->execute([(int)$mission['id']]);
                $locked = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$locked || (string)$locked['status'] !== 'active') {
                    $db->rollBack();
                    continue;
                }

                $success = (mt_rand(1, 10000) / 100.0) <= (float)$mission['success_chance'];
                $message = $success
                    ? $this->buildSuccessMessage($mission)
                    : (string)$mission['npc_name'] . ' returned empty-handed from the mission.';

                $db->prepare("
                    UPDATE sect_missions
                    SET status = ?, success_result = ?, result_message = ?
                    WHERE id = ?
                ")->execute([
                    $success ? 'completed' : 'failed',
                    $success ? 1 : 0,
                    $message,
                    (int)$mission['id'],
                ]);

                $db->commit();
            }
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectMissionService::finalizeDueMissions " . $e->getMessage());
        }
    }

    private function getAvailableMissionNpcs(PDO $db, int $sectId): array
    {
        $stmt = $db->prepare("
            SELECT n.id, n.npc_name, n.npc_rank, n.title
            FROM sect_npcs n
            JOIN sect_bases b ON b.id = n.base_id
            WHERE b.sect_id = ? AND n.npc_role = 'disciple' AND n.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM sect_missions m
                  WHERE m.npc_id = n.id AND m.status = 'active'
              )
            ORDER BY FIELD(n.npc_rank, 'core_disciple', 'inner_disciple', 'outer_disciple'), n.sort_order ASC, n.id ASC
        ");
        $stmt->execute([$sectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, string> $statuses
     */
    private function getMissionsByStatuses(PDO $db, int $sectId, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "
            SELECT m.id, m.mission_type, m.status, m.start_time, m.end_time, m.success_chance, m.success_result,
                   m.reward_gold, m.reward_spirit_stones, m.reward_item_template_id, m.reward_quantity, m.result_message,
                   n.npc_name, n.npc_rank, t.name AS reward_item_name
            FROM sect_missions m
            JOIN sect_npcs n ON n.id = m.npc_id
            LEFT JOIN item_templates t ON t.id = m.reward_item_template_id
            WHERE m.sect_id = ? AND m.status IN ({$placeholders})
            ORDER BY m.end_time ASC, m.id DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$sectId], $statuses));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getMissionNpcForSect(PDO $db, int $sectId, int $npcId, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT n.id, n.npc_name, n.npc_rank, n.npc_role, n.is_active
            FROM sect_npcs n
            JOIN sect_bases b ON b.id = n.base_id
            WHERE b.sect_id = ? AND n.id = ? AND n.npc_role = 'disciple' AND n.is_active = 1
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$sectId, $npcId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function npcHasActiveMission(PDO $db, int $npcId): bool
    {
        $stmt = $db->prepare("SELECT id FROM sect_missions WHERE npc_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$npcId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{gold:int,spirit_stones:int,item_template_id:?int,quantity:int}
     */
    private function rollMissionReward(PDO $db, array $definition): array
    {
        $itemTemplateId = null;
        $quantity = 0;
        if (!empty($definition['item_type'])) {
            $item = $this->pickRandomTemplateByType($db, (string)$definition['item_type']);
            if ($item !== null) {
                $itemTemplateId = (int)$item['id'];
                $quantity = mt_rand((int)$definition['item_qty_min'], (int)$definition['item_qty_max']);
            }
        }

        return [
            'gold' => mt_rand((int)$definition['gold_min'], (int)$definition['gold_max']),
            'spirit_stones' => mt_rand((int)$definition['spirit_min'], (int)$definition['spirit_max']),
            'item_template_id' => $itemTemplateId,
            'quantity' => $quantity,
        ];
    }

    private function pickRandomTemplateByType(PDO $db, string $type): ?array
    {
        $stmt = $db->prepare("SELECT id, name, type FROM item_templates WHERE type = ? ORDER BY id ASC");
        $stmt->execute([$type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }
        return $rows[array_rand($rows)];
    }

    private function buildSuccessMessage(array $mission): string
    {
        $parts = [(string)$mission['npc_name'] . ' completed the mission successfully.'];
        if ((int)$mission['reward_gold'] > 0) {
            $parts[] = '+' . (int)$mission['reward_gold'] . ' gold';
        }
        if ((int)$mission['reward_spirit_stones'] > 0) {
            $parts[] = '+' . (int)$mission['reward_spirit_stones'] . ' spirit stones';
        }
        if (!empty($mission['reward_item_template_id']) && (int)$mission['reward_quantity'] > 0) {
            $parts[] = 'item reward ready';
        }
        return implode(' ', $parts);
    }

    private function getNpcRankBonus(string $npcRank): float
    {
        return self::NPC_RANK_SUCCESS_BONUS[$npcRank] ?? 0.0;
    }

    private function grantItemReward(PDO $db, int $userId, int $itemTemplateId, int $quantity): void
    {
        $stmt = $db->prepare("SELECT id FROM inventory WHERE user_id = ? AND item_template_id = ? AND is_equipped = 0 LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId, $itemTemplateId]);
        $inventoryId = $stmt->fetchColumn();
        if ($inventoryId !== false) {
            $db->prepare("UPDATE inventory SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?")
                ->execute([$quantity, (int)$inventoryId]);
            return;
        }

        $db->prepare("
            INSERT INTO inventory (user_id, item_template_id, quantity, is_equipped, created_at, updated_at)
            VALUES (?, ?, ?, 0, NOW(), NOW())
        ")->execute([$userId, $itemTemplateId, $quantity]);
    }
}
