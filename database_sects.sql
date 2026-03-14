-- Sect system (Phase 2.4). No territory, no wars, no sect bank.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS sects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    leader_user_id INT UNSIGNED NOT NULL,
    tier ENUM('third','second','first') NOT NULL DEFAULT 'third',
    sect_exp INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leader (leader_user_id),
    INDEX idx_tier (tier),
    INDEX idx_sect_exp_id (sect_exp DESC, id ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sect_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rank ENUM('leader','elder','core_disciple','inner_disciple','outer_disciple') NOT NULL DEFAULT 'outer_disciple',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user (user_id),
    INDEX idx_sect (sect_id),
    INDEX idx_sect_rank (sect_id, rank),
    INDEX idx_sect_joined (sect_id, joined_at),
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
