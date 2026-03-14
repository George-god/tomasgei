-- Disciple ranking and NPC sect missions migration for existing databases.

USE cultivation_rpg;

ALTER TABLE sect_members
    ADD COLUMN rank ENUM('leader','elder','core_disciple','inner_disciple','outer_disciple') NOT NULL DEFAULT 'outer_disciple' AFTER user_id;

UPDATE sect_members
SET rank = CASE
    WHEN role = 'leader' THEN 'leader'
    WHEN role = 'elder' THEN 'elder'
    ELSE 'outer_disciple'
END;

ALTER TABLE sect_members
    ADD INDEX idx_sect_rank (sect_id, rank);

ALTER TABLE sect_npcs
    ADD COLUMN npc_rank ENUM('elder','core_disciple','inner_disciple','outer_disciple') NOT NULL DEFAULT 'outer_disciple' AFTER npc_role;

UPDATE sect_npcs
SET npc_rank = CASE
    WHEN npc_role = 'elder' THEN 'elder'
    WHEN npc_key = 'disciple_1' THEN 'core_disciple'
    WHEN npc_key = 'disciple_2' THEN 'inner_disciple'
    ELSE 'outer_disciple'
END;

ALTER TABLE sect_npcs
    ADD INDEX idx_base_rank_active (base_id, npc_rank, is_active);

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
