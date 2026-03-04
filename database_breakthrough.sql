-- Breakthrough: failure chance, breakthrough_attempts, optional pill.
-- Run after database_schema.sql and database_phase1.sql (item_templates), database_realms_tier.sql.

USE cultivation_rpg;

-- User breakthrough attempt counter (reset on success)
ALTER TABLE users ADD COLUMN breakthrough_attempts INT UNSIGNED NOT NULL DEFAULT 0;

-- item_templates: consumable type and breakthrough bonus (percentage points, e.g. 13 = +13%)
ALTER TABLE item_templates MODIFY COLUMN type ENUM('weapon', 'armor', 'accessory', 'consumable') NOT NULL;
ALTER TABLE item_templates ADD COLUMN breakthrough_bonus INT NOT NULL DEFAULT 0 COMMENT 'Success chance bonus in percent (e.g. 13 = +13%)';

-- Seed Breakthrough Pill (85% + 13% = 98% cap)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus) VALUES
(4, 'Breakthrough Pill', 'consumable', 0, 0, 0, 0, 13);
