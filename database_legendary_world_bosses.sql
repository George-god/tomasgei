-- Legendary world bosses and legendary item rarity.
-- Run on existing databases after database_world_boss.sql, database_world_map.sql, and base item tables.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS world_boss_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    region_id INT UNSIGNED NOT NULL,
    max_hp BIGINT UNSIGNED NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 120,
    legendary_item_template_id INT UNSIGNED NULL DEFAULT NULL,
    is_legendary TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_template_name (name),
    INDEX idx_template_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE world_bosses ADD COLUMN template_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE world_bosses ADD COLUMN region_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE world_bosses ADD INDEX idx_template (template_id);
ALTER TABLE world_bosses ADD INDEX idx_region (region_id);

ALTER TABLE boss_rewards ADD COLUMN legendary_item_template_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE item_templates ADD COLUMN rarity ENUM('common', 'legendary') NOT NULL DEFAULT 'common';

INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, rarity) VALUES
(70, 'Serpent Fang Halberd', 'weapon', 28, 6, 0, 0, 'legendary'),
(71, 'Voidscale Robe', 'armor', 0, 26, 90, 0, 'legendary'),
(72, 'Phoenix Soul Pendant', 'accessory', 14, 14, 120, 0, 'legendary');

INSERT INTO world_boss_templates (id, name, region_id, max_hp, duration_minutes, legendary_item_template_id, is_legendary) VALUES
(1, 'Abyssal Sky Serpent', 4, 2500000, 180, 70, 1),
(2, 'Void Dragon', 8, 6500000, 180, 71, 1),
(3, 'Celestial Phoenix', 7, 4200000, 180, 72, 1)
ON DUPLICATE KEY UPDATE
    region_id = VALUES(region_id),
    max_hp = VALUES(max_hp),
    duration_minutes = VALUES(duration_minutes),
    legendary_item_template_id = VALUES(legendary_item_template_id),
    is_legendary = VALUES(is_legendary);
