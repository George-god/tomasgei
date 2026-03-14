<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 3 MMO foundation: scheduled events and world state.
 * Syncs event active state by current time; updates world_state. Lightweight, modular.
 */
class EventService
{
    private const WORLD_STATE_ACTIVE_EVENT = 'active_event';
    private const WORLD_STATE_WORLD_BOSS_EVENT = 'world_boss_event';

    /**
     * Sync scheduled events by current time: activate (start_time <= now <= end_time), deactivate otherwise.
     * Writes current active event name to world_state (or empty if none).
     */
    public function syncScheduledEvents(): void
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');

            $db->prepare("UPDATE scheduled_events SET is_active = (start_time <= ? AND end_time >= ?)")
                ->execute([$now, $now]);

            $stmt = $db->prepare("SELECT event_name FROM scheduled_events WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeName = $row ? (string)$row['event_name'] : '';

            $stmt = $db->prepare("INSERT INTO world_state (key_name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)");
            $stmt->execute([self::WORLD_STATE_ACTIVE_EVENT, $activeName, $now]);
        } catch (PDOException $e) {
            error_log("EventService::syncScheduledEvents " . $e->getMessage());
        }
    }

    /**
     * Get current active world event (syncs first, then returns name or null).
     */
    public function getActiveEvent(): ?string
    {
        $this->syncScheduledEvents();
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT value FROM world_state WHERE key_name = ?");
            $stmt->execute([self::WORLD_STATE_ACTIVE_EVENT]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $value = $row ? trim((string)$row['value']) : '';
            if ($value !== '') {
                return $value;
            }

            $stmt = $db->prepare("SELECT value FROM world_state WHERE key_name = ?");
            $stmt->execute([self::WORLD_STATE_WORLD_BOSS_EVENT]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $value = $row ? trim((string)$row['value']) : '';
            return $value !== '' ? $value : null;
        } catch (PDOException $e) {
            error_log("EventService::getActiveEvent " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get global announcements that have not expired.
     */
    public function getActiveAnnouncements(): array
    {
        try {
            $db = Database::getConnection();
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("SELECT id, message, created_at, expires_at FROM global_announcements WHERE expires_at > ? ORDER BY created_at DESC");
            $stmt->execute([$now]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("EventService::getActiveAnnouncements " . $e->getMessage());
            return [];
        }
    }
}
