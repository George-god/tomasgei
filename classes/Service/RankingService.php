<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Ranking service for ELO-based ranking system
 * 
 * ELO Formula:
 * Expected Score = 1 / (1 + 10^((opponent_rating - player_rating) / 400))
 * Rating Change = K * (actual_score - expected_score)
 * K-factor = 32 (standard for competitive games)
 */
class RankingService
{
    private const K_FACTOR = 32; // Standard K-factor for competitive games

    /**
     * Calculate ELO rating change after a battle
     * 
     * @param float $playerRating Player's current rating
     * @param float $opponentRating Opponent's current rating
     * @param bool $playerWon True if player won
     * @return array Rating changes for both players
     */
    public function calculateRatingChange(
        float $playerRating,
        float $opponentRating,
        bool $playerWon
    ): array {
        // Calculate expected score for player
        $expectedScore = $this->calculateExpectedScore($playerRating, $opponentRating);
        
        // Actual score: 1 for win, 0 for loss
        $actualScore = $playerWon ? 1.0 : 0.0;
        
        // Calculate rating change
        $ratingChange = self::K_FACTOR * ($actualScore - $expectedScore);
        
        // Opponent's change is opposite
        $opponentChange = -$ratingChange;
        
        return [
            'attacker_change' => $ratingChange,
            'defender_change' => $opponentChange
        ];
    }

    /**
     * Calculate expected score using ELO formula
     * Expected Score = 1 / (1 + 10^((opponent_rating - player_rating) / 400))
     * 
     * @param float $playerRating Player's rating
     * @param float $opponentRating Opponent's rating
     * @return float Expected score (0.0 to 1.0)
     */
    private function calculateExpectedScore(float $playerRating, float $opponentRating): float
    {
        $ratingDiff = $opponentRating - $playerRating;
        $exponent = $ratingDiff / 400.0;
        $denominator = 1.0 + pow(10, $exponent);
        return 1.0 / $denominator;
    }

    /**
     * Get leaderboard
     * 
     * @param int $limit Number of players to return
     * @param int $offset Offset for pagination
     * @return array Leaderboard entries
     */
    public function getLeaderboard(int $limit = 100, int $offset = 0): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT u.id, u.username, u.realm_id, u.level, u.rating, 
                    u.wins, u.losses, r.name as realm_name
                    FROM users u
                    LEFT JOIN realms r ON u.realm_id = r.id
                    ORDER BY u.rating DESC, u.wins DESC
                    LIMIT ? OFFSET ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to fetch leaderboard: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user's rank position
     * 
     * @param int $userId User ID
     * @return int Rank position (1-based)
     */
    public function getUserRank(int $userId): int
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT COUNT(*) + 1 as rank
                    FROM users u1
                    WHERE u1.rating > (SELECT u2.rating FROM users u2 WHERE u2.id = ?)
                    OR (u1.rating = (SELECT u2.rating FROM users u2 WHERE u2.id = ?) 
                        AND u1.wins > (SELECT u2.wins FROM users u2 WHERE u2.id = ?))";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $userId, $userId]);
            $result = $stmt->fetch();
            return (int)($result['rank'] ?? 0);
        } catch (PDOException $e) {
            error_log("Failed to get user rank: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update rankings table (for season support)
     * 
     * @param int $userId User ID
     * @param int $seasonId Season ID (default 1)
     * @return bool Success status
     */
    public function updateRanking(int $userId, int $seasonId = 1): bool
    {
        try {
            $db = Database::getConnection();
            
            // Get user data
            $stmt = $db->prepare("SELECT rating, wins, losses FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return false;
            }
            
            // Get rank position
            $rankPosition = $this->getUserRank($userId);
            
            // Insert or update ranking
            $sql = "INSERT INTO rankings (user_id, rating, rank_position, season_id, wins, losses)
                    VALUES (:user_id, :rating, :rank_position, :season_id, :wins, :losses)
                    ON DUPLICATE KEY UPDATE
                    rating = :rating,
                    rank_position = :rank_position,
                    wins = :wins,
                    losses = :losses,
                    updated_at = NOW()";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':rating' => $user['rating'],
                ':rank_position' => $rankPosition,
                ':season_id' => $seasonId,
                ':wins' => $user['wins'],
                ':losses' => $user['losses']
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to update ranking: " . $e->getMessage());
            return false;
        }
    }
}
