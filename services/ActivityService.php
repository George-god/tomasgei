<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Daily & weekly activity tasks: generate rows per period, track progress, grant rewards on completion.
 */
class ActivityService
{
    public const TASK_PVE = 'pve_wins';
    public const TASK_PVP = 'pvp_battles';
    public const TASK_EXPLORE = 'explorations';
    public const TASK_CRAFT = 'crafts';
    public const TASK_BOSS = 'boss_damage';

    /** @var array<string, array{label: string, target: int, gold: int, stones: int}> */
    private const DAILY_DEFS = [
        self::TASK_PVE => ['label' => 'Win PvE battles', 'target' => 3, 'gold' => 50, 'stones' => 0],
        self::TASK_PVP => ['label' => 'Complete PvP battles', 'target' => 2, 'gold' => 40, 'stones' => 0],
        self::TASK_EXPLORE => ['label' => 'Explore regions', 'target' => 5, 'gold' => 35, 'stones' => 0],
        self::TASK_CRAFT => ['label' => 'Craft items (alchemy / blacksmith / runes)', 'target' => 2, 'gold' => 35, 'stones' => 0],
        self::TASK_BOSS => ['label' => 'Deal damage to World Boss', 'target' => 5000, 'gold' => 80, 'stones' => 1],
    ];

    /** @var array<string, array{label: string, target: int, gold: int, stones: int}> */
    private const WEEKLY_DEFS = [
        self::TASK_PVE => ['label' => 'Win PvE battles', 'target' => 20, 'gold' => 400, 'stones' => 5],
        self::TASK_PVP => ['label' => 'Complete PvP battles', 'target' => 15, 'gold' => 350, 'stones' => 3],
        self::TASK_EXPLORE => ['label' => 'Explore regions', 'target' => 40, 'gold' => 300, 'stones' => 2],
        self::TASK_CRAFT => ['label' => 'Craft items', 'target' => 15, 'gold' => 280, 'stones' => 3],
        self::TASK_BOSS => ['label' => 'Deal damage to World Boss', 'target' => 50000, 'gold' => 600, 'stones' => 10],
    ];

    public function todayDate(): string
    {
        return date('Y-m-d');
    }

    public function currentWeekStart(): string
    {
        return date('Y-m-d', strtotime('monday this week'));
    }

    /**
     * Ensure rows exist for today / this week (idempotent).
     */
    public function ensureTasksForUser(int $userId): void
    {
        $this->ensureDailyRows($userId, $this->todayDate());
        $this->ensureWeeklyRows($userId, $this->currentWeekStart());
    }

    private function ensureDailyRows(int $userId, string $periodDate): void
    {
        try {
            $db = Database::getConnection();
            foreach (self::DAILY_DEFS as $key => $def) {
                $stmt = $db->prepare("
                    INSERT IGNORE INTO daily_tasks (user_id, task_key, period_date, target_value, progress, reward_gold, reward_spirit_stones)
                    VALUES (?, ?, ?, ?, 0, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $key,
                    $periodDate,
                    $def['target'],
                    $def['gold'],
                    $def['stones'],
                ]);
            }
        } catch (PDOException $e) {
            error_log('ActivityService::ensureDailyRows ' . $e->getMessage());
        }
    }

    private function ensureWeeklyRows(int $userId, string $weekStart): void
    {
        try {
            $db = Database::getConnection();
            foreach (self::WEEKLY_DEFS as $key => $def) {
                $stmt = $db->prepare("
                    INSERT IGNORE INTO weekly_tasks (user_id, task_key, week_start, target_value, progress, reward_gold, reward_spirit_stones)
                    VALUES (?, ?, ?, ?, 0, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $key,
                    $weekStart,
                    $def['target'],
                    $def['gold'],
                    $def['stones'],
                ]);
            }
        } catch (PDOException $e) {
            error_log('ActivityService::ensureWeeklyRows ' . $e->getMessage());
        }
    }

    public function recordPveWin(int $userId): void
    {
        $this->incrementDaily($userId, self::TASK_PVE, 1);
        $this->incrementWeekly($userId, self::TASK_PVE, 1);
    }

    public function recordPvpBattle(int $userId): void
    {
        $this->incrementDaily($userId, self::TASK_PVP, 1);
        $this->incrementWeekly($userId, self::TASK_PVP, 1);
    }

    public function recordExploration(int $userId): void
    {
        $this->incrementDaily($userId, self::TASK_EXPLORE, 1);
        $this->incrementWeekly($userId, self::TASK_EXPLORE, 1);
    }

    public function recordCraft(int $userId): void
    {
        $this->incrementDaily($userId, self::TASK_CRAFT, 1);
        $this->incrementWeekly($userId, self::TASK_CRAFT, 1);
    }

    public function recordBossDamage(int $userId, int $damage): void
    {
        if ($damage < 1) {
            return;
        }
        $this->incrementDaily($userId, self::TASK_BOSS, $damage);
        $this->incrementWeekly($userId, self::TASK_BOSS, $damage);
    }

    private function incrementDaily(int $userId, string $taskKey, int $amount): void
    {
        if ($amount < 1) {
            return;
        }
        try {
            $this->ensureDailyRows($userId, $this->todayDate());
            $db = Database::getConnection();
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT id, target_value, progress, reward_gold, reward_spirit_stones, completed_at
                FROM daily_tasks
                WHERE user_id = ? AND period_date = ? AND task_key = ?
                FOR UPDATE
            ");
            $stmt->execute([$userId, $this->todayDate(), $taskKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['completed_at'] !== null) {
                $db->commit();
                return;
            }
            $target = (int)$row['target_value'];
            $progress = min($target, (int)$row['progress'] + $amount);
            $db->prepare("UPDATE daily_tasks SET progress = ? WHERE id = ?")->execute([$progress, (int)$row['id']]);
            if ($progress >= $target) {
                $this->grantRewards(
                    $db,
                    $userId,
                    (int)$row['reward_gold'],
                    (int)$row['reward_spirit_stones'],
                    'daily',
                    $taskKey
                );
                $db->prepare("UPDATE daily_tasks SET completed_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
            }
            $db->commit();
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ActivityService::incrementDaily ' . $e->getMessage());
        }
    }

    private function incrementWeekly(int $userId, string $taskKey, int $amount): void
    {
        if ($amount < 1) {
            return;
        }
        try {
            $week = $this->currentWeekStart();
            $this->ensureWeeklyRows($userId, $week);
            $db = Database::getConnection();
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT id, target_value, progress, reward_gold, reward_spirit_stones, completed_at
                FROM weekly_tasks
                WHERE user_id = ? AND week_start = ? AND task_key = ?
                FOR UPDATE
            ");
            $stmt->execute([$userId, $week, $taskKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['completed_at'] !== null) {
                $db->commit();
                return;
            }
            $target = (int)$row['target_value'];
            $progress = min($target, (int)$row['progress'] + $amount);
            $db->prepare("UPDATE weekly_tasks SET progress = ? WHERE id = ?")->execute([$progress, (int)$row['id']]);
            if ($progress >= $target) {
                $this->grantRewards(
                    $db,
                    $userId,
                    (int)$row['reward_gold'],
                    (int)$row['reward_spirit_stones'],
                    'weekly',
                    $taskKey
                );
                $db->prepare("UPDATE weekly_tasks SET completed_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
            }
            $db->commit();
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ActivityService::incrementWeekly ' . $e->getMessage());
        }
    }

    private function grantRewards(PDO $db, int $userId, int $gold, int $stones, string $period, string $taskKey): void
    {
        if ($gold > 0) {
            $db->prepare("UPDATE users SET gold = gold + ? WHERE id = ?")->execute([$gold, $userId]);
        }
        if ($stones > 0) {
            $db->prepare("UPDATE users SET spirit_stones = spirit_stones + ? WHERE id = ?")->execute([$stones, $userId]);
        }
        $label = self::DAILY_DEFS[$taskKey]['label'] ?? $taskKey;
        $msg = sprintf(
            'Activity reward (%s · %s): +%d gold%s.',
            $period === 'daily' ? 'Daily' : 'Weekly',
            $label,
            $gold,
            $stones > 0 ? ", +{$stones} spirit stones" : ''
        );
        try {
            $ns = new NotificationService();
            $ns->createNotification($userId, 'activity', 'Activity complete', $msg, null, 'activity');
        } catch (\Throwable $e) {
            // ignore notification failure
        }
    }

    /**
     * @return array{daily: array, weekly: array, today: string, week_start: string}
     */
    public function getDashboard(int $userId): array
    {
        $this->ensureTasksForUser($userId);
        $today = $this->todayDate();
        $week = $this->currentWeekStart();
        $daily = [];
        $weekly = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM daily_tasks WHERE user_id = ? AND period_date = ? ORDER BY task_key");
            $stmt->execute([$userId, $today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = (string)$r['task_key'];
                $daily[] = $this->formatRow($r, self::DAILY_DEFS[$key] ?? ['label' => $key]);
            }
            $stmt = $db->prepare("SELECT * FROM weekly_tasks WHERE user_id = ? AND week_start = ? ORDER BY task_key");
            $stmt->execute([$userId, $week]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = (string)$r['task_key'];
                $weekly[] = $this->formatRow($r, self::WEEKLY_DEFS[$key] ?? ['label' => $key]);
            }
        } catch (PDOException $e) {
            error_log('ActivityService::getDashboard ' . $e->getMessage());
        }

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'today' => $today,
            'week_start' => $week,
        ];
    }

    private function formatRow(array $r, array $def): array
    {
        $target = max(1, (int)$r['target_value']);
        $progress = (int)$r['progress'];
        $pct = min(100, (int)round(100 * $progress / $target));

        return [
            'task_key' => $r['task_key'],
            'label' => $def['label'] ?? (string)$r['task_key'],
            'target' => $target,
            'progress' => $progress,
            'percent' => $pct,
            'reward_gold' => (int)$r['reward_gold'],
            'reward_spirit_stones' => (int)$r['reward_spirit_stones'],
            'completed' => $r['completed_at'] !== null && $r['completed_at'] !== '',
            'completed_at' => $r['completed_at'],
        ];
    }
}
