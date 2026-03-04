<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Challenge service for asynchronous PvP challenges
 * 
 * Features:
 * - Rating-range matchmaking (±150 rating)
 * - Anti-farming protections (limit challenges per opponent per hour)
 * - Server-side validation
 */
class ChallengeService
{
    private const CHALLENGE_EXPIRY_HOURS = 24;
    private const RATING_RANGE = 150; // ±150 rating matchmaking
    private const MAX_CHALLENGES_PER_OPPONENT_PER_HOUR = 3; // Anti-farming limit

    /**
     * Create a new battle challenge
     * 
     * @param int $challengerId Challenger user ID
     * @param int $defenderId Defender user ID
     * @return array Result with success status and challenge ID
     */
    public function createChallenge(int $challengerId, int $defenderId): array
    {
        // Validate users
        if ($challengerId === $defenderId) {
            return [
                'success' => false,
                'error' => 'You cannot challenge yourself.'
            ];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Fetch both users with ratings
            $challenger = $this->fetchUserWithRating($db, $challengerId);
            $defender = $this->fetchUserWithRating($db, $defenderId);

            if (!$challenger) {
                return [
                    'success' => false,
                    'error' => 'Challenger not found.'
                ];
            }

            if (!$defender) {
                return [
                    'success' => false,
                    'error' => 'Defender not found.'
                ];
            }

            // Validate rating range (±150) - SERVER-SIDE VALIDATION
            $challengerRating = (float)$challenger['rating'];
            $defenderRating = (float)$defender['rating'];
            $ratingDiff = abs($challengerRating - $defenderRating);

            if ($ratingDiff > self::RATING_RANGE) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => "Rating difference too large. You can only challenge players within ±{$this->getRatingRange()} rating.",
                    'challenger_rating' => $challengerRating,
                    'defender_rating' => $defenderRating,
                    'rating_difference' => $ratingDiff,
                    'max_allowed_difference' => self::RATING_RANGE
                ];
            }

            // Check anti-farming protection
            $farmingCheck = $this->checkFarmingProtection($db, $challengerId, $defenderId);
            if (!$farmingCheck['allowed']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => $farmingCheck['error'],
                    'challenges_remaining' => $farmingCheck['challenges_remaining'] ?? 0,
                    'cooldown_remaining' => $farmingCheck['cooldown_remaining'] ?? 0
                ];
            }

            // PvP stamina: prevent creating challenge if challenger has no stamina
            $staminaService = new PvpStaminaService();
            $staminaService->regenIfNeeded($challengerId);
            if (!$staminaService->canFight($challengerId)) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Not enough PvP stamina. Regenerate 1 every 30 minutes (max 5).'
                ];
            }

            // Check for existing pending challenge
            $stmt = $db->prepare("SELECT id FROM battle_challenges 
                                 WHERE challenger_id = ? AND defender_id = ? AND status = 'pending' 
                                 LIMIT 1");
            $stmt->execute([$challengerId, $defenderId]);
            if ($stmt->fetch()) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'You already have a pending challenge with this player.'
                ];
            }

            // Create challenge
            $expiresAt = date('Y-m-d H:i:s', time() + (self::CHALLENGE_EXPIRY_HOURS * 3600));
            $sql = "INSERT INTO battle_challenges (challenger_id, defender_id, expires_at) 
                    VALUES (:challenger_id, :defender_id, :expires_at)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':challenger_id' => $challengerId,
                ':defender_id' => $defenderId,
                ':expires_at' => $expiresAt
            ]);

            $challengeId = (int)$db->lastInsertId();

            // Update rate limiting
            $this->updateRateLimit($db, $challengerId, $defenderId);

            // Create notification for defender
            $notificationService = new NotificationService();
            $notificationService->createNotification(
                $defenderId,
                'battle_challenge',
                'Battle Challenge',
                "You have received a battle challenge from {$challenger['username']}!",
                $challengeId,
                'battle_challenge'
            );

            $db->commit();

            return [
                'success' => true,
                'challenge_id' => $challengeId,
                'rating_difference' => $ratingDiff
            ];

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Challenge creation failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to create challenge. Please try again.'
            ];
        }
    }

    /**
     * Check anti-farming protection
     * Limits challenges per opponent per hour
     * 
     * @param \PDO $db Database connection
     * @param int $challengerId Challenger ID
     * @param int $defenderId Defender ID
     * @return array Farming check result
     */
    private function checkFarmingProtection(\PDO $db, int $challengerId, int $defenderId): array
    {
        $currentTime = time();
        $hourAgo = $currentTime - 3600; // 1 hour ago

        // Check existing rate limit record
        $stmt = $db->prepare("SELECT * FROM challenge_rate_limits 
                             WHERE challenger_id = ? AND defender_id = ? 
                             ORDER BY hour_window_start DESC LIMIT 1");
        $stmt->execute([$challengerId, $defenderId]);
        $rateLimit = $stmt->fetch();

        if ($rateLimit) {
            $windowStart = strtotime($rateLimit['hour_window_start']);
            
            // Check if still in same hour window
            if ($currentTime < ($windowStart + 3600)) {
                $challengeCount = (int)$rateLimit['challenge_count'];
                
                if ($challengeCount >= self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR) {
                    $cooldownRemaining = ($windowStart + 3600) - $currentTime;
                    return [
                        'allowed' => false,
                        'error' => "You have reached the maximum challenges per hour for this opponent. Please wait " . ceil($cooldownRemaining / 60) . " minutes.",
                        'challenges_remaining' => 0,
                        'cooldown_remaining' => $cooldownRemaining
                    ];
                }
                
                return [
                    'allowed' => true,
                    'challenges_remaining' => self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR - $challengeCount
                ];
            }
        }

        // No rate limit or new hour window
        return [
            'allowed' => true,
            'challenges_remaining' => self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR
        ];
    }

    /**
     * Update rate limit record
     * 
     * @param \PDO $db Database connection
     * @param int $challengerId Challenger ID
     * @param int $defenderId Defender ID
     * @return void
     */
    private function updateRateLimit(\PDO $db, int $challengerId, int $defenderId): void
    {
        $currentTime = time();
        $hourWindowStart = date('Y-m-d H:i:s', floor($currentTime / 3600) * 3600); // Round to hour

        // Check if record exists for this hour window
        $stmt = $db->prepare("SELECT * FROM challenge_rate_limits 
                             WHERE challenger_id = ? AND defender_id = ? 
                             AND hour_window_start = ? LIMIT 1");
        $stmt->execute([$challengerId, $defenderId, $hourWindowStart]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing record
            $stmt = $db->prepare("UPDATE challenge_rate_limits 
                                 SET challenge_count = challenge_count + 1
                                 WHERE challenger_id = ? AND defender_id = ? 
                                 AND hour_window_start = ?");
            $stmt->execute([$challengerId, $defenderId, $hourWindowStart]);
        } else {
            // Create new record
            $stmt = $db->prepare("INSERT INTO challenge_rate_limits 
                                 (challenger_id, defender_id, challenge_count, hour_window_start) 
                                 VALUES (?, ?, 1, ?)");
            $stmt->execute([$challengerId, $defenderId, $hourWindowStart]);
        }
    }

    /**
     * Get available opponents within rating range
     * 
     * @param int $userId User ID
     * @param int $limit Number of opponents to return
     * @return array List of available opponents
     */
    public function getAvailableOpponents(int $userId, int $limit = 20): array
    {
        try {
            $db = Database::getConnection();
            
            // Get user's rating
            $user = $this->fetchUserWithRating($db, $userId);
            if (!$user) {
                return [];
            }

            $userRating = (float)$user['rating'];
            $minRating = $userRating - self::RATING_RANGE;
            $maxRating = $userRating + self::RATING_RANGE;

            // Get opponents within rating range
            $sql = "SELECT u.id, u.username, u.realm_id, u.level, u.rating, u.wins, u.losses,
                    r.name as realm_name
                    FROM users u
                    LEFT JOIN realms r ON u.realm_id = r.id
                    WHERE u.id != ?
                    AND u.rating >= ?
                    AND u.rating <= ?
                    ORDER BY ABS(u.rating - ?) ASC
                    LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $minRating, $maxRating, $userRating, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get available opponents: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Accept a battle challenge and simulate battle
     * 
     * @param int $challengeId Challenge ID
     * @param int $defenderId Defender user ID (must match challenge)
     * @return array Result with battle details
     */
    public function acceptChallenge(int $challengeId, int $defenderId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Fetch challenge
            $stmt = $db->prepare("SELECT * FROM battle_challenges WHERE id = ? AND defender_id = ? LIMIT 1");
            $stmt->execute([$challengeId, $defenderId]);
            $challenge = $stmt->fetch();

            if (!$challenge) {
                return [
                    'success' => false,
                    'error' => 'Challenge not found or you are not the defender.'
                ];
            }

            if ($challenge['status'] !== 'pending') {
                return [
                    'success' => false,
                    'error' => 'Challenge is no longer pending.'
                ];
            }

            // Check if expired
            if (strtotime($challenge['expires_at']) < time()) {
                $this->expireChallenge($db, $challengeId);
                return [
                    'success' => false,
                    'error' => 'Challenge has expired.'
                ];
            }

            // Re-validate rating range (in case ratings changed)
            $challenger = $this->fetchUserWithRating($db, (int)$challenge['challenger_id']);
            $defender = $this->fetchUserWithRating($db, $defenderId);

            if ($challenger && $defender) {
                $ratingDiff = abs((float)$challenger['rating'] - (float)$defender['rating']);
                if ($ratingDiff > self::RATING_RANGE) {
                    $this->expireChallenge($db, $challengeId);
                    return [
                        'success' => false,
                        'error' => 'Rating difference is now too large. Challenge expired.'
                    ];
                }
            }

            // Update challenge status
            $stmt = $db->prepare("UPDATE battle_challenges SET status = 'accepted', responded_at = NOW() WHERE id = ?");
            $stmt->execute([$challengeId]);

            $challengerId = (int)$challenge['challenger_id'];

            // Deduct 1 PvP stamina from challenger (each fight costs 1)
            $staminaService = new PvpStaminaService();
            $staminaService->regenIfNeeded($challengerId);
            $deduct = $staminaService->deductStamina($challengerId);
            if (!$deduct['success']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => $deduct['message'] ?? 'Not enough PvP stamina.'
                ];
            }

            // Simulate battle
            $battleService = new BattleService();
            $battleResult = $battleService->simulateBattle($challengerId, $defenderId);

            if (!$battleResult['success']) {
                $db->rollBack();
                return $battleResult;
            }

            // Mark challenge as completed
            $stmt = $db->prepare("UPDATE battle_challenges SET status = 'completed' WHERE id = ?");
            $stmt->execute([$challengeId]);

            // Create notifications
            $notificationService = new NotificationService();
            $winnerId = $battleResult['winner_id'];
            
            // Notify challenger
            $notificationService->createNotification(
                $challengerId,
                'battle_result',
                'Battle Result',
                $winnerId === $challengerId ? 'You won the battle!' : 'You lost the battle.',
                $battleResult['battle_id'],
                'battle'
            );

            // Notify defender
            $notificationService->createNotification(
                $defenderId,
                'battle_result',
                'Battle Result',
                $winnerId === $defenderId ? 'You won the battle!' : 'You lost the battle.',
                $battleResult['battle_id'],
                'battle'
            );

            $db->commit();

            return [
                'success' => true,
                'battle_id' => $battleResult['battle_id'],
                'winner_id' => $battleResult['winner_id'],
                'attacker_chi_loss' => $battleResult['attacker_chi_loss'] ?? 0,
                'defender_chi_loss' => $battleResult['defender_chi_loss'] ?? 0
            ];

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Challenge acceptance failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to accept challenge. Please try again.'
            ];
        }
    }

    /**
     * Decline a battle challenge
     * 
     * @param int $challengeId Challenge ID
     * @param int $defenderId Defender user ID
     * @return array Result
     */
    public function declineChallenge(int $challengeId, int $defenderId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("UPDATE battle_challenges 
                                 SET status = 'declined', responded_at = NOW() 
                                 WHERE id = ? AND defender_id = ? AND status = 'pending'");
            $stmt->execute([$challengeId, $defenderId]);

            if ($stmt->rowCount() === 0) {
                return [
                    'success' => false,
                    'error' => 'Challenge not found or already processed.'
                ];
            }

            return ['success' => true];

        } catch (PDOException $e) {
            error_log("Challenge decline failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to decline challenge.'
            ];
        }
    }

    /**
     * Get pending challenges for a user
     * 
     * @param int $userId User ID
     * @return array List of challenges
     */
    public function getPendingChallenges(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT bc.*, 
                    u1.username as challenger_username,
                    u1.rating as challenger_rating,
                    u2.username as defender_username,
                    u2.rating as defender_rating
                    FROM battle_challenges bc
                    LEFT JOIN users u1 ON bc.challenger_id = u1.id
                    LEFT JOIN users u2 ON bc.defender_id = u2.id
                    WHERE (bc.challenger_id = ? OR bc.defender_id = ?) 
                    AND bc.status = 'pending'
                    ORDER BY bc.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to fetch challenges: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get challenge rate limit status for a user-opponent pair
     * 
     * @param int $challengerId Challenger ID
     * @param int $defenderId Defender ID
     * @return array Rate limit status
     */
    public function getRateLimitStatus(int $challengerId, int $defenderId): array
    {
        try {
            $db = Database::getConnection();
            $currentTime = time();
            $hourWindowStart = date('Y-m-d H:i:s', floor($currentTime / 3600) * 3600);

            $stmt = $db->prepare("SELECT * FROM challenge_rate_limits 
                                 WHERE challenger_id = ? AND defender_id = ? 
                                 AND hour_window_start = ? LIMIT 1");
            $stmt->execute([$challengerId, $defenderId, $hourWindowStart]);
            $rateLimit = $stmt->fetch();

            if ($rateLimit) {
                $challengeCount = (int)$rateLimit['challenge_count'];
                $windowStart = strtotime($rateLimit['hour_window_start']);
                $cooldownRemaining = max(0, ($windowStart + 3600) - $currentTime);

                return [
                    'challenges_used' => $challengeCount,
                    'challenges_remaining' => max(0, self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR - $challengeCount),
                    'cooldown_remaining' => $cooldownRemaining,
                    'can_challenge' => $challengeCount < self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR
                ];
            }

            return [
                'challenges_used' => 0,
                'challenges_remaining' => self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR,
                'cooldown_remaining' => 0,
                'can_challenge' => true
            ];
        } catch (PDOException $e) {
            error_log("Failed to get rate limit status: " . $e->getMessage());
            return [
                'challenges_used' => 0,
                'challenges_remaining' => self::MAX_CHALLENGES_PER_OPPONENT_PER_HOUR,
                'cooldown_remaining' => 0,
                'can_challenge' => true
            ];
        }
    }

    /**
     * Fetch user with rating
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @return array|null User data
     */
    private function fetchUserWithRating(\PDO $db, int $userId): ?array
    {
        $sql = "SELECT id, username, rating FROM users WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Expire old challenges
     * 
     * @param \PDO $db Database connection
     * @param int $challengeId Challenge ID
     * @return void
     */
    private function expireChallenge(\PDO $db, int $challengeId): void
    {
        $stmt = $db->prepare("UPDATE battle_challenges SET status = 'expired' WHERE id = ?");
        $stmt->execute([$challengeId]);
    }

    /**
     * Get rating range constant
     * 
     * @return int Rating range
     */
    public function getRatingRange(): int
    {
        return self::RATING_RANGE;
    }

    /**
     * Clean up old rate limit records (older than 24 hours)
     * Can be called periodically via cron
     * 
     * @return int Number of records deleted
     */
    public function cleanupOldRateLimits(): int
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM challenge_rate_limits 
                                 WHERE hour_window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Failed to cleanup rate limits: " . $e->getMessage());
            return 0;
        }
    }
}
