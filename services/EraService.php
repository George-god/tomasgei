<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/../core/Cache.php';

use Game\Config\Database;
use Game\Core\Cache;
use PDOException;

/**
 * Era Service
 * 
 * Handles era cycle system:
 * - Time-limited eras
 * - Era end: snapshot rankings, reset ratings/territories/world pressure
 * - Preserve realms, levels, blessings
 * - Hall of Legends (historical achievements)
 */
class EraService
{
    /**
     * Get current active era
     * 
     * @return array|null Active era data
     */
    public function getCurrentEra(): ?array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM eras WHERE is_active = 1 LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Failed to get current era: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all eras
     * 
     * @param int $limit Number of eras to return
     * @return array List of eras
     */
    public function getAllEras(int $limit = 50): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM eras ORDER BY start_date DESC LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get eras: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get era by ID
     * 
     * @param int $eraId Era ID
     * @return array|null Era data
     */
    public function getEra(int $eraId): ?array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM eras WHERE id = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$eraId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Failed to get era: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new era
     * 
     * @param string $name Era name
     * @param string $description Era description
     * @param string $startDate Start date (Y-m-d H:i:s)
     * @param string $endDate End date (Y-m-d H:i:s)
     * @return array Result
     */
    public function createEra(
        string $name,
        string $description,
        string $startDate,
        string $endDate
    ): array {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Deactivate current era if exists
            $stmt = $db->prepare("UPDATE eras SET is_active = 0 WHERE is_active = 1");
            $stmt->execute();

            // Create new era
            $sql = "INSERT INTO eras (name, description, start_date, end_date, is_active) 
                    VALUES (:name, :description, :start_date, :end_date, 1)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);

            $eraId = (int)$db->lastInsertId();

            $db->commit();
            Cache::forgetByPrefix('era:');

            return [
                'success' => true,
                'era_id' => $eraId
            ];

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Era creation failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to create era.'
            ];
        }
    }

    /**
     * Process era end
     * Snapshot rankings, reset ratings/territories/world pressure
     * Preserve realms, levels, blessings
     * 
     * @param int $eraId Era ID to end
     * @return array Result
     */
    public function processEraEnd(int $eraId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $era = $this->getEra($eraId);
            if (!$era) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Era not found.'
                ];
            }

            // Snapshot player rankings
            $this->snapshotPlayerRankings($db, $eraId);

            // Snapshot sect rankings
            $this->snapshotSectRankings($db, $eraId);

            // Reset ratings (preserve base stats)
            $this->resetRatings($db);

            // Reset territories
            $this->resetTerritories($db);

            // Reset world pressure
            $this->resetWorldPressure($db);

            // Mark era as inactive
            $stmt = $db->prepare("UPDATE eras SET is_active = 0 WHERE id = ?");
            $stmt->execute([$eraId]);

            $db->commit();
            Cache::forgetByPrefix('era:');

            return [
                'success' => true,
                'era_id' => $eraId,
                'snapshots_created' => true,
                'resets_completed' => true
            ];

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Era end processing failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to process era end.'
            ];
        }
    }

    /**
     * Snapshot player rankings for an era
     * 
     * @param \PDO $db Database connection
     * @param int $eraId Era ID
     * @return void
     */
    private function snapshotPlayerRankings(\PDO $db, int $eraId): void
    {
        // Get all users with their current rankings
        $sql = "SELECT id, rating, wins, losses FROM users ORDER BY rating DESC, wins DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $rankPosition = 1;
        $insertSql = "INSERT INTO era_rankings 
                     (era_id, user_id, final_rating, rank_position, wins, losses) 
                     VALUES (?, ?, ?, ?, ?, ?)";
        $insertStmt = $db->prepare($insertSql);

        foreach ($users as $user) {
            $insertStmt->execute([
                $eraId,
                (int)$user['id'],
                (float)$user['rating'],
                $rankPosition,
                (int)$user['wins'],
                (int)$user['losses']
            ]);
            $rankPosition++;
        }
    }

    /**
     * Snapshot sect rankings for an era
     * 
     * @param \PDO $db Database connection
     * @param int $eraId Era ID
     * @return void
     */
    private function snapshotSectRankings(\PDO $db, int $eraId): void
    {
        // Get all sects with their stats
        $sql = "SELECT s.id, s.total_rating,
                COUNT(DISTINCT t.id) as territories_controlled,
                COUNT(DISTINCT CASE WHEN sw.winner_sect_id = s.id THEN sw.id END) as wars_won,
                COUNT(DISTINCT CASE WHEN sw.attacker_sect_id = s.id AND sw.winner_sect_id != s.id 
                                    OR sw.defender_sect_id = s.id AND sw.winner_sect_id != s.id 
                                    THEN sw.id END) as wars_lost
                FROM sects s
                LEFT JOIN territories t ON s.id = t.sect_id
                LEFT JOIN sect_wars sw ON (s.id = sw.attacker_sect_id OR s.id = sw.defender_sect_id)
                GROUP BY s.id
                ORDER BY s.total_rating DESC, territories_controlled DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $sects = $stmt->fetchAll();

        $rankPosition = 1;
        $insertSql = "INSERT INTO era_sect_rankings 
                     (era_id, sect_id, final_rating, rank_position, territories_controlled, wars_won, wars_lost) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $db->prepare($insertSql);

        foreach ($sects as $sect) {
            $insertStmt->execute([
                $eraId,
                (int)$sect['id'],
                (float)$sect['total_rating'],
                $rankPosition,
                (int)$sect['territories_controlled'],
                (int)$sect['wars_won'],
                (int)$sect['wars_lost']
            ]);
            $rankPosition++;
        }
    }

    /**
     * Reset ratings (preserve realms, levels, blessings)
     * 
     * @param \PDO $db Database connection
     * @return void
     */
    private function resetRatings(\PDO $db): void
    {
        // Reset ratings to default (1000.0)
        // Preserve wins/losses for historical reference, but reset rating
        $sql = "UPDATE users SET rating = 1000.00";
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Reset territories (make all neutral)
     * 
     * @param \PDO $db Database connection
     * @return void
     */
    private function resetTerritories(\PDO $db): void
    {
        $sql = "UPDATE territories SET sect_id = NULL, controlled_since = NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Reset world pressure (reset all realm influences)
     * 
     * @param \PDO $db Database connection
     * @return void
     */
    private function resetWorldPressure(\PDO $db): void
    {
        $sql = "UPDATE world_state SET 
                pressure_level = 0.0,
                influence_percentage = 0.0,
                stat_modifier_percentage = 0.0,
                cultivation_modifier_percentage = 0.0,
                tribulation_modifier_percentage = 0.0";
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Get Hall of Legends (top players from all eras)
     * 
     * @param int $limit Number of entries per category
     * @return array Hall of Legends data
     */
    public function getHallOfLegends(int $limit = 10): array
    {
        try {
            return Cache::remember('era:hall_of_legends:' . max(1, $limit), 60, function () use ($limit): array {
                $db = Database::getConnection();

                $topPlayers = $db->query("
                    SELECT er.*, u.username, e.name AS era_name, e.start_date, e.end_date
                    FROM era_rankings er
                    INNER JOIN users u ON er.user_id = u.id
                    INNER JOIN eras e ON er.era_id = e.id
                    ORDER BY er.final_rating DESC, er.wins DESC
                    LIMIT " . max(1, (int)$limit)
                )->fetchAll();

                $topWinners = $db->query("
                    SELECT er.*, u.username, e.name AS era_name
                    FROM era_rankings er
                    INNER JOIN users u ON er.user_id = u.id
                    INNER JOIN eras e ON er.era_id = e.id
                    ORDER BY er.wins DESC, er.final_rating DESC
                    LIMIT " . max(1, (int)$limit)
                )->fetchAll();

                $topSects = $db->query("
                    SELECT esr.*, s.name AS sect_name, e.name AS era_name
                    FROM era_sect_rankings esr
                    INNER JOIN sects s ON esr.sect_id = s.id
                    INNER JOIN eras e ON esr.era_id = e.id
                    ORDER BY esr.final_rating DESC, esr.territories_controlled DESC
                    LIMIT " . max(1, (int)$limit)
                )->fetchAll();

                $topTerritorySects = $db->query("
                    SELECT esr.*, s.name AS sect_name, e.name AS era_name
                    FROM era_sect_rankings esr
                    INNER JOIN sects s ON esr.sect_id = s.id
                    INNER JOIN eras e ON esr.era_id = e.id
                    ORDER BY esr.territories_controlled DESC, esr.final_rating DESC
                    LIMIT " . max(1, (int)$limit)
                )->fetchAll();

                return [
                    'top_players_by_rating' => $topPlayers ?: [],
                    'top_players_by_wins' => $topWinners ?: [],
                    'top_sects_by_rating' => $topSects ?: [],
                    'top_sects_by_territories' => $topTerritorySects ?: [],
                ];
            });
        } catch (PDOException $e) {
            error_log("Failed to get Hall of Legends: " . $e->getMessage());
            return [
                'top_players_by_rating' => [],
                'top_players_by_wins' => [],
                'top_sects_by_rating' => [],
                'top_sects_by_territories' => []
            ];
        }
    }

    /**
     * Get era rankings (player rankings for a specific era)
     * 
     * @param int $eraId Era ID
     * @param int $limit Number of entries
     * @return array Era rankings
     */
    public function getEraRankings(int $eraId, int $limit = 100): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT er.*, u.username, u.realm_id, u.level,
                    r.name as realm_name
                    FROM era_rankings er
                    INNER JOIN users u ON er.user_id = u.id
                    LEFT JOIN realms r ON u.realm_id = r.id
                    WHERE er.era_id = ?
                    ORDER BY er.rank_position ASC
                    LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$eraId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get era rankings: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get era sect rankings
     * 
     * @param int $eraId Era ID
     * @param int $limit Number of entries
     * @return array Era sect rankings
     */
    public function getEraSectRankings(int $eraId, int $limit = 50): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT esr.*, s.name as sect_name
                    FROM era_sect_rankings esr
                    INNER JOIN sects s ON esr.sect_id = s.id
                    WHERE esr.era_id = ?
                    ORDER BY esr.rank_position ASC
                    LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$eraId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get era sect rankings: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user's era history
     * 
     * @param int $userId User ID
     * @return array User's era rankings
     */
    public function getUserEraHistory(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT er.*, e.name as era_name, e.start_date, e.end_date
                    FROM era_rankings er
                    INNER JOIN eras e ON er.era_id = e.id
                    WHERE er.user_id = ?
                    ORDER BY e.start_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get user era history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sect's era history
     * 
     * @param int $sectId Sect ID
     * @return array Sect's era rankings
     */
    public function getSectEraHistory(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT esr.*, e.name as era_name, e.start_date, e.end_date
                    FROM era_sect_rankings esr
                    INNER JOIN eras e ON esr.era_id = e.id
                    WHERE esr.sect_id = ?
                    ORDER BY e.start_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$sectId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get sect era history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if era has ended
     * 
     * @param int $eraId Era ID
     * @return bool True if era has ended
     */
    public function hasEraEnded(int $eraId): bool
    {
        try {
            $era = $this->getEra($eraId);
            if (!$era) {
                return false;
            }

            $endDate = strtotime($era['end_date']);
            return $endDate < time();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get eras that need processing (ended but not processed)
     * 
     * @return array List of eras that need processing
     */
    public function getErasNeedingProcessing(): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT e.* FROM eras e
                    LEFT JOIN era_rankings er ON e.id = er.era_id
                    WHERE e.end_date < NOW()
                    AND er.id IS NULL
                    ORDER BY e.end_date ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get eras needing processing: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get current era time remaining
     * 
     * @return array|null Time remaining info
     */
    public function getEraTimeRemaining(): ?array
    {
        $era = $this->getCurrentEra();
        if (!$era) {
            return null;
        }

        $endDate = strtotime($era['end_date']);
        $currentTime = time();
        $remaining = max(0, $endDate - $currentTime);

        return [
            'era_id' => (int)$era['id'],
            'era_name' => $era['name'],
            'end_date' => $era['end_date'],
            'seconds_remaining' => $remaining,
            'days_remaining' => floor($remaining / 86400),
            'hours_remaining' => floor(($remaining % 86400) / 3600),
            'minutes_remaining' => floor(($remaining % 3600) / 60)
        ];
    }
}
