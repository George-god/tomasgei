-- Player titles: run on your DB (see services/TitleService.php)

CREATE TABLE IF NOT EXISTS titles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    unlock_type ENUM('pvp_wins','pve_kills','boss_participation','sect_contribution','tribulation_success','exploration') NOT NULL,
    unlock_value INT UNSIGNED NOT NULL DEFAULT 1,
    bonus_attack_pct DECIMAL(6,5) NOT NULL DEFAULT 0 COMMENT 'e.g. 0.00500 = 0.5%',
    bonus_defense_pct DECIMAL(6,5) NOT NULL DEFAULT 0,
    bonus_max_chi_pct DECIMAL(6,5) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_titles (
    user_id INT UNSIGNED NOT NULL,
    title_id INT UNSIGNED NOT NULL,
    unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, title_id),
    INDEX idx_user (user_id),
    CONSTRAINT fk_user_titles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_titles_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracking columns (cumulative for unlocks). Adjust AFTER column if your users table differs.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS equipped_title_id INT UNSIGNED NULL DEFAULT NULL AFTER last_sect_war_attack_at,
    ADD COLUMN IF NOT EXISTS title_pve_wins INT UNSIGNED NOT NULL DEFAULT 0 AFTER equipped_title_id,
    ADD COLUMN IF NOT EXISTS title_explore_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER title_pve_wins,
    ADD COLUMN IF NOT EXISTS title_boss_attacks INT UNSIGNED NOT NULL DEFAULT 0 AFTER title_explore_count,
    ADD COLUMN IF NOT EXISTS title_sect_donated_gold BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER title_boss_attacks,
    ADD COLUMN IF NOT EXISTS title_tribulations_won INT UNSIGNED NOT NULL DEFAULT 0 AFTER title_sect_donated_gold;

-- Optional FK (run after columns exist):
-- ALTER TABLE users ADD CONSTRAINT fk_users_equipped_title FOREIGN KEY (equipped_title_id) REFERENCES titles(id) ON DELETE SET NULL;

INSERT IGNORE INTO titles (id, slug, name, description, unlock_type, unlock_value, bonus_attack_pct, bonus_defense_pct, bonus_max_chi_pct, sort_order) VALUES
(1, 'first_blood', 'First Blood', 'Win your first PvP battle.', 'pvp_wins', 1, 0.00300, 0, 0, 10),
(2, 'duelist', 'Duelist', 'Win 10 PvP battles.', 'pvp_wins', 10, 0.00500, 0.00300, 0, 20),
(3, 'warlord', 'Warlord', 'Win 50 PvP battles.', 'pvp_wins', 50, 0.01000, 0.00500, 0.00500, 30),
(4, 'hunter', 'Beast Hunter', 'Win 25 PvE battles.', 'pve_kills', 25, 0.00400, 0.00400, 0, 40),
(5, 'slayer', 'Realm Slayer', 'Win 100 PvE battles.', 'pve_kills', 100, 0.00800, 0.00600, 0.00400, 50),
(6, 'skyspear', 'Sky Spear', 'Attack the World Boss 30 times.', 'boss_participation', 30, 0, 0, 0.00800, 60),
(7, 'patron', 'Sect Patron', 'Donate 5,000 gold total to your sect.', 'sect_contribution', 5000, 0.00300, 0.00600, 0.00300, 70),
(8, 'benefactor', 'Heavenly Benefactor', 'Donate 50,000 gold total to your sect.', 'sect_contribution', 50000, 0.00600, 0.00800, 0.00600, 80),
(9, 'thunderheart', 'Thunder Heart', 'Survive a major tribulation once.', 'tribulation_success', 1, 0.00500, 0.00500, 0.00500, 90),
(10, 'ascendant', 'Ascendant of Trials', 'Survive 5 major tribulations.', 'tribulation_success', 5, 0.01000, 0.00800, 0.00800, 100),
(11, 'wanderer', 'Realm Wanderer', 'Explore regions 50 times.', 'exploration', 50, 0.00300, 0, 0.00600, 110),
(12, 'explorer', 'Boundless Explorer', 'Explore regions 200 times.', 'exploration', 200, 0.00600, 0.00400, 0.01000, 120);
