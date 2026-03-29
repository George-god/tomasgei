<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Player titles: unlock by stats, equip one at a time, small combat bonuses via StatCalculator.
 */
class TitleService
{
    /**
     * @return array{attack_pct: float, defense_pct: float, max_chi_pct: float}
     */
    public function getEquippedBonuses(int $userId): array
    {
        $defaults = ['attack_pct' => 0.0, 'defense_pct' => 0.0, 'max_chi_pct' => 0.0];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.bonus_attack_pct, t.bonus_defense_pct, t.bonus_max_chi_pct
                FROM users u
                JOIN titles t ON t.id = u.equipped_title_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $defaults;
            }
            return [
                'attack_pct' => (float)$row['bonus_attack_pct'],
                'defense_pct' => (float)$row['bonus_defense_pct'],
                'max_chi_pct' => (float)$row['bonus_max_chi_pct'],
            ];
        } catch (PDOException $e) {
            error_log('TitleService::getEquippedBonuses ' . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Equipped title row for character sheet UI.
     *
     * @return array{id: int, name: string, attack_pct: float, defense_pct: float, max_chi_pct: float}|null
     */
    public function getEquippedTitleDisplay(int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.id, t.name, t.bonus_attack_pct, t.bonus_defense_pct, t.bonus_max_chi_pct
                FROM users u
                JOIN titles t ON t.id = u.equipped_title_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'attack_pct' => (float)$row['bonus_attack_pct'],
                'defense_pct' => (float)$row['bonus_defense_pct'],
                'max_chi_pct' => (float)$row['bonus_max_chi_pct'],
            ];
        } catch (PDOException $e) {
            error_log('TitleService::getEquippedTitleDisplay ' . $e->getMessage());
            return null;
        }
    }

    public function onPvpWin(int $userId): void
    {
        $this->checkAndUnlockTitles($userId);
    }

    public function onPveWin(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET title_pve_wins = title_pve_wins + 1 WHERE id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            error_log('TitleService::onPveWin ' . $e->getMessage());
            return;
        }
        $this->checkAndUnlockTitles($userId);
    }

    public function onExplore(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET title_explore_count = title_explore_count + 1 WHERE id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            error_log('TitleService::onExplore ' . $e->getMessage());
            return;
        }
        $this->checkAndUnlockTitles($userId);
    }

    public function onBossAttack(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET title_boss_attacks = title_boss_attacks + 1 WHERE id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            error_log('TitleService::onBossAttack ' . $e->getMessage());
            return;
        }
        $this->checkAndUnlockTitles($userId);
    }

    public function onSectDonate(int $userId, int $goldAmount): void
    {
        if ($goldAmount < 1) {
            return;
        }
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET title_sect_donated_gold = title_sect_donated_gold + ? WHERE id = ?")
                ->execute([$goldAmount, $userId]);
        } catch (PDOException $e) {
            error_log('TitleService::onSectDonate ' . $e->getMessage());
            return;
        }
        $this->checkAndUnlockTitles($userId);
    }

    public function onTribulationSuccess(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET title_tribulations_won = title_tribulations_won + 1 WHERE id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            error_log('TitleService::onTribulationSuccess ' . $e->getMessage());
            return;
        }
        $this->checkAndUnlockTitles($userId);
    }

    /**
     * Unlock any titles whose threshold is met; notify on new unlock.
     */
    public function checkAndUnlockTitles(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT id, wins, title_pve_wins, title_explore_count, title_boss_attacks,
                       title_sect_donated_gold, title_tribulations_won
                FROM users WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                return;
            }

            $stmt = $db->prepare("
                SELECT t.id, t.slug, t.name, t.unlock_type, t.unlock_value
                FROM titles t
                WHERE t.unlock_type <> 'season_rank'
                  AND NOT EXISTS (
                    SELECT 1 FROM user_titles ut WHERE ut.user_id = ? AND ut.title_id = t.id
                )
            ");
            $stmt->execute([$userId]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insert = $db->prepare("INSERT INTO user_titles (user_id, title_id) VALUES (?, ?)");
            $ns = new NotificationService();

            foreach ($candidates as $t) {
                $type = (string)$t['unlock_type'];
                $need = (int)$t['unlock_value'];
                $have = $this->statForType($u, $type);
                if ($have < $need) {
                    continue;
                }
                $insert->execute([$userId, (int)$t['id']]);
                try {
                    $ns->createNotification(
                        $userId,
                        'title_unlock',
                        'Title Unlocked',
                        'You earned the title: ' . (string)$t['name'] . '. Visit Titles to equip it.',
                        (int)$t['id'],
                        'title'
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        } catch (PDOException $e) {
            error_log('TitleService::checkAndUnlockTitles ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $u User row with title stat columns
     */
    private function statForType(array $u, string $type): int
    {
        switch ($type) {
            case 'pvp_wins':
                return (int)($u['wins'] ?? 0);
            case 'pve_kills':
                return (int)($u['title_pve_wins'] ?? 0);
            case 'exploration':
                return (int)($u['title_explore_count'] ?? 0);
            case 'boss_participation':
                return (int)($u['title_boss_attacks'] ?? 0);
            case 'sect_contribution':
                return (int)($u['title_sect_donated_gold'] ?? 0);
            case 'tribulation_success':
                return (int)($u['title_tribulations_won'] ?? 0);
            case 'season_rank':
                return 0;
            default:
                return 0;
        }
    }

    /**
     * Grant a title (e.g. seasonal rewards). Ignores unlock_value; uses INSERT IGNORE.
     *
     * @return bool True if the title was newly granted
     */
    public function grantTitle(int $userId, int $titleId): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, name FROM titles WHERE id = ?");
            $stmt->execute([$titleId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                return false;
            }
            $ins = $db->prepare("INSERT IGNORE INTO user_titles (user_id, title_id) VALUES (?, ?)");
            $ins->execute([$userId, $titleId]);
            if ($ins->rowCount() < 1) {
                return false;
            }
            $ns = new NotificationService();
            $ns->createNotification(
                $userId,
                'title_unlock',
                'Title Unlocked',
                'You earned the title: ' . (string)$t['name'] . '. Visit Titles to equip it.',
                $titleId,
                'title'
            );
            return true;
        } catch (PDOException $e) {
            error_log('TitleService::grantTitle ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('TitleService::grantTitle ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function equipTitle(int $userId, ?int $titleId): array
    {
        if ($titleId === null || $titleId < 1) {
            try {
                $db = Database::getConnection();
                $db->prepare("UPDATE users SET equipped_title_id = NULL WHERE id = ?")->execute([$userId]);
                return ['success' => true, 'message' => 'Title unequipped.'];
            } catch (PDOException $e) {
                return ['success' => false, 'message' => 'Could not update title.'];
            }
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM user_titles WHERE user_id = ? AND title_id = ?");
            $stmt->execute([$userId, $titleId]);
            if (!$stmt->fetchColumn()) {
                return ['success' => false, 'message' => 'You have not unlocked this title.'];
            }
            $db->prepare("UPDATE users SET equipped_title_id = ? WHERE id = ?")->execute([$titleId, $userId]);
            return ['success' => true, 'message' => 'Title equipped.'];
        } catch (PDOException $e) {
            error_log('TitleService::equipTitle ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not equip title.'];
        }
    }

    /**
     * @return array{titles: array<int, array>, stats: array<string, int>, equipped_id: ?int}
     */
    public function getTitlesPageData(int $userId): array
    {
        $this->checkAndUnlockTitles($userId);

        $stats = [
            'pvp_wins' => 0,
            'pve_kills' => 0,
            'exploration' => 0,
            'boss_participation' => 0,
            'sect_contribution' => 0,
            'tribulation_success' => 0,
        ];
        $equippedId = null;
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT wins, title_pve_wins, title_explore_count, title_boss_attacks,
                       title_sect_donated_gold, title_tribulations_won, equipped_title_id
                FROM users WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $stats['pvp_wins'] = (int)$u['wins'];
                $stats['pve_kills'] = (int)$u['title_pve_wins'];
                $stats['exploration'] = (int)$u['title_explore_count'];
                $stats['boss_participation'] = (int)$u['title_boss_attacks'];
                $stats['sect_contribution'] = (int)$u['title_sect_donated_gold'];
                $stats['tribulation_success'] = (int)$u['title_tribulations_won'];
                $equippedId = isset($u['equipped_title_id']) && $u['equipped_title_id'] !== null
                    ? (int)$u['equipped_title_id']
                    : null;
            }

            $stmt = $db->query("SELECT * FROM titles ORDER BY sort_order ASC, id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $unlockedStmt = $db->prepare("SELECT title_id FROM user_titles WHERE user_id = ?");
            $unlockedStmt->execute([$userId]);
            $unlocked = [];
            while ($r = $unlockedStmt->fetch(PDO::FETCH_ASSOC)) {
                $unlocked[(int)$r['title_id']] = true;
            }

            $out = [];
            foreach ($rows as $row) {
                $tid = (int)$row['id'];
                $type = (string)$row['unlock_type'];
                $need = (int)$row['unlock_value'];
                if ($type === 'season_rank') {
                    $need = 1;
                    $have = isset($unlocked[$tid]) ? 1 : 0;
                } else {
                    $have = $stats[$type] ?? 0;
                }
                $out[] = [
                    'id' => $tid,
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'unlock_type' => $type,
                    'unlock_value' => $need,
                    'progress_current' => min($have, $need),
                    'progress_pct' => $need > 0 ? min(100, (int)round(100 * $have / $need)) : 0,
                    'unlocked' => isset($unlocked[$tid]),
                    'equipped' => $equippedId === $tid,
                    'bonus_attack_pct' => (float)$row['bonus_attack_pct'],
                    'bonus_defense_pct' => (float)$row['bonus_defense_pct'],
                    'bonus_max_chi_pct' => (float)$row['bonus_max_chi_pct'],
                ];
            }
            return ['titles' => $out, 'stats' => $stats, 'equipped_id' => $equippedId];
        } catch (PDOException $e) {
            error_log('TitleService::getTitlesPageData ' . $e->getMessage());
            return ['titles' => [], 'stats' => $stats, 'equipped_id' => null];
        }
    }
}
