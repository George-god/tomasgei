-- Sect alliances: 3–5 sects per pact, invitations, sect war bonuses (run on existing DB)

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS alliances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alliances_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alliance_members (
    alliance_id INT UNSIGNED NOT NULL,
    sect_id INT UNSIGNED NOT NULL,
    role ENUM('founder', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (alliance_id, sect_id),
    UNIQUE KEY uk_alliance_members_sect (sect_id),
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    INDEX idx_alliance_members_alliance (alliance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alliance_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id INT UNSIGNED NOT NULL,
    target_sect_id INT UNSIGNED NOT NULL,
    inviter_sect_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_alliance_invite_pair (alliance_id, target_sect_id),
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
    FOREIGN KEY (target_sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (inviter_sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    INDEX idx_alliance_invitations_target (target_sect_id, status),
    INDEX idx_alliance_invitations_alliance (alliance_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
