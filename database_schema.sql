-- Competitive Multiplayer Cultivation RPG Database Schema
-- Run this SQL to create all required tables

CREATE DATABASE IF NOT EXISTS cultivation_rpg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE cultivation_rpg;

-- Realms table (normalized cultivation realm definitions)
CREATE TABLE IF NOT EXISTS realms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    min_level INT UNSIGNED NOT NULL DEFAULT 1,
    max_level INT UNSIGNED NOT NULL DEFAULT 100,
    chi_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    stat_bonus_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    attack_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    defense_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table (with ELO rating and PvP stats)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(30) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    realm_id INT UNSIGNED NOT NULL DEFAULT 1,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    chi BIGINT UNSIGNED NOT NULL DEFAULT 100,
    max_chi BIGINT UNSIGNED NOT NULL DEFAULT 100,
    attack INT UNSIGNED NOT NULL DEFAULT 10,
    defense INT UNSIGNED NOT NULL DEFAULT 10,
    wins INT UNSIGNED NOT NULL DEFAULT 0,
    losses INT UNSIGNED NOT NULL DEFAULT 0,
    rating DECIMAL(8,2) NOT NULL DEFAULT 1000.00,
    last_cultivation_at TIMESTAMP NULL DEFAULT NULL,
    last_breakthrough_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (realm_id) REFERENCES realms(id) ON DELETE RESTRICT,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_realm_id (realm_id),
    INDEX idx_rating (rating DESC),
    INDEX idx_wins_losses (wins DESC, losses ASC),
    INDEX idx_last_cultivation_at (last_cultivation_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Battles table (PvP battle records)
CREATE TABLE IF NOT EXISTS battles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attacker_id INT UNSIGNED NOT NULL,
    defender_id INT UNSIGNED NOT NULL,
    winner_id INT UNSIGNED NOT NULL,
    attacker_rating_before DECIMAL(8,2) NOT NULL,
    defender_rating_before DECIMAL(8,2) NOT NULL,
    attacker_rating_after DECIMAL(8,2) NOT NULL,
    defender_rating_after DECIMAL(8,2) NOT NULL,
    attacker_chi_start BIGINT UNSIGNED NOT NULL,
    defender_chi_start BIGINT UNSIGNED NOT NULL,
    attacker_chi_loss BIGINT UNSIGNED NOT NULL DEFAULT 0,
    defender_chi_loss BIGINT UNSIGNED NOT NULL DEFAULT 0,
    turns INT UNSIGNED NOT NULL DEFAULT 0,
    battle_duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attacker_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (defender_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_attacker_id (attacker_id),
    INDEX idx_defender_id (defender_id),
    INDEX idx_winner_id (winner_id),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Battle logs table (detailed turn-by-turn battle logs)
CREATE TABLE IF NOT EXISTS battle_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    battle_id INT UNSIGNED NOT NULL,
    turn_number INT UNSIGNED NOT NULL,
    attacker_id INT UNSIGNED NOT NULL,
    defender_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_critical BOOLEAN NOT NULL DEFAULT FALSE,
    is_dodge BOOLEAN NOT NULL DEFAULT FALSE,
    is_lifesteal BOOLEAN NOT NULL DEFAULT FALSE,
    is_counterattack BOOLEAN NOT NULL DEFAULT FALSE,
    attacker_chi_after BIGINT UNSIGNED NOT NULL,
    defender_chi_after BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (battle_id) REFERENCES battles(id) ON DELETE CASCADE,
    FOREIGN KEY (attacker_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (defender_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_battle_id (battle_id),
    INDEX idx_turn_number (turn_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Battle challenges table (asynchronous PvP challenges)
CREATE TABLE IF NOT EXISTS battle_challenges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenger_id INT UNSIGNED NOT NULL,
    defender_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'declined', 'completed', 'expired') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (challenger_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (defender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_challenger_id (challenger_id),
    INDEX idx_defender_id (defender_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table (user notifications)
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    related_id INT UNSIGNED NULL,
    related_type VARCHAR(50) NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at DESC),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rankings table (ELO-based ranking system, can support seasons)
CREATE TABLE IF NOT EXISTS rankings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    rating DECIMAL(8,2) NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    season_id INT UNSIGNED DEFAULT 1,
    wins INT UNSIGNED NOT NULL DEFAULT 0,
    losses INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_season (user_id, season_id),
    INDEX idx_rating (rating DESC),
    INDEX idx_rank_position (rank_position),
    INDEX idx_season_id (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sects table (factions/guilds - future-ready)
CREATE TABLE IF NOT EXISTS sects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    leader_id INT UNSIGNED NULL,
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_rating DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (leader_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_leader_id (leader_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sect members table (many-to-many relationship)
CREATE TABLE IF NOT EXISTS sect_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('leader', 'elder', 'disciple') NOT NULL DEFAULT 'disciple',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sect_user (sect_id, user_id),
    INDEX idx_sect_id (sect_id),
    INDEX idx_user_id (user_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tribulation blessings table (permanent stat bonuses from tribulations)
CREATE TABLE IF NOT EXISTS tribulation_blessings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    blessing_type VARCHAR(50) NOT NULL,
    stat_type VARCHAR(50) NOT NULL,
    bonus_percentage FLOAT NOT NULL,
    tribulation_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_blessing_type (blessing_type),
    INDEX idx_stat_type (stat_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tribulations table (tribulation event records)
CREATE TABLE IF NOT EXISTS tribulations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    realm_id_before INT UNSIGNED NOT NULL,
    realm_id_after INT UNSIGNED NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    strikes_count INT UNSIGNED NOT NULL DEFAULT 0,
    damage_taken BIGINT UNSIGNED NOT NULL DEFAULT 0,
    blessing_granted_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (realm_id_before) REFERENCES realms(id) ON DELETE RESTRICT,
    FOREIGN KEY (realm_id_after) REFERENCES realms(id) ON DELETE RESTRICT,
    FOREIGN KEY (blessing_granted_id) REFERENCES tribulation_blessings(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tribulation logs table (detailed strike-by-strike logs)
CREATE TABLE IF NOT EXISTS tribulation_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tribulation_id INT UNSIGNED NOT NULL,
    strike_number INT UNSIGNED NOT NULL,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_after_defense BIGINT UNSIGNED NOT NULL DEFAULT 0,
    was_dodged BOOLEAN NOT NULL DEFAULT FALSE,
    chi_after BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tribulation_id) REFERENCES tribulations(id) ON DELETE CASCADE,
    INDEX idx_tribulation_id (tribulation_id),
    INDEX idx_strike_number (strike_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Territories table (territory control system)
CREATE TABLE IF NOT EXISTS territories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    realm_id INT UNSIGNED NOT NULL,
    sect_id INT UNSIGNED NULL,
    stat_bonus_percentage FLOAT NOT NULL DEFAULT 0.0,
    cultivation_bonus_percentage FLOAT NOT NULL DEFAULT 0.0,
    tribulation_resistance_percentage FLOAT NOT NULL DEFAULT 0.0,
    controlled_since TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (realm_id) REFERENCES realms(id) ON DELETE RESTRICT,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE SET NULL,
    INDEX idx_realm_id (realm_id),
    INDEX idx_sect_id (sect_id),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sect wars table (sect war records)
CREATE TABLE IF NOT EXISTS sect_wars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attacker_sect_id INT UNSIGNED NOT NULL,
    defender_sect_id INT UNSIGNED NOT NULL,
    winner_sect_id INT UNSIGNED NOT NULL,
    territory_id INT UNSIGNED NULL,
    battle_id INT UNSIGNED NULL,
    war_type ENUM('territory', 'honor') NOT NULL DEFAULT 'territory',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attacker_sect_id) REFERENCES sects(id) ON DELETE RESTRICT,
    FOREIGN KEY (defender_sect_id) REFERENCES sects(id) ON DELETE RESTRICT,
    FOREIGN KEY (winner_sect_id) REFERENCES sects(id) ON DELETE RESTRICT,
    FOREIGN KEY (territory_id) REFERENCES territories(id) ON DELETE SET NULL,
    FOREIGN KEY (battle_id) REFERENCES battles(id) ON DELETE SET NULL,
    INDEX idx_attacker_sect_id (attacker_sect_id),
    INDEX idx_defender_sect_id (defender_sect_id),
    INDEX idx_winner_sect_id (winner_sect_id),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eras table (era cycle system)
CREATE TABLE IF NOT EXISTS eras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    start_date TIMESTAMP NOT NULL,
    end_date TIMESTAMP NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_start_date (start_date),
    INDEX idx_end_date (end_date),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Era rankings table (historical player rankings per era)
CREATE TABLE IF NOT EXISTS era_rankings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    era_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    final_rating DECIMAL(8,2) NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    wins INT UNSIGNED NOT NULL DEFAULT 0,
    losses INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (era_id) REFERENCES eras(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_era_user (era_id, user_id),
    INDEX idx_era_id (era_id),
    INDEX idx_rank_position (rank_position),
    INDEX idx_final_rating (final_rating DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Era sect rankings table (historical sect rankings per era)
CREATE TABLE IF NOT EXISTS era_sect_rankings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    era_id INT UNSIGNED NOT NULL,
    sect_id INT UNSIGNED NOT NULL,
    final_rating DECIMAL(10,2) NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    territories_controlled INT UNSIGNED NOT NULL DEFAULT 0,
    wars_won INT UNSIGNED NOT NULL DEFAULT 0,
    wars_lost INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (era_id) REFERENCES eras(id) ON DELETE CASCADE,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_era_sect (era_id, sect_id),
    INDEX idx_era_id (era_id),
    INDEX idx_rank_position (rank_position),
    INDEX idx_final_rating (final_rating DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- World state table (global game state tracking)
CREATE TABLE IF NOT EXISTS world_state (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    realm_id INT UNSIGNED NOT NULL,
    pressure_level FLOAT NOT NULL DEFAULT 0.0,
    influence_percentage FLOAT NOT NULL DEFAULT 0.0,
    stat_modifier_percentage FLOAT NOT NULL DEFAULT 0.0,
    cultivation_modifier_percentage FLOAT NOT NULL DEFAULT 0.0,
    tribulation_modifier_percentage FLOAT NOT NULL DEFAULT 0.0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (realm_id) REFERENCES realms(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_realm (realm_id),
    INDEX idx_realm_id (realm_id),
    INDEX idx_pressure_level (pressure_level DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Challenge rate limiting table (anti-farming protection)
CREATE TABLE IF NOT EXISTS challenge_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenger_id INT UNSIGNED NOT NULL,
    defender_id INT UNSIGNED NOT NULL,
    challenge_count INT UNSIGNED NOT NULL DEFAULT 1,
    last_challenge_at TIMESTAMP NOT NULL,
    hour_window_start TIMESTAMP NOT NULL,
    FOREIGN KEY (challenger_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (defender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_challenger_defender (challenger_id, defender_id),
    INDEX idx_hour_window_start (hour_window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skills table (player skills/techniques - future-ready)
CREATE TABLE IF NOT EXISTS skills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    skill_type VARCHAR(50) NOT NULL,
    damage_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    chi_cost INT UNSIGNED NOT NULL DEFAULT 0,
    cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    realm_requirement_id INT UNSIGNED NULL,
    level_requirement INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (realm_requirement_id) REFERENCES realms(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_skill_type (skill_type),
    INDEX idx_realm_requirement (realm_requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User skills table (many-to-many relationship)
CREATE TABLE IF NOT EXISTS user_skills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    skill_id INT UNSIGNED NOT NULL,
    skill_level INT UNSIGNED NOT NULL DEFAULT 1,
    unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_skill (user_id, skill_id),
    INDEX idx_user_id (user_id),
    INDEX idx_skill_id (skill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default realms
INSERT INTO realms (id, name, description, min_level, max_level, chi_multiplier, stat_bonus_multiplier, attack_multiplier, defense_multiplier) VALUES
(1, 'Qi Refining', 'The first stage of cultivation. Foundation of spiritual energy.', 1, 10, 1.00, 1.00, 1.00, 1.00),
(2, 'Foundation Building', 'Building a solid foundation for future breakthroughs.', 11, 20, 1.50, 1.20, 1.20, 1.15),
(3, 'Core Formation', 'Forming the core of spiritual power.', 21, 30, 2.00, 1.50, 1.50, 1.30),
(4, 'Nascent Soul', 'Awakening the nascent soul within.', 31, 40, 2.50, 2.00, 2.00, 1.50),
(5, 'Soul Transformation', 'Transforming the soul to higher levels.', 41, 50, 3.00, 2.50, 2.50, 2.00)
ON DUPLICATE KEY UPDATE name=name;

-- Initialize world state for all realms
INSERT INTO world_state (realm_id, pressure_level, influence_percentage) 
SELECT id, 0.0, 0.0 FROM realms
ON DUPLICATE KEY UPDATE realm_id=realm_id;
