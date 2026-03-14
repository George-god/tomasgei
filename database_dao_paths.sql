-- Dao Path system migration for existing databases.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS dao_paths (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL UNIQUE,
    alignment ENUM('orthodox', 'demonic') NOT NULL DEFAULT 'orthodox',
    element ENUM('flame', 'water', 'wind', 'earth') NOT NULL,
    description TEXT NULL,
    attack_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    defense_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    max_chi_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    dodge_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    bonus_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    heal_on_hit_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    reflect_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    self_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    favored_tribulation ENUM('lightning','fire','demonic_heart','void','heavenly_judgment') NOT NULL DEFAULT 'lightning',
    drawback_text VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alignment (alignment),
    INDEX idx_element (element)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN dao_path_id INT UNSIGNED NULL DEFAULT NULL AFTER realm_id;

ALTER TABLE users
    ADD CONSTRAINT fk_users_dao_path FOREIGN KEY (dao_path_id) REFERENCES dao_paths(id) ON DELETE SET NULL;

ALTER TABLE users
    ADD INDEX idx_dao_path_id (dao_path_id);

INSERT IGNORE INTO dao_paths (
    id, path_key, name, alignment, element, description,
    attack_bonus_pct, defense_bonus_pct, max_chi_bonus_pct, dodge_bonus_pct,
    bonus_damage_pct, heal_on_hit_pct, reflect_damage_pct, self_damage_pct,
    favored_tribulation, drawback_text
) VALUES
(1, 'flame_dao', 'Flame Dao', 'orthodox', 'flame', 'Refines blazing force into righteous destruction.', 0.1200, 0.0200, 0.0000, 0.0000, 0.0800, 0.0000, 0.0000, 0.0000, 'fire', NULL),
(2, 'water_dao', 'Water Dao', 'orthodox', 'water', 'Uses flowing essence to endure and recover through every clash.', 0.0200, 0.1000, 0.1000, 0.0000, 0.0000, 0.0500, 0.0000, 0.0000, 'void', NULL),
(3, 'wind_dao', 'Wind Dao', 'orthodox', 'Turns movement and speed into piercing momentum.', 0.0800, 0.0200, 0.0000, 0.0800, 0.0500, 0.0000, 0.0000, 0.0000, 'lightning', NULL),
(4, 'earth_dao', 'Earth Dao', 'orthodox', 'Roots the cultivator in stability, endurance, and mountain-like defense.', 0.0000, 0.1400, 0.1200, 0.0000, 0.0000, 0.0000, 0.0400, 0.0000, 'heavenly_judgment', NULL),
(5, 'demonic_flame_dao', 'Demonic Flame Dao', 'demonic', 'flame', 'Consumes the self to unleash savage crimson fire.', 0.1800, 0.0200, 0.0000, 0.0000, 0.1200, 0.0000, 0.0000, 0.0400, 'fire', 'Each attack burns a portion of your own life force.'),
(6, 'demonic_water_dao', 'Demonic Water Dao', 'demonic', 'water', 'Abyssal currents devour enemies and siphon their vitality.', 0.0200, 0.1500, 0.1500, 0.0000, 0.0000, 0.0800, 0.0000, 0.0200, 'void', 'Every attack demands a blood price from the user.'),
(7, 'demonic_wind_dao', 'Demonic Wind Dao', 'demonic', 'wind', 'Chaotic velocity tears through foes at the cost of control.', 0.1400, 0.0000, 0.0000, 0.1200, 0.1000, 0.0000, 0.0000, 0.0300, 'lightning', 'Rapid attacks shred your own meridians with each strike.'),
(8, 'demonic_earth_dao', 'Demonic Earth Dao', 'demonic', 'earth', 'Condenses abyssal weight into crushing defense and backlash.', 0.0000, 0.2000, 0.1800, 0.0000, 0.0000, 0.0000, 0.0800, 0.0250, 'heavenly_judgment', 'Heavier strikes extract qi from your body whenever you attack.');
