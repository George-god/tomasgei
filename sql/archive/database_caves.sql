-- Cultivation Cave system: personal sanctuary bonuses (cultivation chi gain, breakthrough).
-- Run after database_schema.sql (requires users table).

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS cave_environments (
    env_key VARCHAR(40) NOT NULL PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    cultivation_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    breakthrough_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    description VARCHAR(400) NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cave_formations (
    formation_key VARCHAR(40) NOT NULL PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    cultivation_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    breakthrough_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    required_cave_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description VARCHAR(400) NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_required_level (required_cave_level),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_caves (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    unlocked TINYINT(1) NOT NULL DEFAULT 0,
    cave_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    environment_key VARCHAR(40) NOT NULL DEFAULT 'balanced',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (environment_key) REFERENCES cave_environments(env_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_cave_formations (
    user_id INT UNSIGNED NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    formation_key VARCHAR(40) NULL DEFAULT NULL,
    PRIMARY KEY (user_id, slot),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (formation_key) REFERENCES cave_formations(formation_key) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cave_environments (env_key, display_name, cultivation_bonus_pct, breakthrough_bonus_pct, description, sort_order) VALUES
('balanced', 'Balanced Meridian Hall', 0.0080, 0.0080, 'Harmonized qi flow; modest gains to both cultivation speed and breakthrough calm.', 1),
('flame_vein', 'Flame Vein Chamber', 0.0220, 0.0040, 'Volatile fire spirit accelerates chi condensation.', 2),
('frost_well', 'Frost Well Grotto', 0.0040, 0.0220, 'Still, cold essence steadies the dao heart for tribulations.', 3),
('thunder_heart', 'Thunder Heart Cavern', 0.0140, 0.0140, 'Crackling yang qi tempers body and resolve alike.', 4),
('earth_root', 'Earth Root Sanctum', 0.0160, 0.0100, 'Dense terrestrial qi anchors your foundation.', 5),
('void_mist', 'Void Mist Hollow', 0.0060, 0.0200, 'Thin boundary between realms favors breakthrough insight.', 6);

INSERT IGNORE INTO cave_formations (formation_key, display_name, cultivation_bonus_pct, breakthrough_bonus_pct, required_cave_level, description, sort_order) VALUES
('spirit_gathering_array', 'Spirit Gathering Array', 0.0120, 0.0020, 1, 'Draws ambient qi toward your meditation cushion.', 1),
('breath_circle', 'Breath Stabilization Circle', 0.0060, 0.0060, 1, 'Equalizes inhale and exhale cycles.', 2),
('tribulation_seal', 'Minor Tribulation Seal', 0.0030, 0.0120, 2, 'Redirects stray tribulation echoes.', 3),
('dual_essence_pillars', 'Dual Essence Pillars', 0.0100, 0.0080, 4, 'Two pillars channel yin and yang in parallel.', 4),
('astral_convergence', 'Astral Convergence Diagram', 0.0150, 0.0100, 7, 'Maps star paths onto your meridians.', 5);
