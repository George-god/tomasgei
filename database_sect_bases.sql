-- Sect Base system.
-- Each sect receives a living base with buildings and resident NPCs.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS sect_bases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    base_name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sect_base (sect_id),
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_buildings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_id INT UNSIGNED NOT NULL,
    building_key VARCHAR(50) NOT NULL,
    building_name VARCHAR(100) NOT NULL,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    description TEXT NULL,
    display_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bonus_summary VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uk_base_building (base_id, building_key),
    INDEX idx_base_order (base_id, display_order),
    FOREIGN KEY (base_id) REFERENCES sect_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_npcs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_id INT UNSIGNED NOT NULL,
    npc_key VARCHAR(50) NOT NULL,
    npc_name VARCHAR(100) NOT NULL,
    npc_role ENUM('elder', 'disciple') NOT NULL,
    npc_rank ENUM('elder','core_disciple','inner_disciple','outer_disciple') NOT NULL DEFAULT 'outer_disciple',
    title VARCHAR(100) NOT NULL,
    bonus_type ENUM('cultivation_speed', 'gold_gain', 'breakthrough') NULL DEFAULT NULL,
    bonus_value DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_base_npc (base_id, npc_key),
    INDEX idx_base_role_active (base_id, npc_role, is_active),
    INDEX idx_base_rank_active (base_id, npc_rank, is_active),
    FOREIGN KEY (base_id) REFERENCES sect_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_missions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    npc_id INT UNSIGNED NOT NULL,
    assigned_by_user_id INT UNSIGNED NOT NULL,
    mission_type ENUM('herb_gathering','ore_mining','beast_hunt','scout_territory','treasure_hunt') NOT NULL,
    status ENUM('active','completed','failed','claimed') NOT NULL DEFAULT 'active',
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    success_chance DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    success_result TINYINT(1) NULL DEFAULT NULL,
    reward_gold INT UNSIGNED NOT NULL DEFAULT 0,
    reward_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    reward_item_template_id INT UNSIGNED NULL DEFAULT NULL,
    reward_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    result_message VARCHAR(255) NULL DEFAULT NULL,
    collected_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sect_status_end (sect_id, status, end_time),
    INDEX idx_npc_status (npc_id, status),
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (npc_id) REFERENCES sect_npcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sect_bases (sect_id, base_name, created_at)
SELECT s.id, CONCAT(s.name, ' Base'), NOW()
FROM sects s
LEFT JOIN sect_bases b ON b.sect_id = s.id
WHERE b.id IS NULL;

INSERT INTO sect_buildings (base_id, building_key, building_name, level, description, display_order, bonus_summary)
SELECT b.id, t.building_key, t.building_name, 1, t.description, t.display_order, t.bonus_summary
FROM sect_bases b
JOIN (
    SELECT 'sect_hall' AS building_key, 'Sect Hall' AS building_name, 'The administrative heart of the sect where leaders receive petitions and issue commands.' AS description, 1 AS display_order, 'Coordinates sect affairs and raises morale.' AS bonus_summary
    UNION ALL
    SELECT 'training_grounds', 'Training Grounds', 'Courtyards and dueling rings where disciples temper their bodies and techniques.', 2, 'Supports disciplined daily cultivation.'
    UNION ALL
    SELECT 'alchemy_pavilion', 'Alchemy Pavilion', 'Refiners and flame arrays assist the sect''s pill makers and medicine keepers.', 3, 'Improves alchemical atmosphere and resource handling.'
    UNION ALL
    SELECT 'forge_pavilion', 'Forge Pavilion', 'A forge lined with spirit furnaces for shaping weapons, armor, and artifacts.', 4, 'Keeps sect equipment in battle-ready condition.'
    UNION ALL
    SELECT 'library_pavilion', 'Library Pavilion', 'Shelves of manuals and copied techniques preserve the sect''s teachings.', 5, 'Improves study, insight, and tactical memory.'
    UNION ALL
    SELECT 'inner_garden', 'Inner Garden', 'Quiet gardens, herb beds, and meditation stones nurture recovery and spiritual balance.', 6, 'Provides serenity and spiritual nourishment.'
    UNION ALL
    SELECT 'war_room', 'War Room', 'Map tables, scouts'' reports, and formation boards guide territorial strategy.', 7, 'Improves readiness for sect conflicts.'
) t
LEFT JOIN sect_buildings sb ON sb.base_id = b.id AND sb.building_key = t.building_key
WHERE sb.id IS NULL;

INSERT INTO sect_npcs (base_id, npc_key, npc_name, npc_role, npc_rank, title, bonus_type, bonus_value, is_active, sort_order)
SELECT b.id, n.npc_key, n.npc_name, n.npc_role, n.npc_rank, n.title, n.bonus_type, n.bonus_value, n.is_active, n.sort_order
FROM sect_bases b
JOIN (
    SELECT 'elder_qinghe' AS npc_key, 'Elder Qinghe' AS npc_name, 'elder' AS npc_role, 'elder' AS npc_rank, 'Caretaker of Breathing Forms' AS title, 'cultivation_speed' AS bonus_type, 0.0100 AS bonus_value, 1 AS is_active, 1 AS sort_order
    UNION ALL
    SELECT 'elder_mingshi', 'Elder Mingshi', 'elder', 'elder', 'Treasurer of Outer Affairs', 'gold_gain', 0.0100, 1, 2
    UNION ALL
    SELECT 'elder_yanru', 'Elder Yanru', 'elder', 'elder', 'Keeper of Breakthrough Records', 'breakthrough', 0.0100, 1, 3
    UNION ALL
    SELECT 'disciple_1', 'Disciple Lan', 'disciple', 'core_disciple', 'Garden Attendant', 'cultivation_speed', 0.0010, 1, 11
    UNION ALL
    SELECT 'disciple_2', 'Disciple Bo', 'disciple', 'inner_disciple', 'Library Scribe', 'cultivation_speed', 0.0010, 1, 12
    UNION ALL
    SELECT 'disciple_3', 'Disciple Rui', 'disciple', 'outer_disciple', 'Pavilion Assistant', 'gold_gain', 0.0010, 1, 13
    UNION ALL
    SELECT 'disciple_4', 'Disciple Fen', 'disciple', 'outer_disciple', 'Forge Runner', 'gold_gain', 0.0010, 1, 14
    UNION ALL
    SELECT 'disciple_5', 'Disciple Tao', 'disciple', 'outer_disciple', 'Messenger', 'breakthrough', 0.0010, 1, 15
) n
LEFT JOIN sect_npcs sn ON sn.base_id = b.id AND sn.npc_key = n.npc_key
WHERE sn.id IS NULL;
