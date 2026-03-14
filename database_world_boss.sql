-- World Boss system (Phase 3.1). Lightweight, cooldown 30s.

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
    INDEX idx_template_region (region_id),
    FOREIGN KEY (region_id) REFERENCES world_regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_bosses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NULL DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    region_id INT UNSIGNED NULL DEFAULT NULL,
    max_hp BIGINT UNSIGNED NOT NULL,
    current_hp BIGINT UNSIGNED NOT NULL,
    spawn_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    is_alive TINYINT(1) NOT NULL DEFAULT 1,
    rewards_distributed_at DATETIME NULL DEFAULT NULL,
    INDEX idx_template (template_id),
    INDEX idx_region (region_id),
    INDEX idx_alive_end (is_alive, end_time),
    INDEX idx_alive_hp_end (is_alive, current_hp, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boss_damage_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    boss_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_hit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_boss_user (boss_id, user_id),
    INDEX idx_boss_user (boss_id, user_id),
    INDEX idx_boss_damage_order (boss_id, damage_dealt DESC, last_hit ASC, user_id ASC),
    FOREIGN KEY (boss_id) REFERENCES world_bosses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boss_rewards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    boss_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    gold_reward INT UNSIGNED NOT NULL DEFAULT 0,
    spirit_stone_reward INT UNSIGNED NOT NULL DEFAULT 0,
    legendary_item_template_id INT UNSIGNED NULL DEFAULT NULL,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_boss_reward_user (boss_id, user_id),
    INDEX idx_boss_rank (boss_id, rank_position),
    FOREIGN KEY (boss_id) REFERENCES world_bosses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN last_boss_attack_at DATETIME NULL DEFAULT NULL;

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
