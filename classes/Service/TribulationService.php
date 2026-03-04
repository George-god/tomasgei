<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Tribulation Service
 * 
 * Handles Heavenly Tribulation events during realm breakthroughs.
 * - 3-7 lightning strikes
 * - Percentage-based damage
 * - Defense, dodge, realm resistance applied
 * - Grants permanent blessings on success
 * - Stores complete tribulation logs
 */
class TribulationService
{
    private const MIN_STRIKES = 3;
    private const MAX_STRIKES = 7;
    private const BASE_DAMAGE_PERCENTAGE = 0.15; // 15% of max chi per strike
    private const DAMAGE_VARIANCE = 0.05; // ±5% variance
    private const DODGE_CHANCE_BASE = 0.10; // 10% base dodge chance
    private const REALM_RESISTANCE_MULTIPLIER = 0.05; // 5% damage reduction per realm level

    /**
     * Process Heavenly Tribulation
     * Triggered after successful breakthrough roll
     * 
     * @param int $userId User ID
     * @param int $realmIdBefore Current realm ID (before breakthrough)
     * @param int $realmIdAfter Target realm ID (after breakthrough)
     * @return array Tribulation result
     */
    public function processTribulation(int $userId, int $realmIdBefore, int $realmIdAfter): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Fetch user data with realm info
            $user = $this->fetchUserWithRealm($db, $userId);
            
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found.'
                ];
            }

            // Get current chi (should be at max from breakthrough)
            $currentChi = (int)$user['chi'];
            $maxChi = (int)$user['max_chi'];
            $realmLevel = (int)$user['realm_id'];

            // Determine number of lightning strikes (3-7 random)
            $strikesCount = mt_rand(self::MIN_STRIKES, self::MAX_STRIKES);

            // Process each lightning strike
            $strikes = [];
            $totalDamage = 0;
            $chiAfter = $currentChi;
            $survived = true;

            for ($i = 1; $i <= $strikesCount; $i++) {
                $strike = $this->processLightningStrike(
                    $chiAfter,
                    $maxChi,
                    $realmLevel,
                    (int)$user['defense']
                );

                $chiAfter = $strike['chi_after'];
                $totalDamage += $strike['damage_taken'];
                $strikes[] = $strike;

                // If chi reaches 0, tribulation fails
                if ($chiAfter <= 0) {
                    $survived = false;
                    break; // Stop processing remaining strikes
                }
            }

            // Insert tribulation record
            $tribulationId = $this->insertTribulation(
                $db,
                $userId,
                $realmIdBefore,
                $survived ? $realmIdAfter : null,
                $survived,
                $strikesCount,
                $totalDamage
            );

            // Insert strike logs
            $this->insertTribulationLogs($db, $tribulationId, $strikes);

            $blessingGranted = null;

            if ($survived) {
                // Update user realm and chi
                $updateSql = "UPDATE users SET realm_id = :realm_id, chi = :chi WHERE id = :id";
                $stmt = $db->prepare($updateSql);
                $stmt->execute([
                    ':realm_id' => $realmIdAfter,
                    ':chi' => $chiAfter,
                    ':id' => $userId
                ]);

                // Grant random blessing
                $blessingGranted = $this->grantRandomBlessing($db, $userId, $tribulationId);

                // Create success notification
                $notificationService = new NotificationService();
                $realmName = $this->getRealmName($db, $realmIdAfter);
                $notificationService->createNotification(
                    $userId,
                    'tribulation_success',
                    'Tribulation Survived!',
                    "Congratulations! You have successfully ascended to {$realmName}!",
                    $tribulationId,
                    'tribulation'
                );
            } else {
                // Tribulation failed - apply penalty
                $this->applyFailurePenalty($db, $userId, $chiAfter);

                // Create failure notification
                $notificationService = new NotificationService();
                $notificationService->createNotification(
                    $userId,
                    'tribulation_failure',
                    'Tribulation Failed',
                    'You failed to survive the Heavenly Tribulation. Your breakthrough was unsuccessful.',
                    $tribulationId,
                    'tribulation'
                );
            }

            $db->commit();

            return [
                'success' => $survived,
                'tribulation_id' => $tribulationId,
                'strikes_count' => $strikesCount,
                'strikes_processed' => count($strikes),
                'total_damage' => $totalDamage,
                'chi_before' => $currentChi,
                'chi_after' => $chiAfter,
                'survived' => $survived,
                'realm_ascended' => $survived,
                'new_realm_id' => $survived ? $realmIdAfter : null,
                'blessing_granted' => $blessingGranted,
                'strikes' => $strikes
            ];

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Tribulation processing failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Tribulation processing failed. Please try again.'
            ];
        }
    }

    /**
     * Process a single lightning strike
     * 
     * @param int $currentChi Current chi
     * @param int $maxChi Maximum chi
     * @param int $realmLevel Current realm level
     * @param int $defense Defense stat
     * @return array Strike result
     */
    private function processLightningStrike(
        int $currentChi,
        int $maxChi,
        int $realmLevel,
        int $defense
    ): array {
        // Calculate base damage (percentage of max chi)
        $baseDamage = (int)($maxChi * self::BASE_DAMAGE_PERCENTAGE);
        
        // Apply variance (±5%)
        $variance = (mt_rand(-100, 100) / 10000) * self::DAMAGE_VARIANCE;
        $damage = (int)($baseDamage * (1 + $variance));

        // Check for dodge
        $dodgeChance = self::DODGE_CHANCE_BASE + ($realmLevel * 0.01); // Higher realm = better dodge
        $isDodged = (mt_rand(1, 10000) / 100) <= ($dodgeChance * 100);

        if ($isDodged) {
            return [
                'strike_number' => 0, // Will be set by caller
                'damage_dealt' => $damage,
                'damage_after_defense' => 0,
                'was_dodged' => true,
                'chi_after' => $currentChi,
                'damage_taken' => 0
            ];
        }

        // Apply defense reduction
        // Defense reduces damage: damage * (1 - defense_factor)
        // Defense factor scales with defense stat
        $defenseFactor = min(0.5, $defense / 1000.0); // Max 50% damage reduction
        $damageAfterDefense = (int)($damage * (1 - $defenseFactor));

        // Apply realm resistance
        // Higher realm = more resistance
        $realmResistance = $realmLevel * self::REALM_RESISTANCE_MULTIPLIER;
        $realmResistanceFactor = min(0.3, $realmResistance); // Max 30% reduction
        $finalDamage = (int)($damageAfterDefense * (1 - $realmResistanceFactor));

        // Calculate chi after strike
        $chiAfter = max(0, $currentChi - $finalDamage);

        return [
            'strike_number' => 0, // Will be set by caller
            'damage_dealt' => $damage,
            'damage_after_defense' => $damageAfterDefense,
            'was_dodged' => false,
            'chi_after' => $chiAfter,
            'damage_taken' => $finalDamage
        ];
    }

    /**
     * Grant random permanent blessing to user
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @param int $tribulationId Tribulation ID
     * @return array|null Blessing data
     */
    private function grantRandomBlessing(\PDO $db, int $userId, int $tribulationId): ?array
    {
        // Available blessing types
        $blessingTypes = [
            ['type' => 'minor_attack', 'stat_type' => 'attack', 'percentage' => 1.0],
            ['type' => 'minor_defense', 'stat_type' => 'defense', 'percentage' => 1.0],
            ['type' => 'minor_chi', 'stat_type' => 'chi', 'percentage' => 1.0],
            ['type' => 'minor_all', 'stat_type' => 'all', 'percentage' => 0.5],
        ];

        // Random blessing
        $blessing = $blessingTypes[array_rand($blessingTypes)];

        // Insert blessing
        $sql = "INSERT INTO tribulation_blessings 
                (user_id, blessing_type, stat_type, bonus_percentage, tribulation_id) 
                VALUES (:user_id, :blessing_type, :stat_type, :bonus_percentage, :tribulation_id)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':blessing_type' => $blessing['type'],
            ':stat_type' => $blessing['stat_type'],
            ':bonus_percentage' => $blessing['percentage'],
            ':tribulation_id' => $tribulationId
        ]);

        return [
            'id' => (int)$db->lastInsertId(),
            'type' => $blessing['type'],
            'stat_type' => $blessing['stat_type'],
            'bonus_percentage' => $blessing['percentage']
        ];
    }

    /**
     * Apply failure penalty
     * Chi penalty and cooldown penalty
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @param int $chiAfter Chi after tribulation
     * @return void
     */
    private function applyFailurePenalty(\PDO $db, int $userId, int $chiAfter): void
    {
        // Set chi to 10% of max chi (penalty)
        $sql = "UPDATE users SET chi = GREATEST(1, max_chi * 0.10) WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
    }

    /**
     * Insert tribulation record
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @param int $realmIdBefore Realm ID before
     * @param int|null $realmIdAfter Realm ID after (null if failed)
     * @param bool $success Success status
     * @param int $strikesCount Number of strikes
     * @param int $totalDamage Total damage taken
     * @return int Tribulation ID
     */
    private function insertTribulation(
        \PDO $db,
        int $userId,
        int $realmIdBefore,
        ?int $realmIdAfter,
        bool $success,
        int $strikesCount,
        int $totalDamage
    ): int {
        $sql = "INSERT INTO tribulations 
                (user_id, realm_id_before, realm_id_after, success, strikes_count, damage_taken) 
                VALUES (:user_id, :realm_id_before, :realm_id_after, :success, :strikes_count, :damage_taken)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':realm_id_before' => $realmIdBefore,
            ':realm_id_after' => $realmIdAfter,
            ':success' => $success ? 1 : 0,
            ':strikes_count' => $strikesCount,
            ':damage_taken' => $totalDamage
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Insert tribulation logs
     * 
     * @param \PDO $db Database connection
     * @param int $tribulationId Tribulation ID
     * @param array $strikes Strike data
     * @return void
     */
    private function insertTribulationLogs(\PDO $db, int $tribulationId, array $strikes): void
    {
        $sql = "INSERT INTO tribulation_logs 
                (tribulation_id, strike_number, damage_dealt, damage_after_defense, was_dodged, chi_after) 
                VALUES (:tribulation_id, :strike_number, :damage_dealt, :damage_after_defense, :was_dodged, :chi_after)";
        $stmt = $db->prepare($sql);

        foreach ($strikes as $index => $strike) {
            $stmt->execute([
                ':tribulation_id' => $tribulationId,
                ':strike_number' => $index + 1,
                ':damage_dealt' => $strike['damage_dealt'],
                ':damage_after_defense' => $strike['damage_after_defense'],
                ':was_dodged' => $strike['was_dodged'] ? 1 : 0,
                ':chi_after' => $strike['chi_after']
            ]);
        }
    }

    /**
     * Fetch user with realm data
     * 
     * @param \PDO $db Database connection
     * @param int $userId User ID
     * @return array|null User data
     */
    private function fetchUserWithRealm(\PDO $db, int $userId): ?array
    {
        $sql = "SELECT u.*, r.name as realm_name 
                FROM users u 
                LEFT JOIN realms r ON u.realm_id = r.id 
                WHERE u.id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get realm name by ID
     * 
     * @param \PDO $db Database connection
     * @param int $realmId Realm ID
     * @return string Realm name
     */
    private function getRealmName(\PDO $db, int $realmId): string
    {
        $sql = "SELECT name FROM realms WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$realmId]);
        $realm = $stmt->fetch();
        return $realm ? $realm['name'] : 'Unknown Realm';
    }

    /**
     * Get tribulation history for a user
     * 
     * @param int $userId User ID
     * @param int $limit Number of records
     * @return array Tribulation history
     */
    public function getTribulationHistory(int $userId, int $limit = 10): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT t.*, 
                    r1.name as realm_before_name,
                    r2.name as realm_after_name
                    FROM tribulations t
                    LEFT JOIN realms r1 ON t.realm_id_before = r1.id
                    LEFT JOIN realms r2 ON t.realm_id_after = r2.id
                    WHERE t.user_id = ?
                    ORDER BY t.created_at DESC
                    LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get tribulation history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get tribulation logs for replay
     * 
     * @param int $tribulationId Tribulation ID
     * @return array Strike logs
     */
    public function getTribulationLogs(int $tribulationId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM tribulation_logs 
                    WHERE tribulation_id = ? 
                    ORDER BY strike_number ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$tribulationId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get tribulation logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user's total blessings
     * 
     * @param int $userId User ID
     * @return array Blessings summary
     */
    public function getUserBlessings(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT stat_type, SUM(bonus_percentage) as total_bonus, COUNT(*) as count
                    FROM tribulation_blessings
                    WHERE user_id = ?
                    GROUP BY stat_type";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get user blessings: " . $e->getMessage());
            return [];
        }
    }
}
