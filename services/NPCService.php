<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * NPC service - Phase 1.
 * Simple NPC list and stats. No rarity tiers, no loot tables.
 */
class NPCService
{
    /**
     * Get NPCs available for the user (same realm or realm 1).
     */
    public function getAvailableNPCsForUser(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $userStmt = $db->prepare("SELECT realm_id FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            if (!$user) return [];
            $userRealmId = (int)$user['realm_id'];

            $stmt = $db->prepare("SELECT * FROM npcs WHERE realm_id <= ? ORDER BY level ASC");
            $stmt->execute([$userRealmId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("NPCService::getAvailableNPCsForUser " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get NPC by ID.
     */
    public function getNPCById(int $npcId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM npcs WHERE id = ? LIMIT 1");
            $stmt->execute([$npcId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("NPCService::getNPCById " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get NPC stats for battle (attack, defense, max_chi).
     */
    public function getNpcStatsForBattle(array $npc): array
    {
        return [
            'attack' => (int)$npc['base_attack'],
            'defense' => (int)$npc['base_defense'],
            'max_chi' => (int)$npc['base_hp'],
            'realm_id' => (int)$npc['realm_id']
        ];
    }
}
