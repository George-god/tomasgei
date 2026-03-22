-- Seasonal rankings (see services/SeasonService.php, pages/season_rankings.php)
-- Run after core schema. Extends titles.unlock_type with 'season_rank'.

CREATE TABLE IF NOT EXISTS seasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(64) NOT NULL UNIQUE,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    weight_pvp DECIMAL(12,6) NOT NULL DEFAULT 1.000000 COMMENT 'Multiplier for score_pvp in total_score',
    weight_boss DECIMAL(12,6) NOT NULL DEFAULT 0.010000 COMMENT 'Multiplier for score_world_boss (damage)',
    weight_cultivation DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
    weight_sect DECIMAL(12,6) NOT NULL DEFAULT 5.000000 COMMENT 'Multiplier for sect contribution units',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_ends (status, ends_at),
    INDEX idx_active (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS season_rankings (
    season_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    score_pvp INT UNSIGNED NOT NULL DEFAULT 0,
    score_world_boss BIGINT UNSIGNED NOT NULL DEFAULT 0,
    score_cultivation INT UNSIGNED NOT NULL DEFAULT 0,
    score_sect INT UNSIGNED NOT NULL DEFAULT 0,
    total_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rank_overall INT UNSIGNED NULL DEFAULT NULL,
    rank_pvp INT UNSIGNED NULL DEFAULT NULL,
    rank_boss INT UNSIGNED NULL DEFAULT NULL,
    rank_cultivation INT UNSIGNED NULL DEFAULT NULL,
    rank_sect INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (season_id, user_id),
    INDEX idx_season_total (season_id, total_score DESC),
    INDEX idx_season_pvp (season_id, score_pvp DESC),
    INDEX idx_season_boss (season_id, score_world_boss DESC),
    INDEX idx_season_cult (season_id, score_cultivation DESC),
    INDEX idx_season_sect (season_id, score_sect DESC),
    CONSTRAINT fk_season_rankings_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_season_rankings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend titles enum (ignore error if already applied)
ALTER TABLE titles MODIFY COLUMN unlock_type ENUM(
    'pvp_wins','pve_kills','boss_participation','sect_contribution','tribulation_success','exploration','season_rank'
) NOT NULL;

INSERT IGNORE INTO titles (id, slug, name, description, unlock_type, unlock_value, bonus_attack_pct, bonus_defense_pct, bonus_max_chi_pct, sort_order) VALUES
(13, 'season_sovereign', 'Season Sovereign', 'Finished 1st overall in a ranked season.', 'season_rank', 0, 0.01200, 0.00800, 0.00800, 200),
(14, 'season_ascendant', 'Season Ascendant', 'Finished in the top 3 overall in a ranked season.', 'season_rank', 0, 0.00800, 0.00600, 0.00600, 201),
(15, 'season_duelist', 'Season Duelist', 'Ranked 1st in PvP for a season.', 'season_rank', 0, 0.01000, 0.00400, 0, 202),
(16, 'season_worldbreaker', 'Season Worldbreaker', 'Ranked 1st in world boss damage for a season.', 'season_rank', 0, 0.00800, 0.00400, 0.00400, 203),
(17, 'season_sage', 'Season Sage', 'Ranked 1st in cultivation for a season.', 'season_rank', 0, 0.00400, 0.00400, 0.01000, 204),
(18, 'season_arbiter', 'Season Arbiter', 'Ranked 1st in sect contribution for a season.', 'season_rank', 0, 0.00600, 0.00800, 0.00400, 205);
