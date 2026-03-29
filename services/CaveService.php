<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Personal cultivation cave: unlock, level, environment, formation slots.
 * Bonuses apply to chi gain per cultivate and breakthrough base success / tribulation mitigation.
 */
class CaveService
{
    public const MAX_CAVE_LEVEL = 15;

    private const UNLOCK_GOLD = 1200;
    private const UNLOCK_SPIRIT_STONES = 12;

    /** Per level above 1 (fractional bonuses). */
    private const LEVEL_CULTIVATION_PER_LEVEL = 0.0035;
    private const LEVEL_BREAKTHROUGH_PER_LEVEL = 0.0018;

    private static ?bool $tablesOk = null;

    private function tablesAvailable(PDO $db): bool
    {
        if (self::$tablesOk !== null) {
            return self::$tablesOk;
        }
        try {
            $db->query('SELECT 1 FROM player_caves LIMIT 1');
            self::$tablesOk = true;
        } catch (PDOException $e) {
            self::$tablesOk = false;
        }
        return self::$tablesOk;
    }

    public function ensureRow(int $userId): void
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return;
            }
            $stmt = $db->prepare(
                'INSERT IGNORE INTO player_caves (user_id, unlocked, cave_level, environment_key) VALUES (?, 0, 1, \'balanced\')'
            );
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log('CaveService::ensureRow ' . $e->getMessage());
        }
    }

    /**
     * @return array{cultivation: float, breakthrough: float}
     */
    public function getEffectiveBonuses(int $userId): array
    {
        $state = $this->getCaveState($userId);
        if (!$state['available'] || !$state['unlocked']) {
            return ['cultivation' => 0.0, 'breakthrough' => 0.0];
        }
        return [
            'cultivation' => max(0.0, (float)($state['total_cultivation_bonus'] ?? 0.0)),
            'breakthrough' => max(0.0, (float)($state['total_breakthrough_bonus'] ?? 0.0)),
        ];
    }

    /**
     * Full state for UI and calculations.
     *
     * @return array<string, mixed>
     */
    public function getCaveState(int $userId): array
    {
        $empty = [
            'available' => false,
            'unlocked' => false,
            'cave_level' => 0,
            'environment_key' => 'balanced',
            'environment' => null,
            'formations_equipped' => [],
            'level_cultivation_bonus' => 0.0,
            'level_breakthrough_bonus' => 0.0,
            'environment_cultivation_bonus' => 0.0,
            'environment_breakthrough_bonus' => 0.0,
            'formations_cultivation_bonus' => 0.0,
            'formations_breakthrough_bonus' => 0.0,
            'total_cultivation_bonus' => 0.0,
            'total_breakthrough_bonus' => 0.0,
            'unlock_cost_gold' => self::UNLOCK_GOLD,
            'unlock_cost_spirit_stones' => self::UNLOCK_SPIRIT_STONES,
            'next_upgrade' => null,
            'environments' => [],
            'formation_catalog' => [],
            'slots' => [1 => null, 2 => null, 3 => null],
        ];

        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return $empty;
            }

            $this->ensureRow($userId);

            $stmt = $db->prepare('SELECT unlocked, cave_level, environment_key FROM player_caves WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return $empty;
            }

            $unlocked = (int)$row['unlocked'] === 1;
            $caveLevel = max(1, (int)$row['cave_level']);
            $envKey = (string)($row['environment_key'] ?: 'balanced');

            $environments = $db->query(
                'SELECT env_key, display_name, cultivation_bonus_pct, breakthrough_bonus_pct, description
                 FROM cave_environments ORDER BY sort_order ASC, display_name ASC'
            )->fetchAll() ?: [];

            $catalog = $db->query(
                'SELECT formation_key, display_name, cultivation_bonus_pct, breakthrough_bonus_pct, required_cave_level, description
                 FROM cave_formations ORDER BY sort_order ASC, display_name ASC'
            )->fetchAll() ?: [];

            $slotStmt = $db->prepare(
                'SELECT slot, formation_key FROM player_cave_formations WHERE user_id = ? AND slot IN (1,2,3) ORDER BY slot ASC'
            );
            $slotStmt->execute([$userId]);
            $slotRows = $slotStmt->fetchAll() ?: [];

            $slots = [1 => null, 2 => null, 3 => null];
            foreach ($slotRows as $sr) {
                $s = (int)$sr['slot'];
                if ($s >= 1 && $s <= 3) {
                    $fk = $sr['formation_key'];
                    $slots[$s] = $fk !== null && $fk !== '' ? (string)$fk : null;
                }
            }

            $envRow = null;
            foreach ($environments as $e) {
                if (($e['env_key'] ?? '') === $envKey) {
                    $envRow = $e;
                    break;
                }
            }
            if ($envRow === null && $environments !== []) {
                $envRow = $environments[0];
                $envKey = (string)($envRow['env_key'] ?? 'balanced');
            }

            $envCult = $envRow ? (float)$envRow['cultivation_bonus_pct'] : 0.0;
            $envBreak = $envRow ? (float)$envRow['breakthrough_bonus_pct'] : 0.0;

            $levelExtra = $unlocked ? max(0, $caveLevel - 1) : 0;
            $levelCult = $unlocked ? $levelExtra * self::LEVEL_CULTIVATION_PER_LEVEL : 0.0;
            $levelBreak = $unlocked ? $levelExtra * self::LEVEL_BREAKTHROUGH_PER_LEVEL : 0.0;

            $formCult = 0.0;
            $formBreak = 0.0;
            $equippedMeta = [];
            $byKey = [];
            foreach ($catalog as $c) {
                $byKey[(string)$c['formation_key']] = $c;
            }
            foreach ($slots as $slotNum => $fkey) {
                if ($fkey === null || !isset($byKey[$fkey])) {
                    continue;
                }
                $c = $byKey[$fkey];
                $formCult += (float)$c['cultivation_bonus_pct'];
                $formBreak += (float)$c['breakthrough_bonus_pct'];
                $equippedMeta[] = [
                    'slot' => $slotNum,
                    'formation_key' => $fkey,
                    'display_name' => (string)$c['display_name'],
                    'cultivation_bonus_pct' => (float)$c['cultivation_bonus_pct'],
                    'breakthrough_bonus_pct' => (float)$c['breakthrough_bonus_pct'],
                ];
            }

            $totalCult = $unlocked ? $levelCult + $envCult + $formCult : 0.0;
            $totalBreak = $unlocked ? $levelBreak + $envBreak + $formBreak : 0.0;

            $nextUpgrade = null;
            if ($unlocked && $caveLevel < self::MAX_CAVE_LEVEL) {
                $nextUpgrade = $this->upgradeCostForNextLevel($caveLevel);
            }

            return [
                'available' => true,
                'unlocked' => $unlocked,
                'cave_level' => $unlocked ? $caveLevel : 0,
                'environment_key' => $envKey,
                'environment' => $envRow,
                'formations_equipped' => $equippedMeta,
                'level_cultivation_bonus' => $levelCult,
                'level_breakthrough_bonus' => $levelBreak,
                'environment_cultivation_bonus' => $unlocked ? $envCult : 0.0,
                'environment_breakthrough_bonus' => $unlocked ? $envBreak : 0.0,
                'formations_cultivation_bonus' => $unlocked ? $formCult : 0.0,
                'formations_breakthrough_bonus' => $unlocked ? $formBreak : 0.0,
                'total_cultivation_bonus' => $totalCult,
                'total_breakthrough_bonus' => $totalBreak,
                'unlock_cost_gold' => self::UNLOCK_GOLD,
                'unlock_cost_spirit_stones' => self::UNLOCK_SPIRIT_STONES,
                'next_upgrade' => $nextUpgrade,
                'environments' => $environments,
                'formation_catalog' => $catalog,
                'slots' => $slots,
            ];
        } catch (PDOException $e) {
            error_log('CaveService::getCaveState ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * @return array{gold: int, spirit_stones: int, from_level: int, to_level: int}|null
     */
    private function upgradeCostForNextLevel(int $currentLevel): ?array
    {
        if ($currentLevel >= self::MAX_CAVE_LEVEL) {
            return null;
        }
        $to = $currentLevel + 1;
        $gold = 500 + $currentLevel * 150;
        $stones = 4 + (int)floor($currentLevel / 2);
        return [
            'gold' => $gold,
            'spirit_stones' => $stones,
            'from_level' => $currentLevel,
            'to_level' => $to,
        ];
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function unlock(int $userId): array
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Cultivation caves are not available on this realm yet.'];
            }
            $this->ensureRow($userId);
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT gold, spirit_stones FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if (!$u) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }
            $gold = (int)$u['gold'];
            $stones = (int)$u['spirit_stones'];

            $cstmt = $db->prepare('SELECT unlocked FROM player_caves WHERE user_id = ? FOR UPDATE');
            $cstmt->execute([$userId]);
            $cave = $cstmt->fetch();
            if (!$cave) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Cave record missing.'];
            }
            if ((int)$cave['unlocked'] === 1) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Your cultivation cave is already unlocked.'];
            }
            if ($gold < self::UNLOCK_GOLD || $stones < self::UNLOCK_SPIRIT_STONES) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Not enough resources. Need ' . self::UNLOCK_GOLD . ' gold and ' . self::UNLOCK_SPIRIT_STONES . ' spirit stones.',
                ];
            }

            $db->prepare('UPDATE users SET gold = gold - ?, spirit_stones = spirit_stones - ? WHERE id = ?')
                ->execute([self::UNLOCK_GOLD, self::UNLOCK_SPIRIT_STONES, $userId]);
            $db->prepare('UPDATE player_caves SET unlocked = 1, cave_level = 1, updated_at = NOW() WHERE user_id = ?')
                ->execute([$userId]);

            $db->commit();
            return ['success' => true, 'message' => 'You have claimed your cultivation cave. Qi gathers more readily within its walls.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('CaveService::unlock ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not unlock the cave.'];
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function upgradeLevel(int $userId): array
    {
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Cultivation caves are not available on this realm yet.'];
            }
            $this->ensureRow($userId);
            $db->beginTransaction();

            $cstmt = $db->prepare('SELECT unlocked, cave_level FROM player_caves WHERE user_id = ? FOR UPDATE');
            $cstmt->execute([$userId]);
            $cave = $cstmt->fetch();
            if (!$cave || (int)$cave['unlocked'] !== 1) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Unlock your cave first.'];
            }
            $level = (int)$cave['cave_level'];
            if ($level >= self::MAX_CAVE_LEVEL) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Your cave is already at maximum refinement.'];
            }
            $cost = $this->upgradeCostForNextLevel($level);
            if ($cost === null) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Cannot upgrade further.'];
            }

            $stmt = $db->prepare('SELECT gold, spirit_stones FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if (!$u) {
                $db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }
            if ((int)$u['gold'] < $cost['gold'] || (int)$u['spirit_stones'] < $cost['spirit_stones']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Need ' . $cost['gold'] . ' gold and ' . $cost['spirit_stones'] . ' spirit stones to upgrade.',
                ];
            }

            $db->prepare('UPDATE users SET gold = gold - ?, spirit_stones = spirit_stones - ? WHERE id = ?')
                ->execute([$cost['gold'], $cost['spirit_stones'], $userId]);
            $db->prepare('UPDATE player_caves SET cave_level = cave_level + 1, updated_at = NOW() WHERE user_id = ?')
                ->execute([$userId]);

            $db->commit();
            return [
                'success' => true,
                'message' => 'The cave deepens. Arrays and veins resonate at a higher tier (level ' . $cost['to_level'] . ').',
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('CaveService::upgradeLevel ' . $e->getMessage());
            return ['success' => false, 'error' => 'Upgrade failed.'];
        }
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function setEnvironment(int $userId, string $envKey): array
    {
        $envKey = trim($envKey);
        if ($envKey === '') {
            return ['success' => false, 'error' => 'Invalid environment.'];
        }
        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Cultivation caves are not available on this realm yet.'];
            }
            $this->ensureRow($userId);

            $chk = $db->prepare('SELECT 1 FROM cave_environments WHERE env_key = ? LIMIT 1');
            $chk->execute([$envKey]);
            if (!$chk->fetch()) {
                return ['success' => false, 'error' => 'Unknown environment type.'];
            }

            $cstmt = $db->prepare('SELECT unlocked FROM player_caves WHERE user_id = ? LIMIT 1');
            $cstmt->execute([$userId]);
            $cave = $cstmt->fetch();
            if (!$cave || (int)$cave['unlocked'] !== 1) {
                return ['success' => false, 'error' => 'Unlock your cave first.'];
            }

            $db->prepare('UPDATE player_caves SET environment_key = ?, updated_at = NOW() WHERE user_id = ?')
                ->execute([$envKey, $userId]);

            return ['success' => true, 'message' => 'The cave’s ambient qi shifts to match your chosen environment.'];
        } catch (PDOException $e) {
            error_log('CaveService::setEnvironment ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not change environment.'];
        }
    }

    /**
     * @param int $slot 1–3
     * @param string|null $formationKey null or '' clears slot
     * @return array{success: bool, message?: string, error?: string}
     */
    public function setFormationSlot(int $userId, int $slot, ?string $formationKey): array
    {
        if ($slot < 1 || $slot > 3) {
            return ['success' => false, 'error' => 'Invalid formation slot.'];
        }
        $formationKey = $formationKey !== null ? trim($formationKey) : null;
        if ($formationKey === '') {
            $formationKey = null;
        }

        try {
            $db = Database::getConnection();
            if (!$this->tablesAvailable($db)) {
                return ['success' => false, 'error' => 'Cultivation caves are not available on this realm yet.'];
            }
            $this->ensureRow($userId);

            $cstmt = $db->prepare('SELECT unlocked, cave_level FROM player_caves WHERE user_id = ? LIMIT 1');
            $cstmt->execute([$userId]);
            $cave = $cstmt->fetch();
            if (!$cave || (int)$cave['unlocked'] !== 1) {
                return ['success' => false, 'error' => 'Unlock your cave first.'];
            }
            $caveLevel = max(1, (int)$cave['cave_level']);

            if ($formationKey !== null) {
                $fstmt = $db->prepare(
                    'SELECT required_cave_level, display_name FROM cave_formations WHERE formation_key = ? LIMIT 1'
                );
                $fstmt->execute([$formationKey]);
                $f = $fstmt->fetch();
                if (!$f) {
                    return ['success' => false, 'error' => 'Unknown formation.'];
                }
                if ($caveLevel < (int)$f['required_cave_level']) {
                    return ['success' => false, 'error' => 'Your cave level is too low for this formation.'];
                }

                $other = $db->prepare(
                    'SELECT slot FROM player_cave_formations WHERE user_id = ? AND formation_key = ? AND slot <> ? LIMIT 1'
                );
                $other->execute([$userId, $formationKey, $slot]);
                if ($other->fetch()) {
                    return ['success' => false, 'error' => 'That formation is already active in another slot.'];
                }
            }

            $db->prepare(
                'INSERT INTO player_cave_formations (user_id, slot, formation_key) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE formation_key = VALUES(formation_key)'
            )->execute([$userId, $slot, $formationKey]);

            $msg = $formationKey === null
                ? 'Formation cleared from slot ' . $slot . '.'
                : 'Formation inscribed in slot ' . $slot . '.';

            return ['success' => true, 'message' => $msg];
        } catch (PDOException $e) {
            error_log('CaveService::setFormationSlot ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not update formation.'];
        }
    }
}
