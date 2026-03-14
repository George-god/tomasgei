-- Hidden Dungeon system.
-- Run after database_world_map.sql.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS dungeons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    region_id INT UNSIGNED NOT NULL,
    difficulty INT UNSIGNED NOT NULL DEFAULT 1,
    min_realm_id INT UNSIGNED NOT NULL DEFAULT 1,
    boss_name VARCHAR(100) NOT NULL,
    boss_hp BIGINT UNSIGNED NOT NULL DEFAULT 100,
    boss_attack INT UNSIGNED NOT NULL DEFAULT 10,
    boss_defense INT UNSIGNED NOT NULL DEFAULT 10,
    FOREIGN KEY (region_id) REFERENCES world_regions(id) ON DELETE CASCADE,
    INDEX idx_region (region_id),
    INDEX idx_min_realm (min_realm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dungeon_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    dungeon_id INT UNSIGNED NOT NULL,
    progress INT UNSIGNED NOT NULL DEFAULT 0,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (dungeon_id) REFERENCES dungeons(id) ON DELETE CASCADE,
    INDEX idx_user_started (user_id, started_at),
    INDEX idx_user_dungeon (user_id, dungeon_id, is_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dungeons (id, name, region_id, difficulty, min_realm_id, boss_name, boss_hp, boss_attack, boss_defense) VALUES
(1, 'Rootbound Hollow', 1, 1, 1, 'Treant Core', 180, 16, 10),
(2, 'Whispering Den', 2, 2, 1, 'Mistfang Alpha', 260, 22, 14),
(3, 'Scarlet Forge', 3, 3, 2, 'Crimson Sentinel', 420, 30, 18),
(4, 'Fallen Observatory', 4, 4, 3, 'Star-Eyed Watcher', 620, 38, 24),
(5, 'Emperor''s Tomb', 5, 5, 4, 'Ancient Warden', 900, 48, 30);
