-- Phase 1: Additional indexes for stability and common queries.
-- Run after database_phase1.sql and database_pvp_stamina.sql.

USE cultivation_rpg;

-- Inventory: composite for "find stack by user + template" (ItemService::addItemToInventory)
CREATE INDEX idx_inventory_user_template ON inventory (user_id, item_template_id);

-- PvE battles: user + created_at for "recent battles" and replays
CREATE INDEX idx_pve_battles_user_created ON pve_battles (user_id, created_at DESC);

-- Battles (PvP): already have idx_attacker_id, idx_defender_id, idx_created_at in schema
-- No change if already present.
