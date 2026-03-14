-- Sect War system.
-- Captureable regions become sect territories. Sects fight over a shared war crystal.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS sect_territories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_id INT UNSIGNED NOT NULL,
    owner_sect_id INT UNSIGNED NULL DEFAULT NULL,
    captured_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_region (region_id),
    INDEX idx_owner (owner_sect_id),
    FOREIGN KEY (region_id) REFERENCES world_regions(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_sect_id) REFERENCES sects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_wars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    territory_id INT UNSIGNED NOT NULL,
    attacker_sect_id INT UNSIGNED NOT NULL,
    defender_sect_id INT UNSIGNED NULL DEFAULT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    crystal_max_hp BIGINT UNSIGNED NOT NULL,
    crystal_current_hp BIGINT UNSIGNED NOT NULL,
    status ENUM('active','completed') NOT NULL DEFAULT 'active',
    winner_sect_id INT UNSIGNED NULL DEFAULT NULL,
    rewards_distributed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_territory_status (territory_id, status),
    INDEX idx_attacker_status (attacker_sect_id, status),
    INDEX idx_defender_status (defender_sect_id, status),
    FOREIGN KEY (territory_id) REFERENCES sect_territories(id) ON DELETE CASCADE,
    FOREIGN KEY (attacker_sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (defender_sect_id) REFERENCES sects(id) ON DELETE SET NULL,
    FOREIGN KEY (winner_sect_id) REFERENCES sects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_war_damage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    war_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    sect_id INT UNSIGNED NOT NULL,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    kills INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_war_user (war_id, user_id),
    INDEX idx_war_sect (war_id, sect_id),
    FOREIGN KEY (war_id) REFERENCES sect_wars(id) ON DELETE CASCADE,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN last_sect_war_attack_at DATETIME NULL DEFAULT NULL;

INSERT INTO sect_territories (region_id, owner_sect_id, captured_at)
SELECT r.id, NULL, NULL
FROM world_regions r
WHERE COALESCE(r.can_be_captured, 0) = 1
ON DUPLICATE KEY UPDATE region_id = VALUES(region_id);
