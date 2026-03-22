<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/AllianceService.php';
require_once __DIR__ . '/DiplomacyService.php';
require_once __DIR__ . '/DaoRecord.php';
require_once __DIR__ . '/SectService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Sect War system.
 * Captureable regions become territories with a shared war crystal for the attacking sect.
 * Defenders contribute by repelling invaders while the timer protects the territory.
 */
class SectWarService
{
    private const ATTACK_COOLDOWN_SECONDS = 30;
    private const WAR_DURATION_MINUTES = 120;
    private const ATTACKER_DAMAGE_VARIANCE_MIN = 0.90;
    private const ATTACKER_DAMAGE_VARIANCE_MAX = 1.10;
    private const DEFENDER_DAMAGE_VARIANCE_MIN = 0.85;
    private const DEFENDER_DAMAGE_VARIANCE_MAX = 1.05;
    private const KILL_SCORE_WEIGHT = 500;
    private const WINNER_REWARDS = [
        1 => ['gold' => 2200, 'spirit_stones' => 8],
        2 => ['gold' => 1600, 'spirit_stones' => 6],
        3 => ['gold' => 1100, 'spirit_stones' => 4],
    ];
    private const WINNER_PARTICIPATION_REWARD = ['gold' => 500, 'spirit_stones' => 2];
    private const LOSER_PARTICIPATION_REWARD = ['gold' => 200, 'spirit_stones' => 1];

    public function getTerritoriesOverview(?int $userId = null): array
    {
        $this->finalizeCompletedWars();

        $mySectId = $this->getUserSectId($userId);
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT t.id, t.region_id, t.owner_sect_id, t.captured_at,
                       r.name AS region_name, r.description, r.difficulty, r.min_realm_id, r.can_be_captured,
                       owner.name AS owner_sect_name,
                       w.id AS active_war_id, w.attacker_sect_id, w.defender_sect_id, w.start_time, w.end_time,
                       w.crystal_max_hp, w.crystal_current_hp,
                       attacker.name AS attacker_sect_name,
                       defender.name AS defender_sect_name
                FROM sect_territories t
                JOIN world_regions r ON r.id = t.region_id
                LEFT JOIN sects owner ON owner.id = t.owner_sect_id
                LEFT JOIN sect_wars w ON w.territory_id = t.id AND w.status = 'active'
                LEFT JOIN sects attacker ON attacker.id = w.attacker_sect_id
                LEFT JOIN sects defender ON defender.id = w.defender_sect_id
                WHERE COALESCE(r.can_be_captured, 0) = 1
                ORDER BY r.difficulty ASC, r.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $alliedOwnerIds = [];
            $napOwnerIds = [];
            if ($mySectId !== null) {
                $alliedOwnerIds = array_fill_keys((new AllianceService())->getAlliedSectIds($mySectId), true);
                $napOwnerIds = array_fill_keys((new DiplomacyService())->getActiveNapPartnerSectIds($mySectId), true);
            }

            foreach ($rows as &$row) {
                $ownerId = (int)($row['owner_sect_id'] ?? 0);
                $row['is_owned_by_my_sect'] = $mySectId !== null && $ownerId === $mySectId;
                $row['owner_is_allied'] = $mySectId !== null && $ownerId > 0 && isset($alliedOwnerIds[$ownerId]);
                $row['owner_has_nap'] = $mySectId !== null && $ownerId > 0 && isset($napOwnerIds[$ownerId]);
                $row['my_sect_can_challenge'] = $mySectId !== null
                    && (int)($row['active_war_id'] ?? 0) === 0
                    && $ownerId !== $mySectId
                    && !($ownerId > 0 && isset($alliedOwnerIds[$ownerId]))
                    && !($ownerId > 0 && isset($napOwnerIds[$ownerId]));
                $row['crystal_percent'] = (int)$this->calculatePercent(
                    (int)($row['crystal_current_hp'] ?? 0),
                    (int)($row['crystal_max_hp'] ?? 0)
                );
            }
            unset($row);

            return $rows;
        } catch (PDOException $e) {
            error_log("SectWarService::getTerritoriesOverview " . $e->getMessage());
            return [];
        }
    }

    public function getActiveWarForUser(int $userId): ?array
    {
        $this->finalizeCompletedWars();
        $sectId = $this->getUserSectId($userId);
        if ($sectId === null) {
            return null;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id
                FROM sect_wars
                WHERE status = 'active' AND (attacker_sect_id = ? OR defender_sect_id = ?)
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$sectId, $sectId]);
            $warId = $stmt->fetchColumn();
            if ($warId === false) {
                return null;
            }
            return $this->getWarState($userId, (int)$warId);
        } catch (PDOException $e) {
            error_log("SectWarService::getActiveWarForUser " . $e->getMessage());
            return null;
        }
    }

    public function declareWar(int $userId, int $territoryId): array
    {
        $this->finalizeCompletedWars();

        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return ['success' => false, 'message' => 'You must be in a sect to declare war.'];
        }
        $rank = (string)($sect['rank'] ?? $sect['role'] ?? '');
        if (!in_array($rank, ['leader', 'elder'], true)) {
            return ['success' => false, 'message' => 'Only leaders and elders can declare sect wars.'];
        }

        $attackerSectId = (int)$sect['id'];

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT t.id, t.region_id, t.owner_sect_id, r.name AS region_name, r.difficulty, COALESCE(r.can_be_captured, 0) AS can_be_captured
                FROM sect_territories t
                JOIN world_regions r ON r.id = t.region_id
                WHERE t.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$territoryId]);
            $territory = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$territory || (int)$territory['can_be_captured'] !== 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Territory not found or cannot be captured.'];
            }

            if ((int)($territory['owner_sect_id'] ?? 0) === $attackerSectId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Your sect already controls this territory.'];
            }

            $defenderSectId = (int)($territory['owner_sect_id'] ?? 0) ?: null;
            if ($defenderSectId !== null && $defenderSectId > 0) {
                $allianceSvc = new AllianceService();
                if ($allianceSvc->areSectsAllied($attackerSectId, $defenderSectId)) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'You cannot declare war on territory held by an allied sect.'];
                }
                if ((new DiplomacyService())->hasActiveNap($attackerSectId, $defenderSectId)) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'You cannot declare war on territory held under a non-aggression pact.'];
                }
            }

            if ($this->hasActiveWarForTerritory($db, (int)$territory['id'])) {
                $db->rollBack();
                return ['success' => false, 'message' => 'This territory is already under war.'];
            }

            if ($this->hasActiveWarForSect($db, $attackerSectId)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Your sect is already involved in another active war.'];
            }

            if ($defenderSectId !== null && $this->hasActiveWarForSect($db, $defenderSectId)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'The defending sect is already involved in another active war.'];
            }

            $startTime = date('Y-m-d H:i:s');
            $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' + ' . self::WAR_DURATION_MINUTES . ' minutes'));
            $maxHp = $this->getCrystalMaxHp((int)$territory['difficulty']);

            $db->prepare("
                INSERT INTO sect_wars (
                    territory_id, attacker_sect_id, defender_sect_id, start_time, end_time,
                    crystal_max_hp, crystal_current_hp, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ")->execute([(int)$territory['id'], $attackerSectId, $defenderSectId, $startTime, $endTime, $maxHp, $maxHp]);
            $warId = (int)$db->lastInsertId();

            $attackerName = (string)$sect['name'];
            $defenderName = $this->getSectNameById($db, $defenderSectId);
            $announcement = $defenderSectId !== null
                ? "Sect War: {$attackerName} has challenged {$defenderName} for {$territory['region_name']}."
                : "Sect War: {$attackerName} has launched an assault on the unclaimed {$territory['region_name']}.";
            $this->createAnnouncement($db, $announcement, $endTime);
            DaoRecord::log(
                'sect_war',
                $userId,
                $warId,
                'You declared sect war for ' . (string)$territory['region_name'] . '.',
                [
                    'attacker_sect_id' => $attackerSectId,
                    'defender_sect_id' => $defenderSectId,
                    'territory_id' => (int)$territory['id'],
                    'region_name' => (string)$territory['region_name'],
                ],
                $db
            );

            $db->commit();
            return [
                'success' => true,
                'message' => 'Sect war declared.',
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectWarService::declareWar " . $e->getMessage());
            return ['success' => false, 'message' => 'Could not declare sect war.'];
        }
    }

    public function attack(int $userId, int $warId): array
    {
        $this->finalizeCompletedWars();

        $sect = (new SectService())->getSectByUserId($userId);
        if (!$sect) {
            return ['success' => false, 'message' => 'You must be in a sect to join this war.'];
        }
        $sectId = (int)$sect['id'];

        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT last_sect_war_attack_at FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $lastAttackAt = $stmt->fetchColumn();
            if ($lastAttackAt !== null && $lastAttackAt !== '') {
                $elapsed = time() - (int)strtotime((string)$lastAttackAt);
                if ($elapsed < self::ATTACK_COOLDOWN_SECONDS) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'message' => 'Wait ' . (self::ATTACK_COOLDOWN_SECONDS - $elapsed) . ' seconds before acting again.',
                        'cooldown_remaining' => self::ATTACK_COOLDOWN_SECONDS - $elapsed,
                    ];
                }
            }

            $stmt = $db->prepare("
                SELECT w.*, t.region_id, r.name AS region_name, r.difficulty,
                       attacker.name AS attacker_sect_name,
                       defender.name AS defender_sect_name
                FROM sect_wars w
                JOIN sect_territories t ON t.id = w.territory_id
                JOIN world_regions r ON r.id = t.region_id
                JOIN sects attacker ON attacker.id = w.attacker_sect_id
                LEFT JOIN sects defender ON defender.id = w.defender_sect_id
                WHERE w.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$warId]);
            $war = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$war || (string)$war['status'] !== 'active' || strtotime((string)$war['end_time']) <= time()) {
                $db->rollBack();
                return ['success' => false, 'message' => 'This war is no longer active.'];
            }

            $isAttacker = $sectId === (int)$war['attacker_sect_id'];
            $isDefender = $sectId === (int)($war['defender_sect_id'] ?? 0);
            if (!$isAttacker && !$isDefender) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Your sect is not part of this war.'];
            }

            $statCalc = new StatCalculator();
            $stats = $statCalc->calculateFinalStats($userId);
            $attack = (int)($stats['final']['attack'] ?? 1);
            $defense = (int)($stats['final']['defense'] ?? 1);
            $difficulty = (int)$war['difficulty'];
            $allianceDamageMult = (new AllianceService())->getWarDamageMultiplierForSect($sectId);
            $defWarSectId = (int)($war['defender_sect_id'] ?? 0);
            $atkWarSectId = (int)$war['attacker_sect_id'];
            $enemySectId = $isAttacker ? $defWarSectId : $atkWarSectId;
            $rivalMult = ($defWarSectId > 0 && $enemySectId > 0)
                ? (new DiplomacyService())->getRivalDamageMultiplier($sectId, $enemySectId)
                : 1.0;
            $warDamageMult = $allianceDamageMult * $rivalMult;

            $crystalCurrentHp = (int)$war['crystal_current_hp'];
            $kills = $this->rollKills($attack, $defense, $difficulty, $isAttacker);
            $contributionDamage = 0;
            $newCrystalHp = $crystalCurrentHp;
            $message = '';
            $isWarEnded = false;

            if ($isAttacker) {
                $variance = $this->randomFloat(self::ATTACKER_DAMAGE_VARIANCE_MIN, self::ATTACKER_DAMAGE_VARIANCE_MAX);
                $damage = max(1, (int)round($attack * $variance * $warDamageMult));
                $contributionDamage = min($damage, $crystalCurrentHp);
                $newCrystalHp = max(0, $crystalCurrentHp - $contributionDamage);
                $isWarEnded = $newCrystalHp <= 0;
                $message = 'You dealt ' . number_format($contributionDamage) . ' damage to the War Crystal.';
                if ($kills > 0) {
                    $message .= ' Defeated ' . $kills . ' defenders on the way.';
                }
                $db->prepare("UPDATE sect_wars SET crystal_current_hp = ? WHERE id = ?")->execute([$newCrystalHp, $warId]);
            } else {
                $variance = $this->randomFloat(self::DEFENDER_DAMAGE_VARIANCE_MIN, self::DEFENDER_DAMAGE_VARIANCE_MAX);
                $contributionDamage = max(1, (int)round((($attack * 0.65) + ($defense * 0.35)) * $variance * $warDamageMult));
                $message = 'You repelled the assault for ' . number_format($contributionDamage) . ' battle damage.';
                if ($kills > 0) {
                    $message .= ' Repelled ' . $kills . ' invading cultivators.';
                }
            }

            $db->prepare("
                INSERT INTO sect_war_damage (war_id, user_id, sect_id, damage_dealt, kills, last_hit)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    damage_dealt = damage_dealt + VALUES(damage_dealt),
                    kills = kills + VALUES(kills),
                    last_hit = VALUES(last_hit)
            ")->execute([$warId, $userId, $sectId, $contributionDamage, $kills, $now]);

            $db->prepare("UPDATE users SET last_sect_war_attack_at = ? WHERE id = ?")->execute([$now, $userId]);

            if ($isWarEnded) {
                $this->finalizeWar($db, $war, true);
            }

            DaoRecord::log(
                'sect_war',
                $userId,
                $warId,
                $isAttacker
                    ? 'You attacked the War Crystal in ' . (string)$war['region_name'] . '.'
                    : 'You defended your territory in ' . (string)$war['region_name'] . '.',
                [
                    'side' => $isAttacker ? 'attacker' : 'defender',
                    'damage_dealt' => $contributionDamage,
                    'kills' => $kills,
                    'war_ended' => $isWarEnded,
                    'crystal_current_hp' => $newCrystalHp,
                    'crystal_max_hp' => (int)$war['crystal_max_hp'],
                ],
                $db
            );

            $db->commit();

            return [
                'success' => true,
                'message' => $message,
                'damage_dealt' => $contributionDamage,
                'kills_gained' => $kills,
                'crystal_current_hp' => $newCrystalHp,
                'crystal_max_hp' => (int)$war['crystal_max_hp'],
                'war_ended' => $isWarEnded,
                'user_side' => $isAttacker ? 'attacker' : 'defender',
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectWarService::attack " . $e->getMessage());
            return ['success' => false, 'message' => 'Sect war action failed.'];
        }
    }

    public function getWarState(int $userId, int $warId): ?array
    {
        $this->finalizeCompletedWars();

        $sectId = $this->getUserSectId($userId);
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT w.id, w.territory_id, w.attacker_sect_id, w.defender_sect_id, w.start_time, w.end_time,
                       w.crystal_max_hp, w.crystal_current_hp, w.status, w.winner_sect_id,
                       t.owner_sect_id, t.region_id,
                       r.name AS region_name, r.description, r.difficulty,
                       attacker.name AS attacker_sect_name,
                       defender.name AS defender_sect_name,
                       winner.name AS winner_sect_name
                FROM sect_wars w
                JOIN sect_territories t ON t.id = w.territory_id
                JOIN world_regions r ON r.id = t.region_id
                JOIN sects attacker ON attacker.id = w.attacker_sect_id
                LEFT JOIN sects defender ON defender.id = w.defender_sect_id
                LEFT JOIN sects winner ON winner.id = w.winner_sect_id
                WHERE w.id = ?
                LIMIT 1
            ");
            $stmt->execute([$warId]);
            $war = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$war) {
                return null;
            }
            if ((string)$war['status'] !== 'active') {
                return null;
            }

            $userSide = null;
            if ($sectId !== null) {
                if ($sectId === (int)$war['attacker_sect_id']) {
                    $userSide = 'attacker';
                } elseif ($sectId === (int)($war['defender_sect_id'] ?? 0)) {
                    $userSide = 'defender';
                }
            }

            return [
                'war' => [
                    'id' => (int)$war['id'],
                    'territory_id' => (int)$war['territory_id'],
                    'region_name' => (string)$war['region_name'],
                    'description' => (string)($war['description'] ?? ''),
                    'difficulty' => (int)$war['difficulty'],
                    'attacker_sect_id' => (int)$war['attacker_sect_id'],
                    'attacker_sect_name' => (string)$war['attacker_sect_name'],
                    'defender_sect_id' => $war['defender_sect_id'] !== null ? (int)$war['defender_sect_id'] : null,
                    'defender_sect_name' => (string)($war['defender_sect_name'] ?? 'Unclaimed Land'),
                    'winner_sect_id' => $war['winner_sect_id'] !== null ? (int)$war['winner_sect_id'] : null,
                    'winner_sect_name' => (string)($war['winner_sect_name'] ?? ''),
                    'start_time' => (string)$war['start_time'],
                    'end_time' => (string)$war['end_time'],
                    'crystal_max_hp' => (int)$war['crystal_max_hp'],
                    'crystal_current_hp' => (int)$war['crystal_current_hp'],
                    'crystal_percent' => $this->calculatePercent((int)$war['crystal_current_hp'], (int)$war['crystal_max_hp']),
                    'status' => (string)$war['status'],
                ],
                'user_side' => $userSide,
                'cooldown_remaining' => $this->getCooldownRemaining($userId),
                'attackers' => $this->getLeaderboardForSect((int)$war['id'], (int)$war['attacker_sect_id']),
                'defenders' => $war['defender_sect_id'] !== null
                    ? $this->getLeaderboardForSect((int)$war['id'], (int)$war['defender_sect_id'])
                    : [],
            ];
        } catch (PDOException $e) {
            error_log("SectWarService::getWarState " . $e->getMessage());
            return null;
        }
    }

    public function getCooldownRemaining(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT last_sect_war_attack_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $lastAt = $stmt->fetchColumn();
            if ($lastAt === false || $lastAt === null || $lastAt === '') {
                return 0;
            }
            $elapsed = time() - (int)strtotime((string)$lastAt);
            return max(0, self::ATTACK_COOLDOWN_SECONDS - $elapsed);
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function getLeaderboardForSect(int $warId, int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT d.user_id, d.damage_dealt, d.kills, d.last_hit, u.username,
                       (d.damage_dealt + (d.kills * ?)) AS contribution_score
                FROM sect_war_damage d
                JOIN users u ON u.id = d.user_id
                WHERE d.war_id = ? AND d.sect_id = ?
                ORDER BY contribution_score DESC, d.damage_dealt DESC, d.last_hit ASC
                LIMIT 10
            ");
            $stmt->execute([self::KILL_SCORE_WEIGHT, $warId, $sectId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("SectWarService::getLeaderboardForSect " . $e->getMessage());
            return [];
        }
    }

    private function finalizeCompletedWars(): void
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("
                SELECT id
                FROM sect_wars
                WHERE status = 'active' AND (crystal_current_hp <= 0 OR end_time <= ?)
            ");
            $stmt->execute([$now]);
            $warIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            foreach ($warIds as $warId) {
                $db->beginTransaction();
                $stmt = $db->prepare("
                    SELECT w.*, t.owner_sect_id, t.region_id, r.name AS region_name,
                           attacker.name AS attacker_sect_name,
                           defender.name AS defender_sect_name
                    FROM sect_wars w
                    JOIN sect_territories t ON t.id = w.territory_id
                    JOIN world_regions r ON r.id = t.region_id
                    JOIN sects attacker ON attacker.id = w.attacker_sect_id
                    LEFT JOIN sects defender ON defender.id = w.defender_sect_id
                    WHERE w.id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt->execute([(int)$warId]);
                $war = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$war || (string)$war['status'] !== 'active') {
                    $db->rollBack();
                    continue;
                }

                $attackersDestroyedCrystal = (int)$war['crystal_current_hp'] <= 0;
                $this->finalizeWar($db, $war, $attackersDestroyedCrystal);
                $db->commit();
            }
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SectWarService::finalizeCompletedWars " . $e->getMessage());
        }
    }

    private function finalizeWar(PDO $db, array $war, bool $attackersDestroyedCrystal): void
    {
        $winnerSectId = null;
        if ($attackersDestroyedCrystal) {
            $winnerSectId = (int)$war['attacker_sect_id'];
        } elseif (!empty($war['defender_sect_id'])) {
            $winnerSectId = (int)$war['defender_sect_id'];
        }

        $db->prepare("
            UPDATE sect_wars
            SET status = 'completed',
                crystal_current_hp = GREATEST(0, crystal_current_hp),
                winner_sect_id = ?,
                rewards_distributed_at = COALESCE(rewards_distributed_at, NOW())
            WHERE id = ?
        ")->execute([$winnerSectId ?: null, (int)$war['id']]);

        $db->prepare("
            UPDATE sect_territories
            SET owner_sect_id = ?, captured_at = CASE WHEN ? IS NULL THEN captured_at ELSE NOW() END
            WHERE id = ?
        ")->execute([$winnerSectId ?: null, $winnerSectId ?: null, (int)$war['territory_id']]);

        $this->distributeWarRewards($db, (int)$war['id'], $winnerSectId);

        $regionName = (string)$war['region_name'];
        $attackerName = (string)$war['attacker_sect_name'];
        $defenderName = (string)($war['defender_sect_name'] ?? 'Unclaimed Land');
        if ($attackersDestroyedCrystal) {
            $message = "Sect War ended: {$attackerName} destroyed the War Crystal and captured {$regionName}.";
        } elseif (!empty($war['defender_sect_id'])) {
            $message = "Sect War ended: {$defenderName} held {$regionName} until the timer expired.";
        } else {
            $message = "Sect War ended: {$attackerName} failed to conquer the unclaimed {$regionName} before time ran out.";
        }
        $this->createAnnouncement($db, $message, date('Y-m-d H:i:s', strtotime('+1 hour')));
        $stmt = $db->prepare('SELECT DISTINCT user_id, sect_id, damage_dealt, kills FROM sect_war_damage WHERE war_id = ?');
        $stmt->execute([(int)$war['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $participant) {
            $isWinner = $winnerSectId !== null && (int)$participant['sect_id'] === $winnerSectId;
            DaoRecord::log(
                'sect_war',
                (int)$participant['user_id'],
                (int)$war['id'],
                'Sect war for ' . $regionName . ' has concluded and your sect ' . ($isWinner ? 'prevailed.' : 'was repelled.'),
                [
                    'winner_sect_id' => $winnerSectId,
                    'territory_id' => (int)$war['territory_id'],
                    'damage_dealt' => (int)$participant['damage_dealt'],
                    'kills' => (int)$participant['kills'],
                ],
                $db
            );
        }
    }

    private function distributeWarRewards(PDO $db, int $warId, ?int $winnerSectId): void
    {
        $stmt = $db->prepare("
            SELECT user_id, sect_id, damage_dealt, kills,
                   (damage_dealt + (kills * ?)) AS contribution_score
            FROM sect_war_damage
            WHERE war_id = ?
            ORDER BY sect_id ASC, contribution_score DESC, damage_dealt DESC, last_hit ASC
        ");
        $stmt->execute([self::KILL_SCORE_WEIGHT, $warId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rewardService = new RewardService();
        $rankBySect = [];
        foreach ($rows as $row) {
            $score = (int)$row['contribution_score'];
            if ($score <= 0) {
                continue;
            }

            $sectId = (int)$row['sect_id'];
            $rankBySect[$sectId] = ($rankBySect[$sectId] ?? 0) + 1;
            $rank = $rankBySect[$sectId];
            $isWinner = $winnerSectId !== null && $sectId === $winnerSectId;
            $reward = $this->getRewardForContribution($rank, $isWinner);
            if ($reward['gold'] <= 0 && $reward['spirit_stones'] <= 0) {
                continue;
            }
            $rewardService->grantCurrency($db, (int)$row['user_id'], $reward['gold'], $reward['spirit_stones']);
        }
    }

    private function getRewardForContribution(int $rank, bool $isWinner): array
    {
        if ($isWinner) {
            return self::WINNER_REWARDS[$rank] ?? self::WINNER_PARTICIPATION_REWARD;
        }
        return self::LOSER_PARTICIPATION_REWARD;
    }

    private function hasActiveWarForTerritory(PDO $db, int $territoryId): bool
    {
        $stmt = $db->prepare("SELECT id FROM sect_wars WHERE territory_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$territoryId]);
        return (bool)$stmt->fetchColumn();
    }

    private function hasActiveWarForSect(PDO $db, int $sectId): bool
    {
        $stmt = $db->prepare("
            SELECT id
            FROM sect_wars
            WHERE status = 'active' AND (attacker_sect_id = ? OR defender_sect_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$sectId, $sectId]);
        return (bool)$stmt->fetchColumn();
    }

    private function getUserSectId(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }
        $sect = (new SectService())->getSectByUserId($userId);
        return $sect ? (int)$sect['id'] : null;
    }

    private function getSectNameById(PDO $db, ?int $sectId): string
    {
        if ($sectId === null || $sectId <= 0) {
            return 'Unclaimed Land';
        }
        $stmt = $db->prepare("SELECT name FROM sects WHERE id = ? LIMIT 1");
        $stmt->execute([$sectId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string)$name : 'Unknown Sect';
    }

    private function createAnnouncement(PDO $db, string $message, string $expiresAt): void
    {
        $db->prepare("
            INSERT INTO global_announcements (message, created_at, expires_at)
            VALUES (?, NOW(), ?)
        ")->execute([$message, $expiresAt]);
    }

    private function getCrystalMaxHp(int $difficulty): int
    {
        return max(150000, $difficulty * 150000);
    }

    private function rollKills(int $attack, int $defense, int $difficulty, bool $isAttacker): int
    {
        $power = $isAttacker ? $attack : (int)round(($attack * 0.5) + ($defense * 0.5));
        $threshold = min(45, max(8, (int)floor($power / max(1, $difficulty * 12))));
        return mt_rand(1, 100) <= $threshold ? 1 : 0;
    }

    private function randomFloat(float $min, float $max): float
    {
        return $min + (mt_rand(1, 100) / 100.0) * ($max - $min);
    }

    private function calculatePercent(int $current, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }
        return min(100, (int)round(($current / $max) * 100));
    }
}
