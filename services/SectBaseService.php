<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Sect bases: buildings, resident NPCs, and passive NPC bonus aggregation.
 */
class SectBaseService
{
    private const DISCIPLE_NPC_TARGET = 5;

    /** @var array<int, array<string, mixed>> */
    private const BUILDINGS = [
        [
            'key' => 'sect_hall',
            'name' => 'Sect Hall',
            'description' => 'The administrative heart of the sect where leaders receive petitions and issue commands.',
            'display_order' => 1,
            'bonus_summary' => 'Coordinates sect affairs and raises morale.',
        ],
        [
            'key' => 'training_grounds',
            'name' => 'Training Grounds',
            'description' => 'Courtyards and dueling rings where disciples temper their bodies and techniques.',
            'display_order' => 2,
            'bonus_summary' => 'Supports disciplined daily cultivation.',
        ],
        [
            'key' => 'alchemy_pavilion',
            'name' => 'Alchemy Pavilion',
            'description' => 'Refiners and flame arrays assist the sect\'s pill makers and medicine keepers.',
            'display_order' => 3,
            'bonus_summary' => 'Improves alchemical atmosphere and resource handling.',
        ],
        [
            'key' => 'forge_pavilion',
            'name' => 'Forge Pavilion',
            'description' => 'A forge lined with spirit furnaces for shaping weapons, armor, and artifacts.',
            'display_order' => 4,
            'bonus_summary' => 'Keeps sect equipment in battle-ready condition.',
        ],
        [
            'key' => 'library_pavilion',
            'name' => 'Library Pavilion',
            'description' => 'Shelves of manuals and copied techniques preserve the sect\'s teachings.',
            'display_order' => 5,
            'bonus_summary' => 'Improves study, insight, and tactical memory.',
        ],
        [
            'key' => 'inner_garden',
            'name' => 'Inner Garden',
            'description' => 'Quiet gardens, herb beds, and meditation stones nurture recovery and spiritual balance.',
            'display_order' => 6,
            'bonus_summary' => 'Provides serenity and spiritual nourishment.',
        ],
        [
            'key' => 'war_room',
            'name' => 'War Room',
            'description' => 'Map tables, scouts\' reports, and formation boards guide territorial strategy.',
            'display_order' => 7,
            'bonus_summary' => 'Improves readiness for sect conflicts.',
        ],
    ];

    /** @var array<int, array<string, mixed>> */
    private const NPCS = [
        [
            'key' => 'elder_qinghe',
            'name' => 'Elder Qinghe',
            'role' => 'elder',
            'rank' => 'elder',
            'title' => 'Caretaker of Breathing Forms',
            'bonus_type' => 'cultivation_speed',
            'bonus_value' => 0.0100,
            'sort_order' => 1,
        ],
        [
            'key' => 'elder_mingshi',
            'name' => 'Elder Mingshi',
            'role' => 'elder',
            'rank' => 'elder',
            'title' => 'Treasurer of Outer Affairs',
            'bonus_type' => 'gold_gain',
            'bonus_value' => 0.0100,
            'sort_order' => 2,
        ],
        [
            'key' => 'elder_yanru',
            'name' => 'Elder Yanru',
            'role' => 'elder',
            'rank' => 'elder',
            'title' => 'Keeper of Breakthrough Records',
            'bonus_type' => 'breakthrough',
            'bonus_value' => 0.0100,
            'sort_order' => 3,
        ],
        [
            'key' => 'disciple_1',
            'name' => 'Disciple Lan',
            'role' => 'disciple',
            'rank' => 'core_disciple',
            'title' => 'Garden Attendant',
            'bonus_type' => 'cultivation_speed',
            'bonus_value' => 0.0010,
            'sort_order' => 11,
        ],
        [
            'key' => 'disciple_2',
            'name' => 'Disciple Bo',
            'role' => 'disciple',
            'rank' => 'inner_disciple',
            'title' => 'Library Scribe',
            'bonus_type' => 'cultivation_speed',
            'bonus_value' => 0.0010,
            'sort_order' => 12,
        ],
        [
            'key' => 'disciple_3',
            'name' => 'Disciple Rui',
            'role' => 'disciple',
            'rank' => 'outer_disciple',
            'title' => 'Pavilion Assistant',
            'bonus_type' => 'gold_gain',
            'bonus_value' => 0.0010,
            'sort_order' => 13,
        ],
        [
            'key' => 'disciple_4',
            'name' => 'Disciple Fen',
            'role' => 'disciple',
            'rank' => 'outer_disciple',
            'title' => 'Forge Runner',
            'bonus_type' => 'gold_gain',
            'bonus_value' => 0.0010,
            'sort_order' => 14,
        ],
        [
            'key' => 'disciple_5',
            'name' => 'Disciple Tao',
            'role' => 'disciple',
            'rank' => 'outer_disciple',
            'title' => 'Messenger',
            'bonus_type' => 'breakthrough',
            'bonus_value' => 0.0010,
            'sort_order' => 15,
        ],
    ];

    public function initializeSectBase(int $sectId, string $sectName, ?PDO $db = null): void
    {
        $closeTransaction = false;
        try {
            $db = $db ?? Database::getConnection();
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $closeTransaction = true;
            }

            $baseId = $this->ensureBaseRow($db, $sectId, $sectName);
            $this->ensureBuildings($db, $baseId);
            $this->ensureNpcs($db, $baseId);
            $this->synchronizeNpcPopulation($sectId, $db);

            if ($closeTransaction) {
                $db->commit();
            }
        } catch (PDOException $e) {
            if ($closeTransaction && isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectBaseService::initializeSectBase " . $e->getMessage());
        }
    }

    public function synchronizeNpcPopulation(int $sectId, ?PDO $db = null): void
    {
        try {
            $db = $db ?? Database::getConnection();
            $base = $this->getBaseRowBySectId($db, $sectId);
            if (!$base) {
                $sectName = $this->getSectName($db, $sectId);
                if ($sectName === null) {
                    return;
                }
                $this->initializeSectBase($sectId, $sectName, $db);
                $base = $this->getBaseRowBySectId($db, $sectId);
                if (!$base) {
                    return;
                }
            }

            $stmt = $db->prepare("SELECT COUNT(*) FROM sect_members WHERE sect_id = ?");
            $stmt->execute([$sectId]);
            $realMembers = (int)$stmt->fetchColumn();
            $extraPlayers = max(0, $realMembers - 1);
            $activeDisciples = max(0, self::DISCIPLE_NPC_TARGET - $extraPlayers);

            $db->prepare("UPDATE sect_npcs SET is_active = 0 WHERE base_id = ? AND npc_role = 'disciple'")
                ->execute([(int)$base['id']]);

            if ($activeDisciples > 0) {
                $stmt = $db->prepare("
                    SELECT id
                    FROM sect_npcs
                    WHERE base_id = ? AND npc_role = 'disciple'
                    ORDER BY sort_order ASC
                    LIMIT {$activeDisciples}
                ");
                $stmt->execute([(int)$base['id']]);
                $npcIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if ($npcIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($npcIds), '?'));
                    $params = array_merge([(int)$base['id']], array_map('intval', $npcIds));
                    $db->prepare("UPDATE sect_npcs SET is_active = 1 WHERE base_id = ? AND id IN ({$placeholders})")
                        ->execute($params);
                }
            }
        } catch (PDOException $e) {
            error_log("SectBaseService::synchronizeNpcPopulation " . $e->getMessage());
        }
    }

    public function getPassiveBonusesForSect(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $sectName = $this->getSectName($db, $sectId);
            if ($sectName === null) {
                return ['cultivation_speed' => 0.0, 'gold_gain' => 0.0, 'breakthrough' => 0.0];
            }

            $this->initializeSectBase($sectId, $sectName, $db);
            $stmt = $db->prepare("
                SELECT bonus_type, SUM(bonus_value) AS total_bonus
                FROM sect_npcs n
                JOIN sect_bases b ON b.id = n.base_id
                WHERE b.sect_id = ? AND n.is_active = 1 AND n.bonus_type IS NOT NULL
                GROUP BY bonus_type
            ");
            $stmt->execute([$sectId]);
            $bonuses = ['cultivation_speed' => 0.0, 'gold_gain' => 0.0, 'breakthrough' => 0.0];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = (string)($row['bonus_type'] ?? '');
                if (isset($bonuses[$key])) {
                    $bonuses[$key] = (float)$row['total_bonus'];
                }
            }
            return $bonuses;
        } catch (PDOException $e) {
            error_log("SectBaseService::getPassiveBonusesForSect " . $e->getMessage());
            return ['cultivation_speed' => 0.0, 'gold_gain' => 0.0, 'breakthrough' => 0.0];
        }
    }

    public function getBaseForSect(int $sectId): ?array
    {
        try {
            $db = Database::getConnection();
            $sectName = $this->getSectName($db, $sectId);
            if ($sectName === null) {
                return null;
            }

            $this->initializeSectBase($sectId, $sectName, $db);
            $base = $this->getBaseRowBySectId($db, $sectId);
            if (!$base) {
                return null;
            }

            $stmt = $db->prepare("
                SELECT id, building_key, building_name, level, description, display_order, bonus_summary
                FROM sect_buildings
                WHERE base_id = ?
                ORDER BY display_order ASC, id ASC
            ");
            $stmt->execute([(int)$base['id']]);
            $buildings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $db->prepare("
                SELECT id, npc_name, npc_role, npc_rank, title, bonus_type, bonus_value, is_active, sort_order
                FROM sect_npcs
                WHERE base_id = ?
                ORDER BY FIELD(npc_rank, 'elder', 'core_disciple', 'inner_disciple', 'outer_disciple'),
                         sort_order ASC, id ASC
            ");
            $stmt->execute([(int)$base['id']]);
            $npcs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $activeNpcs = array_values(array_filter($npcs, static fn(array $npc): bool => (int)($npc['is_active'] ?? 0) === 1));
            $activeDiscipleCount = count(array_filter($activeNpcs, static fn(array $npc): bool => (string)$npc['npc_role'] === 'disciple'));

            $members = $this->getMembers($db, $sectId);
            $npcBonuses = $this->getPassiveBonusesForSect($sectId);

            return [
                'base' => [
                    'id' => (int)$base['id'],
                    'sect_id' => (int)$base['sect_id'],
                    'base_name' => (string)$base['base_name'],
                    'created_at' => (string)$base['created_at'],
                ],
                'buildings' => $buildings,
                'members' => $members,
                'npcs' => $activeNpcs,
                'npc_bonuses' => $npcBonuses,
                'npc_disciple_capacity' => self::DISCIPLE_NPC_TARGET,
                'active_disciple_npc_count' => $activeDiscipleCount,
                'real_member_count' => count($members),
                'real_joiners_replacing_npcs' => max(0, count($members) - 1),
            ];
        } catch (PDOException $e) {
            error_log("SectBaseService::getBaseForSect " . $e->getMessage());
            return null;
        }
    }

    private function ensureBaseRow(PDO $db, int $sectId, string $sectName): int
    {
        $base = $this->getBaseRowBySectId($db, $sectId);
        if ($base) {
            return (int)$base['id'];
        }

        $db->prepare("INSERT INTO sect_bases (sect_id, base_name, created_at) VALUES (?, ?, NOW())")
            ->execute([$sectId, $sectName . ' Base']);
        return (int)$db->lastInsertId();
    }

    private function ensureBuildings(PDO $db, int $baseId): void
    {
        $stmt = $db->prepare("SELECT building_key FROM sect_buildings WHERE base_id = ?");
        $stmt->execute([$baseId]);
        $existing = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        $stmt = $db->prepare("
            INSERT INTO sect_buildings (base_id, building_key, building_name, level, description, display_order, bonus_summary)
            VALUES (?, ?, ?, 1, ?, ?, ?)
        ");

        foreach (self::BUILDINGS as $building) {
            if (isset($existing[$building['key']])) {
                continue;
            }
            $stmt->execute([
                $baseId,
                $building['key'],
                $building['name'],
                $building['description'],
                $building['display_order'],
                $building['bonus_summary'],
            ]);
        }
    }

    private function ensureNpcs(PDO $db, int $baseId): void
    {
        $stmt = $db->prepare("SELECT npc_key FROM sect_npcs WHERE base_id = ?");
        $stmt->execute([$baseId]);
        $existing = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        $stmt = $db->prepare("
            INSERT INTO sect_npcs (base_id, npc_key, npc_name, npc_role, npc_rank, title, bonus_type, bonus_value, is_active, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ");

        foreach (self::NPCS as $npc) {
            if (isset($existing[$npc['key']])) {
                continue;
            }
            $stmt->execute([
                $baseId,
                $npc['key'],
                $npc['name'],
                $npc['role'],
                $npc['rank'],
                $npc['title'],
                $npc['bonus_type'],
                $npc['bonus_value'],
                $npc['sort_order'],
            ]);
        }
    }

    private function getBaseRowBySectId(PDO $db, int $sectId): ?array
    {
        $stmt = $db->prepare("SELECT id, sect_id, base_name, created_at FROM sect_bases WHERE sect_id = ? LIMIT 1");
        $stmt->execute([$sectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getSectName(PDO $db, int $sectId): ?string
    {
        $stmt = $db->prepare("SELECT name FROM sects WHERE id = ? LIMIT 1");
        $stmt->execute([$sectId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string)$name : null;
    }

    private function getMembers(PDO $db, int $sectId): array
    {
        $stmt = $db->prepare("
            SELECT m.id, m.user_id, m.rank, m.rank AS role, m.joined_at, COALESCE(m.contribution, 0) AS contribution, u.username
            FROM sect_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.sect_id = ?
            ORDER BY FIELD(m.rank, 'leader', 'elder', 'core_disciple', 'inner_disciple', 'outer_disciple'),
                     contribution DESC, m.joined_at ASC
        ");
        $stmt->execute([$sectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
