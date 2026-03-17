<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/CultivationManualService.php';
require_once __DIR__ . '/DaoRecord.php';
require_once __DIR__ . '/../core/Cache.php';

use Game\Config\Database;
use Game\Core\Cache;
use PDO;
use PDOException;

/**
 * Phase 3.1 World Boss: active boss, attack (cooldown 30s), damage log, leaderboard.
 * Boss disappears when HP reaches 0 or end_time passes. Spawn creates global announcement.
 */
class WorldBossService
{
    private const WORLD_STATE_WORLD_BOSS_EVENT = 'world_boss_event';
    private const ATTACK_COOLDOWN_SECONDS = 30;
    private const DAMAGE_VARIANCE_MIN = 0.90;
    private const DAMAGE_VARIANCE_MAX = 1.10;
    private const DEFAULT_LEADERBOARD_LIMIT = 10;
    private const RANK_REWARDS = [
        1 => ['gold' => 1500, 'spirit_stones' => 10],
        2 => ['gold' => 1000, 'spirit_stones' => 7],
        3 => ['gold' => 700, 'spirit_stones' => 5],
    ];
    private const TOP_TEN_REWARD = ['gold' => 300, 'spirit_stones' => 2];
    private const PARTICIPATION_REWARD = ['gold' => 100, 'spirit_stones' => 1];
    private const LEGENDARY_DROP_CHANCES = [
        1 => 0.12,
        2 => 0.10,
        3 => 0.08,
    ];
    private const LEGENDARY_DROP_TOP10 = 0.04;

    /**
     * Close expired bosses (end_time < now). Return active boss (is_alive=1, current_hp>0, end_time>=now).
     */
    public function getActiveBoss(): ?array
    {
        try {
            $this->syncBossLifecycle();
            return Cache::remember('world_boss:active', 5, function (): ?array {
                $db = Database::getConnection();
                $now = date('Y-m-d H:i:s');

                $stmt = $db->prepare("
                    SELECT b.id, b.name, b.template_id, b.region_id, b.max_hp, b.current_hp, b.spawn_time, b.end_time, b.is_alive,
                           r.name AS region_name
                    FROM world_bosses b
                    LEFT JOIN world_regions r ON r.id = b.region_id
                    WHERE b.is_alive = 1 AND b.current_hp > 0 AND b.end_time > ?
                    ORDER BY b.id DESC
                    LIMIT 1
                ");
                $stmt->execute([$now]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            });
        } catch (PDOException $e) {
            error_log("WorldBossService::getActiveBoss " . $e->getMessage());
            return null;
        }
    }

    /**
     * Damage leaderboard for a boss (top contributors).
     */
    public function getDamageLeaderboard(int $bossId, int $limit = self::DEFAULT_LEADERBOARD_LIMIT): array
    {
        try {
            $cacheKey = 'world_boss:leaderboard:' . $bossId . ':' . max(1, $limit);
            return Cache::remember($cacheKey, 5, function () use ($bossId, $limit): array {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT d.user_id, d.damage_dealt, d.last_hit, u.username
                    FROM boss_damage_log d
                    JOIN users u ON u.id = d.user_id
                    WHERE d.boss_id = ?
                    ORDER BY d.damage_dealt DESC, d.last_hit ASC, d.user_id ASC
                    LIMIT " . max(1, (int)$limit)
                );
                $stmt->execute([$bossId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return $rows ?: [];
            });
        } catch (PDOException $e) {
            error_log("WorldBossService::getDamageLeaderboard " . $e->getMessage());
            return [];
        }
    }

    /**
     * Attack the current boss. Validates cooldown, computes damage, deducts HP, logs contribution.
     * Returns [success, message, damage_dealt?, current_hp?, max_hp?, is_dead?, cooldown_remaining?].
     */
    public function attack(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $this->syncBossLifecycle(true);
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT last_boss_attack_at FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $lastAt = $stmt->fetchColumn();
            if ($lastAt !== null && $lastAt !== '') {
                $elapsed = time() - (int)strtotime($lastAt);
                if ($elapsed < self::ATTACK_COOLDOWN_SECONDS) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'message' => 'Wait ' . (self::ATTACK_COOLDOWN_SECONDS - $elapsed) . ' seconds before attacking again.',
                        'cooldown_remaining' => self::ATTACK_COOLDOWN_SECONDS - $elapsed,
                    ];
                }
            }

            $statCalc = new StatCalculator();
            $attack = $statCalc->getFinalAttack($userId);
            $variance = self::DAMAGE_VARIANCE_MIN + (mt_rand(1, 100) / 100.0) * (self::DAMAGE_VARIANCE_MAX - self::DAMAGE_VARIANCE_MIN);
            $damage = max(1, (int)round($attack * $variance));

            $stmt = $db->prepare("
                SELECT id, name, region_id, max_hp, current_hp, spawn_time, end_time, is_alive, template_id
                FROM world_bosses
                WHERE is_alive = 1 AND current_hp > 0 AND end_time > ?
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$now]);
            $boss = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$boss) {
                $db->rollBack();
                return ['success' => false, 'message' => 'No active world boss.'];
            }

            $bossId = (int)$boss['id'];
            $currentHp = (int)$boss['current_hp'];
            $actualDamage = min($damage, $currentHp);
            $newHp = max(0, $currentHp - $actualDamage);
            $isDead = $newHp <= 0;

            $db->prepare("UPDATE world_bosses SET current_hp = ?, is_alive = ? WHERE id = ?")
                ->execute([$newHp, $isDead ? 0 : 1, $bossId]);

            $stmt = $db->prepare("INSERT INTO boss_damage_log (boss_id, user_id, damage_dealt, last_hit) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE damage_dealt = damage_dealt + VALUES(damage_dealt), last_hit = VALUES(last_hit)");
            $stmt->execute([$bossId, $userId, $actualDamage, $now]);

            $db->prepare("UPDATE users SET last_boss_attack_at = ? WHERE id = ?")->execute([$now, $userId]);
            $this->invalidateBossCaches($bossId);

            DaoRecord::log(
                'world_boss',
                $userId,
                $bossId,
                'You struck the world boss ' . (string)$boss['name'] . '.',
                [
                    'damage_dealt' => $actualDamage,
                    'current_hp' => $newHp,
                    'max_hp' => (int)$boss['max_hp'],
                    'is_killing_blow' => $isDead,
                ],
                $db
            );

            if ($isDead) {
                $this->finalizeBossRewards($db, $bossId, (string)$boss['name'], true, isset($boss['template_id']) ? (int)$boss['template_id'] : null);
            }

            $db->commit();

            return [
                'success' => true,
                'message' => 'You dealt ' . $actualDamage . ' damage!',
                'damage_dealt' => $actualDamage,
                'current_hp' => $newHp,
                'max_hp' => (int)$boss['max_hp'],
                'is_dead' => $isDead,
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("WorldBossService::attack " . $e->getMessage());
            return ['success' => false, 'message' => 'Attack failed.'];
        }
    }

    /**
     * Spawn a world boss and add global announcement. For admin/scheduled use.
     */
    public function spawnBoss(string $name, int $maxHp, int $durationMinutes): array
    {
        if ($maxHp < 1 || $durationMinutes < 1) {
            return ['success' => false, 'message' => 'Invalid max_hp or duration.'];
        }
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $endTime = date('Y-m-d H:i:s', strtotime($now . ' + ' . $durationMinutes . ' minutes'));

            $template = $this->getBossTemplateByName($name);
            $templateId = $template ? (int)$template['id'] : null;
            $regionId = $template ? (int)$template['region_id'] : null;
            $regionName = $template ? (string)($template['region_name'] ?? '') : '';
            $db->prepare("INSERT INTO world_bosses (template_id, name, region_id, max_hp, current_hp, spawn_time, end_time, is_alive) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$templateId, $name, $regionId, $maxHp, $maxHp, $now, $endTime]);
            $bossId = (int)$db->lastInsertId();

            $msg = $regionName !== ''
                ? "Legendary World Boss [{$name}] has appeared in {$regionName}! Deal damage before time runs out."
                : "World Boss [{$name}] has appeared! Deal damage before time runs out.";
            $db->prepare("INSERT INTO global_announcements (message, created_at, expires_at) VALUES (?, ?, ?)")
                ->execute([$msg, $now, $endTime]);
            $this->setWorldBossEventState($db, $msg);
            $this->invalidateBossCaches($bossId);

            return ['success' => true, 'boss_id' => $bossId, 'end_time' => $endTime];
        } catch (PDOException $e) {
            error_log("WorldBossService::spawnBoss " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not spawn boss.'];
        }
    }

    /**
     * Spawn one of the seeded legendary world bosses by template id or name.
     */
    public function spawnLegendaryBoss(string $templateName): array
    {
        $template = $this->getBossTemplateByName($templateName);
        if (!$template) {
            return ['success' => false, 'message' => 'Legendary boss template not found.'];
        }
        return $this->spawnBoss(
            (string)$template['name'],
            (int)$template['max_hp'],
            (int)$template['duration_minutes']
        );
    }

    /**
     * Cooldown remaining for user (seconds). 0 if can attack.
     */
    public function getCooldownRemaining(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT last_boss_attack_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $lastAt = $stmt->fetchColumn();
            if ($lastAt === null || $lastAt === '') {
                return 0;
            }
            $elapsed = time() - (int)strtotime($lastAt);
            return max(0, self::ATTACK_COOLDOWN_SECONDS - $elapsed);
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Boss state payload for AJAX polling.
     */
    public function getBossState(int $userId): array
    {
        $this->syncBossLifecycle();
        $boss = $this->getActiveBoss();
        if (!$boss) {
            return [
                'boss' => null,
                'leaderboard' => [],
                'cooldown_remaining' => $this->getCooldownRemaining($userId),
            ];
        }

        return [
            'boss' => [
                'id' => (int)$boss['id'],
                'name' => (string)$boss['name'],
                'region_id' => isset($boss['region_id']) ? (int)$boss['region_id'] : null,
                'region_name' => (string)($boss['region_name'] ?? ''),
                'max_hp' => (int)$boss['max_hp'],
                'current_hp' => (int)$boss['current_hp'],
                'spawn_time' => (string)$boss['spawn_time'],
                'end_time' => (string)$boss['end_time'],
                'is_alive' => (int)$boss['is_alive'] === 1,
            ],
            'leaderboard' => $this->getDamageLeaderboard((int)$boss['id'], self::DEFAULT_LEADERBOARD_LIMIT),
            'cooldown_remaining' => $this->getCooldownRemaining($userId),
        ];
    }

    /**
     * Final rewards already written for this boss.
     */
    public function getBossRewards(int $bossId, int $limit = self::DEFAULT_LEADERBOARD_LIMIT): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT r.user_id, r.rank_position, r.damage_dealt, r.gold_reward, r.spirit_stone_reward,
                       r.legendary_item_template_id, u.username, t.name AS legendary_item_name
                FROM boss_rewards r
                JOIN users u ON u.id = r.user_id
                LEFT JOIN item_templates t ON t.id = r.legendary_item_template_id
                WHERE r.boss_id = ?
                ORDER BY r.rank_position ASC
                LIMIT ?
            ");
            $stmt->execute([$bossId, $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("WorldBossService::getBossRewards " . $e->getMessage());
            return [];
        }
    }

    /**
     * Finalize bosses that ended by death or time expiry and have not had rewards written yet.
     */
    private function finalizeCompletedBosses(): void
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("
                SELECT b.id, b.name
                FROM world_bosses b
                WHERE (b.current_hp <= 0 OR b.end_time <= ? OR b.is_alive = 0)
                  AND b.rewards_distributed_at IS NULL
            ");
            $stmt->execute([$now]);
            $bosses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($bosses as $boss) {
                $db->beginTransaction();
                $lock = $db->prepare("SELECT id, name, template_id, current_hp, end_time, rewards_distributed_at FROM world_bosses WHERE id = ? FOR UPDATE");
                $lock->execute([(int)$boss['id']]);
                $lockedBoss = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$lockedBoss) {
                    $db->rollBack();
                    continue;
                }
                if (!empty($lockedBoss['rewards_distributed_at'])) {
                    $db->rollBack();
                    continue;
                }
                $db->prepare("UPDATE world_bosses SET is_alive = 0, current_hp = GREATEST(0, current_hp) WHERE id = ?")
                    ->execute([(int)$boss['id']]);
                $isKilled = (int)$lockedBoss['current_hp'] <= 0;
                $this->finalizeBossRewards($db, (int)$boss['id'], (string)$boss['name'], $isKilled, isset($lockedBoss['template_id']) ? (int)$lockedBoss['template_id'] : null);
                $db->commit();
            }
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("WorldBossService::finalizeCompletedBosses " . $e->getMessage());
        }
    }

    /**
     * Write final rankings and distribute rewards exactly once inside an existing transaction.
     */
    private function finalizeBossRewards(PDO $db, int $bossId, string $bossName, bool $isKilled, ?int $templateId = null): void
    {
        $legendaryItemTemplateId = $this->getLegendaryItemTemplateId($templateId);
        $manualService = new CultivationManualService();
        $stmt = $db->prepare("
            SELECT user_id, damage_dealt
            FROM boss_damage_log
            WHERE boss_id = ?
            ORDER BY damage_dealt DESC, last_hit ASC, user_id ASC
        ");
        $stmt->execute([$bossId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $userId = (int)$row['user_id'];
            $damage = (int)$row['damage_dealt'];
            $reward = $this->getRewardForRank($rank);
            $legendaryDrop = $this->rollLegendaryDrop($rank, $legendaryItemTemplateId) ? $legendaryItemTemplateId : null;

            $db->prepare("
                INSERT INTO boss_rewards (
                    boss_id, user_id, rank_position, damage_dealt, gold_reward, spirit_stone_reward, legendary_item_template_id, awarded_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    rank_position = VALUES(rank_position),
                    damage_dealt = VALUES(damage_dealt),
                    gold_reward = VALUES(gold_reward),
                    spirit_stone_reward = VALUES(spirit_stone_reward),
                    legendary_item_template_id = VALUES(legendary_item_template_id)
            ")->execute([$bossId, $userId, $rank, $damage, $reward['gold'], $reward['spirit_stones'], $legendaryDrop]);

            $db->prepare("
                UPDATE users
                SET gold = GREATEST(0, gold + ?),
                    spirit_stones = GREATEST(0, spirit_stones + ?)
                WHERE id = ?
            ")->execute([$reward['gold'], $reward['spirit_stones'], $userId]);

            if ($legendaryDrop !== null) {
                $this->grantLegendaryItemReward($db, $userId, $legendaryDrop);
            }
            $manualReward = $manualService->awardWorldBossManual($db, $userId, $rank);
            DaoRecord::log(
                'world_boss',
                $userId,
                $bossId,
                'World boss rewards were distributed for ' . $bossName . '.',
                [
                    'rank' => $rank,
                    'damage_dealt' => $damage,
                    'gold_reward' => $reward['gold'],
                    'spirit_stone_reward' => $reward['spirit_stones'],
                    'legendary_item_template_id' => $legendaryDrop,
                    'manual_id' => $manualReward['id'] ?? null,
                ],
                $db
            );
        }

        $message = $isKilled
            ? "World Boss {$bossName} has been defeated! Rewards have been distributed."
            : "World Boss {$bossName} has vanished. Final rankings are locked and rewards distributed.";
        $db->prepare("
            INSERT INTO global_announcements (message, created_at, expires_at)
            VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ")->execute([$message]);
        $db->prepare("UPDATE world_bosses SET rewards_distributed_at = NOW(), is_alive = 0 WHERE id = ?")->execute([$bossId]);
        $this->setWorldBossEventState($db, '');
        $this->invalidateBossCaches($bossId);
    }

    private function getRewardForRank(int $rank): array
    {
        if (isset(self::RANK_REWARDS[$rank])) {
            return self::RANK_REWARDS[$rank];
        }
        if ($rank <= 10) {
            return self::TOP_TEN_REWARD;
        }
        return self::PARTICIPATION_REWARD;
    }

    private function getBossTemplateByName(string $name): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.id, t.name, t.region_id, t.max_hp, t.duration_minutes, t.legendary_item_template_id,
                       r.name AS region_name
                FROM world_boss_templates t
                LEFT JOIN world_regions r ON r.id = t.region_id
                WHERE t.name = ?
                LIMIT 1
            ");
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("WorldBossService::getBossTemplateByName " . $e->getMessage());
            return null;
        }
    }

    private function getLegendaryItemTemplateId(?int $templateId): ?int
    {
        if ($templateId === null) {
            return null;
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT legendary_item_template_id FROM world_boss_templates WHERE id = ? LIMIT 1");
            $stmt->execute([$templateId]);
            $value = $stmt->fetchColumn();
            return $value !== false && $value !== null ? (int)$value : null;
        } catch (PDOException $e) {
            error_log("WorldBossService::getLegendaryItemTemplateId " . $e->getMessage());
            return null;
        }
    }

    private function rollLegendaryDrop(int $rank, ?int $legendaryItemTemplateId): bool
    {
        if ($legendaryItemTemplateId === null) {
            return false;
        }
        $chance = self::LEGENDARY_DROP_CHANCES[$rank] ?? ($rank <= 10 ? self::LEGENDARY_DROP_TOP10 : 0.0);
        if ($chance <= 0) {
            return false;
        }
        return (mt_rand(1, 10000) / 10000) <= $chance;
    }

    private function setWorldBossEventState(PDO $db, string $value): void
    {
        $db->prepare("
            INSERT INTO world_state (key_name, value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)
        ")->execute([self::WORLD_STATE_WORLD_BOSS_EVENT, trim($value)]);
    }

    private function syncBossLifecycle(bool $force = false): void
    {
        if ($force) {
            $this->finalizeCompletedBosses();
            return;
        }

        Cache::remember('world_boss:lifecycle_sync', 5, function (): bool {
            $this->finalizeCompletedBosses();
            return true;
        });
    }

    private function invalidateBossCaches(?int $bossId = null): void
    {
        Cache::forget('world_boss:active');
        Cache::forget('world_boss:lifecycle_sync');
        Cache::forgetByPrefix('world_boss:leaderboard:');
        if ($bossId !== null) {
            Cache::forgetByPrefix('world_boss:leaderboard:' . $bossId . ':');
        }
    }

    private function grantLegendaryItemReward(PDO $db, int $userId, int $itemTemplateId): void
    {
        $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE user_id = ? AND item_template_id = ? AND is_equipped = 0 LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId, $itemTemplateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare("UPDATE inventory SET quantity = quantity + 1, updated_at = NOW() WHERE id = ?")
                ->execute([(int)$row['id']]);
            return;
        }

        $db->prepare("
            INSERT INTO inventory (user_id, item_template_id, quantity, is_equipped, created_at, updated_at)
            VALUES (?, ?, 1, 0, NOW(), NOW())
        ")->execute([$userId, $itemTemplateId]);
    }
}
