<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/CultivationManualService.php';
require_once __DIR__ . '/DaoRecord.php';
require_once __DIR__ . '/PvEBattleService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Hidden dungeon service. 3 stages per run: normal, elite, boss.
 */
class DungeonService
{
    private const DAILY_RUN_LIMIT = 3;

    public function getDungeonsForUser(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $userRealmId = $this->getUserRealmId($userId);
            $activeRuns = $this->getActiveRunsByDungeon($userId);

            $stmt = $db->query("
                SELECT d.id, d.name, d.region_id, d.difficulty, d.min_realm_id, d.boss_name,
                       r.name AS region_name, rl.name AS min_realm_name
                FROM dungeons d
                JOIN world_regions r ON r.id = d.region_id
                LEFT JOIN realms rl ON rl.id = d.min_realm_id
                ORDER BY d.min_realm_id ASC, d.difficulty ASC, d.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$row) {
                $row['locked'] = $userRealmId < (int)$row['min_realm_id'];
                $row['active_run'] = $activeRuns[(int)$row['id']] ?? null;
            }
            unset($row);

            return [
                'dungeons' => $rows,
                'daily_runs_remaining' => $this->getDailyRunsRemaining($userId),
                'user_realm_id' => $userRealmId,
            ];
        } catch (PDOException $e) {
            error_log('DungeonService::getDungeonsForUser ' . $e->getMessage());
            return ['dungeons' => [], 'daily_runs_remaining' => 0, 'user_realm_id' => 1];
        }
    }

    public function getDungeonById(int $dungeonId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT d.*, r.name AS region_name, r.description AS region_description, rl.name AS min_realm_name
                FROM dungeons d
                JOIN world_regions r ON r.id = d.region_id
                LEFT JOIN realms rl ON rl.id = d.min_realm_id
                WHERE d.id = ?
                LIMIT 1
            ");
            $stmt->execute([$dungeonId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('DungeonService::getDungeonById ' . $e->getMessage());
            return null;
        }
    }

    public function getActiveRunForUser(int $userId, int $dungeonId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id, user_id, dungeon_id, progress, is_completed, started_at
                FROM dungeon_runs
                WHERE user_id = ? AND dungeon_id = ? AND is_completed = 0
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $dungeonId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('DungeonService::getActiveRunForUser ' . $e->getMessage());
            return null;
        }
    }

    public function getDailyRunsRemaining(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM dungeon_runs
                WHERE user_id = ? AND DATE(started_at) = CURDATE()
            ");
            $stmt->execute([$userId]);
            $count = (int)$stmt->fetchColumn();
            return max(0, self::DAILY_RUN_LIMIT - $count);
        } catch (PDOException $e) {
            error_log('DungeonService::getDailyRunsRemaining ' . $e->getMessage());
            return 0;
        }
    }

    public function startRun(int $userId, int $dungeonId): array
    {
        $dungeon = $this->getDungeonById($dungeonId);
        if (!$dungeon) {
            return ['success' => false, 'message' => 'Dungeon not found.'];
        }
        if ($this->getUserRealmId($userId) < (int)$dungeon['min_realm_id']) {
            return ['success' => false, 'message' => 'Your realm is too low for this dungeon.'];
        }
        $existing = $this->getActiveRunForUser($userId, $dungeonId);
        if ($existing) {
            return ['success' => true, 'message' => 'Continuing existing run.', 'run_id' => (int)$existing['id']];
        }
        if ($this->getDailyRunsRemaining($userId) < 1) {
            return ['success' => false, 'message' => 'You have used all 3 dungeon runs for today.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO dungeon_runs (user_id, dungeon_id, progress, is_completed, started_at)
                VALUES (?, ?, 0, 0, NOW())
            ");
            $stmt->execute([$userId, $dungeonId]);
            $runId = (int)$db->lastInsertId();
            DaoRecord::log(
                'dungeon_run',
                $userId,
                $runId,
                'You entered the dungeon ' . (string)$dungeon['name'] . '.',
                [
                    'dungeon_id' => $dungeonId,
                    'difficulty' => (int)$dungeon['difficulty'],
                ],
                $db
            );
            return ['success' => true, 'message' => 'Dungeon run started.', 'run_id' => $runId];
        } catch (PDOException $e) {
            error_log('DungeonService::startRun ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not start dungeon run.'];
        }
    }

    public function advanceRun(int $userId, int $runId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT dr.id, dr.user_id, dr.dungeon_id, dr.progress, dr.is_completed, d.name, d.difficulty,
                       d.min_realm_id, d.boss_name, d.boss_hp, d.boss_attack, d.boss_defense
                FROM dungeon_runs dr
                JOIN dungeons d ON d.id = dr.dungeon_id
                WHERE dr.id = ? AND dr.user_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$runId, $userId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Dungeon run not found.'];
            }
            if ((int)$run['is_completed'] === 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'This dungeon run is already completed.'];
            }

            $stage = $this->getStageDefinition($run);
            if ($stage === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Dungeon stages already cleared.'];
            }

            $battleService = new PvEBattleService();
            $result = $battleService->simulateCustomBattle(
                $userId,
                $stage['enemy_name'],
                $stage['hp'],
                $stage['attack'],
                $stage['defense'],
                $stage['reward_chi']
            );
            if (!$result['success']) {
                $db->rollBack();
                return ['success' => false, 'message' => $result['error'] ?? 'Dungeon battle failed.'];
            }

            $payload = $this->finalizeBattle($userId, $result);
            $won = $payload['winner'] === 'user';

            if ($won) {
                $newProgress = (int)$run['progress'] + 1;
                $isBossStage = $stage['stage'] === 3;
                if ($isBossStage) {
                    $rewards = $this->grantBossRewards($db, $userId, (int)$run['difficulty']);
                    $db->prepare('UPDATE dungeon_runs SET progress = 3, is_completed = 1 WHERE id = ?')->execute([$runId]);
                    DaoRecord::log(
                        'dungeon_run',
                        $userId,
                        $runId,
                        'You completed the dungeon ' . (string)$run['name'] . '.',
                        [
                            'stage' => $stage['stage'],
                            'stage_name' => $stage['label'],
                            'difficulty' => (int)$run['difficulty'],
                            'gold_reward' => $rewards['gold'] ?? 0,
                            'spirit_stone_reward' => $rewards['spirit_stones'] ?? 0,
                            'manual_id' => $rewards['manual']['id'] ?? null,
                        ],
                        $db
                    );
                    $db->commit();
                    return [
                        'success' => true,
                        'message' => 'Dungeon completed! Boss defeated and rewards granted.',
                        'battle' => $payload,
                        'stage_name' => $stage['label'],
                        'completed' => true,
                        'rewards' => $rewards,
                    ];
                }

                $db->prepare('UPDATE dungeon_runs SET progress = ? WHERE id = ?')->execute([$newProgress, $runId]);
                DaoRecord::log(
                    'dungeon_run',
                    $userId,
                    $runId,
                    'You cleared a dungeon stage in ' . (string)$run['name'] . '.',
                    [
                        'stage' => $stage['stage'],
                        'stage_name' => $stage['label'],
                        'next_progress' => $newProgress,
                    ],
                    $db
                );
                $db->commit();
                return [
                    'success' => true,
                    'message' => 'Stage cleared.',
                    'battle' => $payload,
                    'stage_name' => $stage['label'],
                    'completed' => false,
                    'next_stage' => $newProgress + 1,
                ];
            }

            DaoRecord::log(
                'dungeon_run',
                $userId,
                $runId,
                'You were defeated during a dungeon run in ' . (string)$run['name'] . '.',
                [
                    'stage' => $stage['stage'],
                    'stage_name' => $stage['label'],
                ],
                $db
            );
            $db->commit();
            return [
                'success' => true,
                'message' => 'You were defeated. Recover and try this stage again.',
                'battle' => $payload,
                'stage_name' => $stage['label'],
                'completed' => false,
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DungeonService::advanceRun ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not advance dungeon run.'];
        }
    }

    public function getStagePreview(array $runOrDungeon): array
    {
        $stage = $this->getStageDefinition($runOrDungeon);
        return $stage ?? [];
    }

    private function getActiveRunsByDungeon(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id, dungeon_id, progress, started_at
                FROM dungeon_runs
                WHERE user_id = ? AND is_completed = 0
                ORDER BY id DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $map = [];
            foreach ($rows as $row) {
                $dungeonId = (int)$row['dungeon_id'];
                if (!isset($map[$dungeonId])) {
                    $map[$dungeonId] = $row;
                }
            }
            return $map;
        } catch (PDOException $e) {
            error_log('DungeonService::getActiveRunsByDungeon ' . $e->getMessage());
            return [];
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
            error_log('DungeonService::getUserRealmId ' . $e->getMessage());
            return 1;
        }
    }

    private function getStageDefinition(array $run): ?array
    {
        $progress = (int)($run['progress'] ?? 0);
        $difficulty = max(1, (int)($run['difficulty'] ?? 1));
        $bossName = (string)($run['boss_name'] ?? 'Boss');
        $bossHp = max(1, (int)($run['boss_hp'] ?? 100));
        $bossAttack = max(1, (int)($run['boss_attack'] ?? 10));
        $bossDefense = max(0, (int)($run['boss_defense'] ?? 10));

        if ($progress === 0) {
            return [
                'stage' => 1,
                'label' => 'Stage 1: Normal Enemy',
                'enemy_name' => 'Dungeon Raider',
                'hp' => (int)round($bossHp * 0.45),
                'attack' => (int)round($bossAttack * 0.55),
                'defense' => (int)round($bossDefense * 0.55),
                'reward_chi' => 10 * $difficulty,
            ];
        }
        if ($progress === 1) {
            return [
                'stage' => 2,
                'label' => 'Stage 2: Elite Enemy',
                'enemy_name' => 'Elite ' . $bossName,
                'hp' => (int)round($bossHp * 0.75),
                'attack' => (int)round($bossAttack * 0.80),
                'defense' => (int)round($bossDefense * 0.80),
                'reward_chi' => 15 * $difficulty,
            ];
        }
        if ($progress === 2) {
            return [
                'stage' => 3,
                'label' => 'Stage 3: Boss',
                'enemy_name' => $bossName,
                'hp' => $bossHp,
                'attack' => $bossAttack,
                'defense' => $bossDefense,
                'reward_chi' => 25 * $difficulty,
            ];
        }
        return null;
    }

    private function finalizeBattle(int $userId, array $result): array
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
            'gold_gained' => 0,
            'spirit_stone_gained' => 0,
        ];
    }

    private function grantBossRewards(PDO $db, int $userId, int $difficulty): array
    {
        $gold = max(50, 200 * $difficulty);
        $spiritStones = max(1, 2 * $difficulty);

        $rewardService = new RewardService();
        $rewardService->grantCurrency($db, $userId, $gold, $spiritStones);
        $manualService = new CultivationManualService();
        $manualReward = $manualService->awardDungeonManual($db, $userId, $difficulty);

        return [
            'gold' => $gold,
            'spirit_stones' => $spiritStones,
            'manual' => $manualReward,
        ];
    }
}
