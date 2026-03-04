<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Territory Service
 * 
 * Handles territory control system:
 * - Territory ownership
 * - Sect territory bonuses
 * - Global realm influence
 * - Territory effects (stat boosts, cultivation efficiency, tribulation resistance, realm pressure)
 */
class TerritoryService
{
    /**
     * Get all territories
     * 
     * @return array List of all territories
     */
    public function getAllTerritories(): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT t.*, 
                    s.name as sect_name,
                    s.id as sect_id,
                    r.name as realm_name
                    FROM territories t
                    LEFT JOIN sects s ON t.sect_id = s.id
                    LEFT JOIN realms r ON t.realm_id = r.id
                    ORDER BY t.realm_id ASC, t.name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get territories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get territory by ID
     * 
     * @param int $territoryId Territory ID
     * @return array|null Territory data
     */
    public function getTerritory(int $territoryId): ?array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT t.*, 
                    s.name as sect_name,
                    s.id as sect_id,
                    r.name as realm_name
                    FROM territories t
                    LEFT JOIN sects s ON t.sect_id = s.id
                    LEFT JOIN realms r ON t.realm_id = r.id
                    WHERE t.id = ?
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$territoryId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Failed to get territory: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get territories controlled by a sect
     * 
     * @param int $sectId Sect ID
     * @return array List of territories
     */
    public function getSectTerritories(int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT t.*, r.name as realm_name
                    FROM territories t
                    LEFT JOIN realms r ON t.realm_id = r.id
                    WHERE t.sect_id = ?
                    ORDER BY t.name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$sectId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get sect territories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get territories by realm
     * 
     * @param int $realmId Realm ID
     * @return array List of territories
     */
    public function getTerritoriesByRealm(int $realmId): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT t.*, 
                    s.name as sect_name,
                    s.id as sect_id
                    FROM territories t
                    LEFT JOIN sects s ON t.sect_id = s.id
                    WHERE t.realm_id = ?
                    ORDER BY t.name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$realmId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get territories by realm: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Claim territory for a sect
     * Usually done through sect war
     * 
     * @param int $territoryId Territory ID
     * @param int $sectId Sect ID
     * @return array Result
     */
    public function claimTerritory(int $territoryId, int $sectId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Verify territory exists
            $territory = $this->getTerritory($territoryId);
            if (!$territory) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Territory not found.'
                ];
            }

            // Verify sect exists
            $stmt = $db->prepare("SELECT id FROM sects WHERE id = ? LIMIT 1");
            $stmt->execute([$sectId]);
            if (!$stmt->fetch()) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Sect not found.'
                ];
            }

            // Update territory ownership
            $sql = "UPDATE territories SET sect_id = ?, controlled_since = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$sectId, $territoryId]);

            // Update global realm influence
            $this->updateGlobalRealmInfluence($db, (int)$territory['realm_id']);

            $db->commit();

            return [
                'success' => true,
                'territory_id' => $territoryId,
                'sect_id' => $sectId
            ];

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Claim territory failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to claim territory.'
            ];
        }
    }

    /**
     * Release territory (make it neutral)
     * 
     * @param int $territoryId Territory ID
     * @return array Result
     */
    public function releaseTerritory(int $territoryId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            // Get territory to find realm
            $territory = $this->getTerritory($territoryId);
            if (!$territory) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Territory not found.'
                ];
            }

            $realmId = (int)$territory['realm_id'];

            // Release territory
            $sql = "UPDATE territories SET sect_id = NULL, controlled_since = NULL WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$territoryId]);

            // Update global realm influence
            $this->updateGlobalRealmInfluence($db, $realmId);

            $db->commit();

            return [
                'success' => true,
                'territory_id' => $territoryId
            ];

        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Release territory failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to release territory.'
            ];
        }
    }

    /**
     * Get territory bonus for a user's sect
     * Sums all territory bonuses from user's sect
     * 
     * @param int $userId User ID
     * @return array Territory bonus data
     */
    public function getUserTerritoryBonus(int $userId): array
    {
        try {
            $db = Database::getConnection();
            
            // Get user's sect
            $stmt = $db->prepare("SELECT sect_id FROM sect_members WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $membership = $stmt->fetch();

            if (!$membership || !$membership['sect_id']) {
                return [
                    'stat_bonus' => 0.0,
                    'cultivation_bonus' => 0.0,
                    'tribulation_resistance' => 0.0,
                    'territories_count' => 0
                ];
            }

            $sectId = (int)$membership['sect_id'];

            // Get territory bonuses
            $sql = "SELECT 
                    SUM(stat_bonus_percentage) as total_stat_bonus,
                    SUM(cultivation_bonus_percentage) as total_cultivation_bonus,
                    SUM(tribulation_resistance_percentage) as total_tribulation_resistance,
                    COUNT(*) as territories_count
                    FROM territories
                    WHERE sect_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$sectId]);
            $result = $stmt->fetch();

            return [
                'stat_bonus' => (float)($result['total_stat_bonus'] ?? 0.0),
                'cultivation_bonus' => (float)($result['total_cultivation_bonus'] ?? 0.0),
                'tribulation_resistance' => (float)($result['total_tribulation_resistance'] ?? 0.0),
                'territories_count' => (int)($result['territories_count'] ?? 0)
            ];
        } catch (PDOException $e) {
            error_log("Failed to get user territory bonus: " . $e->getMessage());
            return [
                'stat_bonus' => 0.0,
                'cultivation_bonus' => 0.0,
                'tribulation_resistance' => 0.0,
                'territories_count' => 0
            ];
        }
    }

    /**
     * Get global realm influence for a realm
     * Calculates influence based on territory control distribution
     * 
     * @param int $realmId Realm ID
     * @return array Global influence data
     */
    public function getGlobalRealmInfluence(int $realmId): array
    {
        try {
            $db = Database::getConnection();
            
            // Get world state
            $stmt = $db->prepare("SELECT * FROM world_state WHERE realm_id = ? LIMIT 1");
            $stmt->execute([$realmId]);
            $worldState = $stmt->fetch();

            if (!$worldState) {
                // Initialize if doesn't exist
                $this->initializeWorldState($db, $realmId);
                return [
                    'influence_percentage' => 0.0,
                    'stat_modifier' => 0.0,
                    'cultivation_modifier' => 0.0,
                    'tribulation_modifier' => 0.0,
                    'pressure_level' => 0.0
                ];
            }

            return [
                'influence_percentage' => (float)$worldState['influence_percentage'],
                'stat_modifier' => (float)$worldState['stat_modifier_percentage'],
                'cultivation_modifier' => (float)$worldState['cultivation_modifier_percentage'],
                'tribulation_modifier' => (float)$worldState['tribulation_modifier_percentage'],
                'pressure_level' => (float)$worldState['pressure_level']
            ];
        } catch (PDOException $e) {
            error_log("Failed to get global realm influence: " . $e->getMessage());
            return [
                'influence_percentage' => 0.0,
                'stat_modifier' => 0.0,
                'cultivation_modifier' => 0.0,
                'tribulation_modifier' => 0.0,
                'pressure_level' => 0.0
            ];
        }
    }

    /**
     * Update global realm influence
     * Calculates influence based on territory control
     * 
     * @param \PDO $db Database connection
     * @param int $realmId Realm ID
     * @return void
     */
    private function updateGlobalRealmInfluence(\PDO $db, int $realmId): void
    {
        // Get total territories in realm
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM territories WHERE realm_id = ?");
        $stmt->execute([$realmId]);
        $totalResult = $stmt->fetch();
        $totalTerritories = (int)($totalResult['total'] ?? 0);

        if ($totalTerritories === 0) {
            // No territories, set influence to 0
            $this->updateWorldState($db, $realmId, 0.0, 0.0, 0.0, 0.0, 0.0);
            return;
        }

        // Get controlled territories count
        $stmt = $db->prepare("SELECT COUNT(*) as controlled FROM territories WHERE realm_id = ? AND sect_id IS NOT NULL");
        $stmt->execute([$realmId]);
        $controlledResult = $stmt->fetch();
        $controlledTerritories = (int)($controlledResult['controlled'] ?? 0);

        // Calculate influence percentage (0-100%)
        $influencePercentage = ($controlledTerritories / $totalTerritories) * 100.0;

        // Calculate modifiers based on influence
        // Higher influence = better modifiers
        // Max modifiers: 10% stat, 15% cultivation, 5% tribulation resistance
        $statModifier = ($influencePercentage / 100.0) * 10.0; // Up to 10%
        $cultivationModifier = ($influencePercentage / 100.0) * 15.0; // Up to 15%
        $tribulationModifier = ($influencePercentage / 100.0) * 5.0; // Up to 5%

        // Calculate realm pressure (inverse - more controlled = less pressure)
        // Pressure affects tribulation difficulty
        $pressureLevel = 100.0 - $influencePercentage; // 0-100, higher = more pressure

        $this->updateWorldState(
            $db,
            $realmId,
            $influencePercentage,
            $statModifier,
            $cultivationModifier,
            $tribulationModifier,
            $pressureLevel
        );
    }

    /**
     * Update world state for a realm
     * 
     * @param \PDO $db Database connection
     * @param int $realmId Realm ID
     * @param float $influencePercentage Influence percentage
     * @param float $statModifier Stat modifier percentage
     * @param float $cultivationModifier Cultivation modifier percentage
     * @param float $tribulationModifier Tribulation modifier percentage
     * @param float $pressureLevel Pressure level
     * @return void
     */
    private function updateWorldState(
        \PDO $db,
        int $realmId,
        float $influencePercentage,
        float $statModifier,
        float $cultivationModifier,
        float $tribulationModifier,
        float $pressureLevel
    ): void {
        $sql = "INSERT INTO world_state 
                (realm_id, pressure_level, influence_percentage, stat_modifier_percentage, 
                 cultivation_modifier_percentage, tribulation_modifier_percentage)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                pressure_level = ?,
                influence_percentage = ?,
                stat_modifier_percentage = ?,
                cultivation_modifier_percentage = ?,
                tribulation_modifier_percentage = ?,
                updated_at = NOW()";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $realmId,
            $pressureLevel,
            $influencePercentage,
            $statModifier,
            $cultivationModifier,
            $tribulationModifier,
            $pressureLevel,
            $influencePercentage,
            $statModifier,
            $cultivationModifier,
            $tribulationModifier
        ]);
    }

    /**
     * Initialize world state for a realm
     * 
     * @param \PDO $db Database connection
     * @param int $realmId Realm ID
     * @return void
     */
    private function initializeWorldState(\PDO $db, int $realmId): void
    {
        $sql = "INSERT INTO world_state 
                (realm_id, pressure_level, influence_percentage, stat_modifier_percentage, 
                 cultivation_modifier_percentage, tribulation_modifier_percentage)
                VALUES (?, 0.0, 0.0, 0.0, 0.0, 0.0)
                ON DUPLICATE KEY UPDATE realm_id = realm_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([$realmId]);
    }

    /**
     * Get territory map data for all realms
     * Useful for territory map page
     * 
     * @return array Territory map organized by realm
     */
    public function getTerritoryMap(): array
    {
        try {
            $db = Database::getConnection();
            
            // Get all realms
            $stmt = $db->prepare("SELECT id, name FROM realms ORDER BY id ASC");
            $stmt->execute();
            $realms = $stmt->fetchAll();

            $map = [];

            foreach ($realms as $realm) {
                $realmId = (int)$realm['id'];
                $territories = $this->getTerritoriesByRealm($realmId);
                $influence = $this->getGlobalRealmInfluence($realmId);

                $map[] = [
                    'realm' => [
                        'id' => $realmId,
                        'name' => $realm['name']
                    ],
                    'territories' => $territories,
                    'total_territories' => count($territories),
                    'controlled_territories' => count(array_filter($territories, fn($t) => $t['sect_id'] !== null)),
                    'global_influence' => $influence
                ];
            }

            return $map;
        } catch (PDOException $e) {
            error_log("Failed to get territory map: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get territory control statistics
     * 
     * @return array Statistics
     */
    public function getTerritoryStatistics(): array
    {
        try {
            $db = Database::getConnection();
            
            // Total territories
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM territories");
            $stmt->execute();
            $total = (int)($stmt->fetch()['total'] ?? 0);

            // Controlled territories
            $stmt = $db->prepare("SELECT COUNT(*) as controlled FROM territories WHERE sect_id IS NOT NULL");
            $stmt->execute();
            $controlled = (int)($stmt->fetch()['controlled'] ?? 0);

            // Neutral territories
            $neutral = $total - $controlled;

            // Territories by realm
            $stmt = $db->prepare("SELECT realm_id, COUNT(*) as count FROM territories GROUP BY realm_id");
            $stmt->execute();
            $byRealm = $stmt->fetchAll();

            // Top controlling sects
            $stmt = $db->prepare("SELECT s.id, s.name, COUNT(t.id) as territories_count
                                 FROM sects s
                                 INNER JOIN territories t ON s.id = t.sect_id
                                 GROUP BY s.id, s.name
                                 ORDER BY territories_count DESC
                                 LIMIT 10");
            $stmt->execute();
            $topSects = $stmt->fetchAll();

            return [
                'total_territories' => $total,
                'controlled_territories' => $controlled,
                'neutral_territories' => $neutral,
                'control_percentage' => $total > 0 ? ($controlled / $total) * 100 : 0,
                'by_realm' => $byRealm,
                'top_controlling_sects' => $topSects
            ];
        } catch (PDOException $e) {
            error_log("Failed to get territory statistics: " . $e->getMessage());
            return [
                'total_territories' => 0,
                'controlled_territories' => 0,
                'neutral_territories' => 0,
                'control_percentage' => 0,
                'by_realm' => [],
                'top_controlling_sects' => []
            ];
        }
    }

    /**
     * Recalculate all realm influences
     * Useful for maintenance or after major changes
     * 
     * @return int Number of realms updated
     */
    public function recalculateAllInfluences(): int
    {
        try {
            $db = Database::getConnection();
            
            // Get all realms
            $stmt = $db->prepare("SELECT id FROM realms");
            $stmt->execute();
            $realms = $stmt->fetchAll();

            $updated = 0;
            foreach ($realms as $realm) {
                $this->updateGlobalRealmInfluence($db, (int)$realm['id']);
                $updated++;
            }

            return $updated;
        } catch (PDOException $e) {
            error_log("Failed to recalculate influences: " . $e->getMessage());
            return 0;
        }
    }
}
