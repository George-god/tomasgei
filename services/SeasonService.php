<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/TitleService.php';
require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Seasonal rankings: PvP, world boss damage, cultivation, sect contribution → total_score.
 * Multiple category leaderboards; season end: ranks, rewards, seasonal titles, new season.
 */
class SeasonService
{
    public const CATEGORY_OVERALL = 'overall';
    public const CATEGORY_PVP = 'pvp';
    public const CATEGORY_BOSS = 'boss';
    public const CATEGORY_CULTIVATION = 'cultivation';
    public const CATEGORY_SECT = 'sect';

    private const PVP_POINTS_PER_WIN = 10;
    private const TITLE_SOVEREIGN = 13;
    private const TITLE_ASCENDANT = 14;
    private const TITLE_DUELIST = 15;
    private const TITLE_WORLDBREAKER = 16;
    private const TITLE_SAGE = 17;
    private const TITLE_ARBITER = 18;

    private const REWARD_TOP1_GOLD = 5000;
    private const REWARD_TOP1_STONES = 50;
    private const REWARD_TOP23_GOLD = 2500;
    private const REWARD_TOP23_STONES = 25;
    private const REWARD_TOP10_GOLD = 1000;
    private const REWARD_TOP10_STONES = 10;
    private const REWARD_PARTICIPATION_GOLD = 100;

    /**
     * PvP win → season PvP score.
     */
    public function onPvpWin(int $userId): void
    {
        $this->addPvpPoints($userId, self::PVP_POINTS_PER_WIN);
    }

    /**
     * World boss damage dealt this attack.
     */
    public function onWorldBossDamage(int $userId, int $damage): void
    {
        if ($damage < 1) {
            return;
        }
        $sid = $this->getActiveSeasonId();
        if ($sid === null) {
            return;
        }
        try {
            $db = Database::getConnection();
            $this->ensureRow($db, $sid, $userId);
            $stmt = $db->prepare("
                UPDATE season_rankings
                SET score_world_boss = score_world_boss + ?
                WHERE season_id = ? AND user_id = ?
            ");
            $stmt->execute([$damage, $sid, $userId]);
            $this->recalculateTotal($db, $sid, $userId);
        } catch (PDOException $e) {
            error_log('SeasonService::onWorldBossDamage ' . $e->getMessage());
        }
    }

    /**
     * Successful cultivation (chi gained this session).
     */
    public function onCultivation(int $userId, int $chiGained): void
    {
        $pts = max(1, (int)floor($chiGained / 10));
        $sid = $this->getActiveSeasonId();
        if ($sid === null) {
            return;
        }
        try {
            $db = Database::getConnection();
            $this->ensureRow($db, $sid, $userId);
            $stmt = $db->prepare("
                UPDATE season_rankings
                SET score_cultivation = score_cultivation + ?
                WHERE season_id = ? AND user_id = ?
            ");
            $stmt->execute([$pts, $sid, $userId]);
            $this->recalculateTotal($db, $sid, $userId);
        } catch (PDOException $e) {
            error_log('SeasonService::onCultivation ' . $e->getMessage());
        }
    }

    /**
     * Sect donation: contribution units (per 100 gold).
     */
    public function onSectContributionUnits(int $userId, int $units): void
    {
        if ($units < 1) {
            return;
        }
        $sid = $this->getActiveSeasonId();
        if ($sid === null) {
            return;
        }
        try {
            $db = Database::getConnection();
            $this->ensureRow($db, $sid, $userId);
            $stmt = $db->prepare("
                UPDATE season_rankings
                SET score_sect = score_sect + ?
                WHERE season_id = ? AND user_id = ?
            ");
            $stmt->execute([$units, $sid, $userId]);
            $this->recalculateTotal($db, $sid, $userId);
        } catch (PDOException $e) {
            error_log('SeasonService::onSectContributionUnits ' . $e->getMessage());
        }
    }

    private function addPvpPoints(int $userId, int $points): void
    {
        if ($points < 1) {
            return;
        }
        $sid = $this->getActiveSeasonId();
        if ($sid === null) {
            return;
        }
        try {
            $db = Database::getConnection();
            $this->ensureRow($db, $sid, $userId);
            $stmt = $db->prepare("
                UPDATE season_rankings
                SET score_pvp = score_pvp + ?
                WHERE season_id = ? AND user_id = ?
            ");
            $stmt->execute([$points, $sid, $userId]);
            $this->recalculateTotal($db, $sid, $userId);
        } catch (PDOException $e) {
            error_log('SeasonService::addPvpPoints ' . $e->getMessage());
        }
    }

    /**
     * @return array{season: ?array, category: string, leaderboard: array<int, array>, my_row: ?array}
     */
    public function getPageData(int $userId, string $category): array
    {
        $this->ensureActiveSeasonExists();
        $cat = $this->normalizeCategory($category);
        $season = $this->getActiveSeason();
        if ($season === null) {
            return [
                'season' => null,
                'category' => $cat,
                'leaderboard' => [],
                'my_row' => null,
            ];
        }
        $sid = (int)$season['id'];
        try {
            $db = Database::getConnection();
            $orderCol = $this->orderColumnForCategory($cat);
            $sql = "
                SELECT r.season_id, r.user_id, r.score_pvp, r.score_world_boss, r.score_cultivation, r.score_sect,
                       r.total_score, r.rank_overall, r.rank_pvp, r.rank_boss, r.rank_cultivation, r.rank_sect,
                       u.username
                FROM season_rankings r
                JOIN users u ON u.id = r.user_id
                WHERE r.season_id = ?
                ORDER BY r.`{$orderCol}` DESC, r.user_id ASC
                LIMIT 100
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$sid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $ranked = [];
            $pos = 1;
            foreach ($rows as $row) {
                $row['display_rank'] = $pos++;
                $ranked[] = $row;
            }

            $my = null;
            $stmt = $db->prepare("
                SELECT r.*, u.username
                FROM season_rankings r
                JOIN users u ON u.id = r.user_id
                WHERE r.season_id = ? AND r.user_id = ?
            ");
            $stmt->execute([$sid, $userId]);
            $my = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            return [
                'season' => $season,
                'category' => $cat,
                'leaderboard' => $ranked,
                'my_row' => $my,
            ];
        } catch (PDOException $e) {
            error_log('SeasonService::getPageData ' . $e->getMessage());
            return [
                'season' => $season,
                'category' => $cat,
                'leaderboard' => [],
                'my_row' => null,
            ];
        }
    }

    /**
     * End expired seasons: compute ranks, rewards, titles, then start a new season.
     *
     * @return array{ok: bool, processed: int, detail: array<int, mixed>, error?: string}
     */
    public function processEndedSeasons(): array
    {
        try {
            $db = Database::getConnection();
        } catch (\Throwable $e) {
            return ['ok' => false, 'processed' => 0, 'detail' => [], 'error' => $e->getMessage()];
        }

        $detail = [];
        $processed = 0;

        try {
            $db->beginTransaction();

            $stmt = $db->query("
                SELECT id, name, slug, weight_pvp, weight_boss, weight_cultivation, weight_sect
                FROM seasons
                WHERE status = 'active' AND ends_at < NOW()
                ORDER BY id ASC
                FOR UPDATE
            ");
            $ended = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($ended as $season) {
                $sid = (int)$season['id'];
                $this->assignAllRanks($db, $sid);
                $rewards = $this->distributeRewardsAndTitles($db, $sid, $season);
                $db->prepare("UPDATE seasons SET status = 'ended' WHERE id = ?")->execute([$sid]);
                $detail[] = ['season_id' => $sid, 'name' => $season['name'], 'rewards' => $rewards];
                $processed++;
            }

            $db->commit();
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('SeasonService::processEndedSeasons ' . $e->getMessage());
            return ['ok' => false, 'processed' => 0, 'detail' => [], 'error' => $e->getMessage()];
        }

        try {
            $this->ensureActiveSeasonExists();
        } catch (\Throwable $e) {
            error_log('SeasonService::ensureActiveSeasonExists ' . $e->getMessage());
        }

        return ['ok' => true, 'processed' => $processed, 'detail' => $detail];
    }

    public function getActiveSeason(): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT id, name, slug, starts_at, ends_at, status,
                       weight_pvp, weight_boss, weight_cultivation, weight_sect
                FROM seasons
                WHERE status = 'active' AND ends_at > NOW()
                ORDER BY id DESC
                LIMIT 1
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('SeasonService::getActiveSeason ' . $e->getMessage());
            return null;
        }
    }

    private function getActiveSeasonId(): ?int
    {
        $s = $this->getActiveSeason();
        return $s ? (int)$s['id'] : null;
    }

    /**
     * Create an active season if none exists (28-day default).
     */
    public function ensureActiveSeasonExists(): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT id FROM seasons WHERE status = 'active' AND ends_at > NOW() LIMIT 1");
            if ($stmt->fetch()) {
                return;
            }
            $name = 'Season ' . gmdate('Y-m-d');
            $slug = 'season-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $ins = $db->prepare("
                INSERT INTO seasons (name, slug, starts_at, ends_at, status, weight_pvp, weight_boss, weight_cultivation, weight_sect)
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 28 DAY), 'active', 1.000000, 0.010000, 1.000000, 5.000000)
            ");
            $ins->execute([$name, $slug]);
        } catch (PDOException $e) {
            error_log('SeasonService::ensureActiveSeasonExists ' . $e->getMessage());
        }
    }

    private function ensureRow(PDO $db, int $seasonId, int $userId): void
    {
        $stmt = $db->prepare("
            INSERT INTO season_rankings (season_id, user_id, score_pvp, score_world_boss, score_cultivation, score_sect, total_score)
            VALUES (?, ?, 0, 0, 0, 0, 0)
            ON DUPLICATE KEY UPDATE season_id = season_id
        ");
        $stmt->execute([$seasonId, $userId]);
    }

    private function recalculateTotal(PDO $db, int $seasonId, int $userId): void
    {
        $stmt = $db->prepare("
            SELECT r.score_pvp, r.score_world_boss, r.score_cultivation, r.score_sect,
                   s.weight_pvp, s.weight_boss, s.weight_cultivation, s.weight_sect
            FROM season_rankings r
            JOIN seasons s ON s.id = r.season_id
            WHERE r.season_id = ? AND r.user_id = ?
        ");
        $stmt->execute([$seasonId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $total = (int)floor(
            (float)$row['weight_pvp'] * (int)$row['score_pvp']
            + (float)$row['weight_boss'] * (int)$row['score_world_boss']
            + (float)$row['weight_cultivation'] * (int)$row['score_cultivation']
            + (float)$row['weight_sect'] * (int)$row['score_sect']
        );
        $db->prepare("UPDATE season_rankings SET total_score = ? WHERE season_id = ? AND user_id = ?")
            ->execute([$total, $seasonId, $userId]);
    }

    /**
     * @param array<string, mixed> $season Weights from seasons row
     * @return array<string, int>
     */
    private function distributeRewardsAndTitles(PDO $db, int $seasonId, array $season): array
    {
        $counts = ['gold' => 0];
        $titleSvc = new TitleService();
        $ns = new NotificationService();

        // Overall order
        $stmt = $db->prepare("
            SELECT user_id, total_score FROM season_rankings
            WHERE season_id = ?
            ORDER BY total_score DESC, user_id ASC
        ");
        $stmt->execute([$seasonId]);
        $overall = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rank = 1;
        foreach ($overall as $row) {
            $uid = (int)$row['user_id'];
            $ts = (int)$row['total_score'];
            if ($ts >= 1) {
                if ($rank === 1) {
                    $this->addGoldStones($db, $uid, self::REWARD_TOP1_GOLD, self::REWARD_TOP1_STONES);
                    $titleSvc->grantTitle($uid, self::TITLE_SOVEREIGN);
                    $counts['gold'] += self::REWARD_TOP1_GOLD;
                    $this->notifySeason($ns, $uid, 'Season champion', 'You placed 1st overall in ' . $season['name'] . ' and received rewards.');
                } elseif ($rank <= 3) {
                    $this->addGoldStones($db, $uid, self::REWARD_TOP23_GOLD, self::REWARD_TOP23_STONES);
                    $titleSvc->grantTitle($uid, self::TITLE_ASCENDANT);
                    $counts['gold'] += self::REWARD_TOP23_GOLD;
                    $this->notifySeason($ns, $uid, 'Top season finish', 'You placed in the top 3 overall in ' . $season['name'] . '.');
                } elseif ($rank <= 10) {
                    $this->addGoldStones($db, $uid, self::REWARD_TOP10_GOLD, self::REWARD_TOP10_STONES);
                    $counts['gold'] += self::REWARD_TOP10_GOLD;
                    $this->notifySeason($ns, $uid, 'Season top 10', 'You placed in the top 10 overall in ' . $season['name'] . '.');
                } else {
                    $this->addGoldStones($db, $uid, self::REWARD_PARTICIPATION_GOLD, 0);
                    $counts['gold'] += self::REWARD_PARTICIPATION_GOLD;
                }
            }
            $rank++;
        }

        // Category #1 titles
        $this->grantTopCategory($db, $seasonId, 'score_pvp', 'rank_pvp', self::TITLE_DUELIST, $titleSvc, $ns, $season['name'], 'PvP');
        $this->grantTopCategory($db, $seasonId, 'score_world_boss', 'rank_boss', self::TITLE_WORLDBREAKER, $titleSvc, $ns, $season['name'], 'World Boss damage');
        $this->grantTopCategory($db, $seasonId, 'score_cultivation', 'rank_cultivation', self::TITLE_SAGE, $titleSvc, $ns, $season['name'], 'Cultivation');
        $this->grantTopCategory($db, $seasonId, 'score_sect', 'rank_sect', self::TITLE_ARBITER, $titleSvc, $ns, $season['name'], 'Sect contribution');

        return $counts;
    }

    private function grantTopCategory(
        PDO $db,
        int $seasonId,
        string $scoreCol,
        string $rankCol,
        int $titleId,
        TitleService $titleSvc,
        NotificationService $ns,
        string $seasonName,
        string $label
    ): void {
        $allowed = ['score_pvp', 'score_world_boss', 'score_cultivation', 'score_sect'];
        $allowedRank = ['rank_pvp', 'rank_boss', 'rank_cultivation', 'rank_sect'];
        if (!in_array($scoreCol, $allowed, true) || !in_array($rankCol, $allowedRank, true)) {
            return;
        }
        $stmt = $db->prepare("
            SELECT user_id, {$scoreCol} AS sc FROM season_rankings
            WHERE season_id = ? AND {$scoreCol} > 0
            ORDER BY {$scoreCol} DESC, user_id ASC
            LIMIT 1
        ");
        $stmt->execute([$seasonId]);
        $top = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$top) {
            return;
        }
        $uid = (int)$top['user_id'];
        if ($titleSvc->grantTitle($uid, $titleId)) {
            $this->notifySeason($ns, $uid, 'Season category leader', 'You ranked 1st in ' . $label . ' for ' . $seasonName . '.');
        }
    }

    private function notifySeason(NotificationService $ns, int $userId, string $title, string $message): void
    {
        try {
            $ns->createNotification($userId, 'season_reward', $title, $message, null, 'season');
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function addGoldStones(PDO $db, int $userId, int $gold, int $stones): void
    {
        if ($gold < 1 && $stones < 1) {
            return;
        }
        $db->prepare("UPDATE users SET gold = gold + ?, spirit_stones = spirit_stones + ? WHERE id = ?")
            ->execute([$gold, $stones, $userId]);
    }

    private function assignAllRanks(PDO $db, int $seasonId): void
    {
        $this->assignRankColumn($db, $seasonId, 'total_score', 'rank_overall');
        $this->assignRankColumn($db, $seasonId, 'score_pvp', 'rank_pvp');
        $this->assignRankColumn($db, $seasonId, 'score_world_boss', 'rank_boss');
        $this->assignRankColumn($db, $seasonId, 'score_cultivation', 'rank_cultivation');
        $this->assignRankColumn($db, $seasonId, 'score_sect', 'rank_sect');
    }

    private function assignRankColumn(PDO $db, int $seasonId, string $scoreCol, string $rankCol): void
    {
        $allowed = [
            'total_score' => 'rank_overall',
            'score_pvp' => 'rank_pvp',
            'score_world_boss' => 'rank_boss',
            'score_cultivation' => 'rank_cultivation',
            'score_sect' => 'rank_sect',
        ];
        if (!isset($allowed[$scoreCol]) || $allowed[$scoreCol] !== $rankCol) {
            return;
        }
        $stmt = $db->prepare("
            SELECT user_id FROM season_rankings
            WHERE season_id = ?
            ORDER BY {$scoreCol} DESC, user_id ASC
        ");
        $stmt->execute([$seasonId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $r = 1;
        $upd = $db->prepare("UPDATE season_rankings SET {$rankCol} = ? WHERE season_id = ? AND user_id = ?");
        foreach ($rows as $uid) {
            $upd->execute([$r, $seasonId, (int)$uid]);
            $r++;
        }
    }

    private function normalizeCategory(string $c): string
    {
        $c = strtolower(trim($c));
        $map = [
            self::CATEGORY_OVERALL => self::CATEGORY_OVERALL,
            'overall' => self::CATEGORY_OVERALL,
            self::CATEGORY_PVP => self::CATEGORY_PVP,
            'pvp' => self::CATEGORY_PVP,
            self::CATEGORY_BOSS => self::CATEGORY_BOSS,
            'boss' => self::CATEGORY_BOSS,
            'world_boss' => self::CATEGORY_BOSS,
            self::CATEGORY_CULTIVATION => self::CATEGORY_CULTIVATION,
            'cultivation' => self::CATEGORY_CULTIVATION,
            self::CATEGORY_SECT => self::CATEGORY_SECT,
            'sect' => self::CATEGORY_SECT,
        ];
        return $map[$c] ?? self::CATEGORY_OVERALL;
    }

    private function orderColumnForCategory(string $cat): string
    {
        switch ($cat) {
            case self::CATEGORY_PVP:
                return 'score_pvp';
            case self::CATEGORY_BOSS:
                return 'score_world_boss';
            case self::CATEGORY_CULTIVATION:
                return 'score_cultivation';
            case self::CATEGORY_SECT:
                return 'score_sect';
            default:
                return 'total_score';
        }
    }
}
