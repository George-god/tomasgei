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
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
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
    INDEX idx_is_admin (is_admin),
    INDEX idx_realm_id (realm_id),
    INDEX idx_rating (rating DESC),
    INDEX idx_wins_losses (wins DESC, losses ASC),
    INDEX idx_rating_wins_id (rating DESC, wins DESC, id ASC),
    INDEX idx_last_cultivation_at (last_cultivation_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bug_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(150) NOT NULL,
    status ENUM('observing', 'investigating', 'resolved') NOT NULL DEFAULT 'observing',
    admin_reply TEXT NULL,
    admin_user_id INT UNSIGNED NULL DEFAULT NULL,
    replied_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_bug_reports_user (user_id, created_at),
    INDEX idx_bug_reports_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dao_petitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) NOT NULL,
    status ENUM('observing', 'contemplating', 'accepted', 'denied') NOT NULL DEFAULT 'observing',
    heavenly_response TEXT NULL,
    admin_user_id INT UNSIGNED NULL DEFAULT NULL,
    responded_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dao_petitions_user (user_id, created_at),
    INDEX idx_dao_petitions_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dao_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NULL DEFAULT NULL,
    description TEXT NOT NULL,
    context_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dao_records_event (event_type, created_at),
    INDEX idx_dao_records_user (user_id, created_at),
    INDEX idx_dao_records_target (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dao paths table (unlocked from Foundation Building onward)
CREATE TABLE IF NOT EXISTS dao_paths (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL UNIQUE,
    alignment ENUM('orthodox', 'demonic') NOT NULL DEFAULT 'orthodox',
    element ENUM('flame', 'water', 'wind', 'earth') NOT NULL,
    description TEXT NULL,
    attack_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    defense_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    max_chi_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    dodge_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    bonus_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    heal_on_hit_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    reflect_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    self_damage_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    favored_tribulation ENUM('lightning','fire','demonic_heart','void','heavenly_judgment') NOT NULL DEFAULT 'lightning',
    drawback_text VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alignment (alignment),
    INDEX idx_element (element)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dao_paths (
    id, path_key, name, alignment, element, description,
    attack_bonus_pct, defense_bonus_pct, max_chi_bonus_pct, dodge_bonus_pct,
    bonus_damage_pct, heal_on_hit_pct, reflect_damage_pct, self_damage_pct,
    favored_tribulation, drawback_text
) VALUES
(1, 'flame_dao', 'Flame Dao', 'orthodox', 'flame', 'Refines blazing force into righteous destruction.', 0.1200, 0.0200, 0.0000, 0.0000, 0.0800, 0.0000, 0.0000, 0.0000, 'fire', NULL),
(2, 'water_dao', 'Water Dao', 'orthodox', 'water', 'Uses flowing essence to endure and recover through every clash.', 0.0200, 0.1000, 0.1000, 0.0000, 0.0000, 0.0500, 0.0000, 0.0000, 'void', NULL),
(3, 'wind_dao', 'Wind Dao', 'orthodox', 'wind', 'Turns movement and speed into piercing momentum.', 0.0800, 0.0200, 0.0000, 0.0800, 0.0500, 0.0000, 0.0000, 0.0000, 'lightning', NULL),
(4, 'earth_dao', 'Earth Dao', 'orthodox', 'earth', 'Roots the cultivator in stability, endurance, and mountain-like defense.', 0.0000, 0.1400, 0.1200, 0.0000, 0.0000, 0.0000, 0.0400, 0.0000, 'heavenly_judgment', NULL),
(5, 'demonic_flame_dao', 'Demonic Flame Dao', 'demonic', 'flame', 'Consumes the self to unleash savage crimson fire.', 0.1800, 0.0200, 0.0000, 0.0000, 0.1200, 0.0000, 0.0000, 0.0400, 'fire', 'Each attack burns a portion of your own life force.'),
(6, 'demonic_water_dao', 'Demonic Water Dao', 'demonic', 'water', 'Abyssal currents devour enemies and siphon their vitality.', 0.0200, 0.1500, 0.1500, 0.0000, 0.0000, 0.0800, 0.0000, 0.0200, 'void', 'Every attack demands a blood price from the user.'),
(7, 'demonic_wind_dao', 'Demonic Wind Dao', 'demonic', 'wind', 'Chaotic velocity tears through foes at the cost of control.', 0.1400, 0.0000, 0.0000, 0.1200, 0.1000, 0.0000, 0.0000, 0.0300, 'lightning', 'Rapid attacks shred your own meridians with each strike.'),
(8, 'demonic_earth_dao', 'Demonic Earth Dao', 'demonic', 'earth', 'Condenses abyssal weight into crushing defense and backlash.', 0.0000, 0.2000, 0.1800, 0.0000, 0.0000, 0.0000, 0.0800, 0.0250, 'heavenly_judgment', 'Heavier strikes extract qi from your body whenever you attack.');

CREATE TABLE IF NOT EXISTS dao_techniques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dao_path_id INT UNSIGNED NOT NULL,
    technique_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    tier ENUM('basic', 'advanced', 'ultimate') NOT NULL,
    damage_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
    cooldown_turns TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cost_type ENUM('stamina', 'hp', 'corruption') NOT NULL,
    cost_value DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    special_effect VARCHAR(50) NOT NULL DEFAULT 'none',
    effect_value DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dao_path_id) REFERENCES dao_paths(id) ON DELETE CASCADE,
    INDEX idx_dao_path_tier (dao_path_id, tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dao_techniques (
    id, dao_path_id, technique_key, name, tier, damage_multiplier, cooldown_turns, cost_type, cost_value, special_effect, effect_value, description
) VALUES
(1, 1, 'ember_palm', 'Ember Palm', 'basic', 1.200, 2, 'stamina', 15.00, 'burn', 0.080, 'A focused palm strike wreathed in controlled flame.'),
(2, 1, 'crimson_wave_slash', 'Crimson Wave Slash', 'advanced', 1.450, 4, 'stamina', 28.00, 'burn', 0.120, 'A sweeping arc of righteous flame that scorches the target.'),
(3, 1, 'vermilion_sun_cataclysm', 'Vermilion Sun Cataclysm', 'ultimate', 1.900, 6, 'hp', 6.00, 'burn', 0.180, 'Condenses a blazing sun into one devastating strike.'),
(4, 2, 'ripple_cut', 'Ripple Cut', 'basic', 1.100, 2, 'stamina', 15.00, 'heal', 0.100, 'A fluid cut that restores a sliver of vitality.'),
(5, 2, 'deep_tide_sever', 'Deep Tide Sever', 'advanced', 1.350, 4, 'stamina', 26.00, 'heal', 0.140, 'Crashing tides wash wounds away while battering the foe.'),
(6, 2, 'ocean_mirror_domain', 'Ocean Mirror Domain', 'ultimate', 1.650, 6, 'hp', 5.00, 'stone_guard', 0.180, 'A reflected ocean current shields the user after impact.'),
(7, 3, 'gale_step_strike', 'Gale Step Strike', 'basic', 1.150, 2, 'stamina', 14.00, 'windstep', 0.100, 'A swift strike that leaves afterimages in the wind.'),
(8, 3, 'sky_rend_tempest', 'Sky Rend Tempest', 'advanced', 1.400, 4, 'stamina', 26.00, 'windstep', 0.150, 'A storm-laced lunge that sharpens the next evasion.'),
(9, 3, 'nine_heavens_velocity', 'ultimate', 1.750, 6, 'hp', 5.00, 'windstep', 0.220, 'Velocity from the upper heavens tears through everything ahead.'),
(10, 4, 'stonebreaker_fist', 'Stonebreaker Fist', 'basic', 1.180, 2, 'stamina', 14.00, 'stone_guard', 0.100, 'A stable fist that raises a brief earthen guard.'),
(11, 4, 'mountain_shaking_descent', 'advanced', 1.420, 4, 'stamina', 27.00, 'stone_guard', 0.140, 'Mountain force crashes down and hardens the cultivator.'),
(12, 4, 'world_root_bastion', 'ultimate', 1.700, 6, 'hp', 5.00, 'reflect', 0.180, 'Roots into the world itself and returns incoming force.'),
(13, 5, 'bloodfire_claw', 'basic', 1.300, 2, 'hp', 4.00, 'burn', 0.100, 'Demonic flames feed on the user to rip into the enemy.'),
(14, 5, 'hellflame_surge', 'advanced', 1.600, 4, 'hp', 7.00, 'burn', 0.150, 'A feral surge of hellfire scorches all restraint away.'),
(15, 5, 'infernal_devouring_sun', 'ultimate', 2.100, 6, 'corruption', 28.00, 'burn', 0.220, 'The infernal sun devours purity for unmatched destructive power.'),
(16, 6, 'abyssal_drain', 'basic', 1.200, 2, 'corruption', 10.00, 'heal', 0.140, 'Dark waters leech vitality from the foe.'),
(17, 6, 'black_tide_collapse', 'advanced', 1.500, 4, 'corruption', 18.00, 'heal', 0.180, 'An abyssal wave collapses inward and restores the user.'),
(18, 6, 'sea_of_grievances', 'ultimate', 1.950, 6, 'corruption', 30.00, 'stone_guard', 0.200, 'A grieving sea swallows force and turns it aside.'),
(19, 7, 'ghost_gale_reap', 'basic', 1.280, 2, 'hp', 3.00, 'windstep', 0.120, 'A reaping gale that slices both foe and meridian.'),
(20, 7, 'voidstep_slaughter', 'advanced', 1.620, 4, 'hp', 6.00, 'windstep', 0.180, 'A slaughtering step through distorted wind currents.'),
(21, 7, 'thousand_wraith_tempest', 'ultimate', 2.050, 6, 'corruption', 26.00, 'windstep', 0.240, 'Wraithlike winds carve the battlefield into ribbons.'),
(22, 8, 'gravecrusher_stance', 'basic', 1.250, 2, 'corruption', 12.00, 'stone_guard', 0.120, 'A grave-heavy stance that blunts the next assault.'),
(23, 8, 'abyssal_mountain_slam', 'advanced', 1.580, 4, 'corruption', 20.00, 'reflect', 0.150, 'An abyssal mountain crash that punishes retaliation.'),
(24, 8, 'netherworld_citadel', 'ultimate', 1.900, 6, 'corruption', 32.00, 'reflect', 0.220, 'A citadel of the underworld repels all who strike it.');

CREATE TABLE IF NOT EXISTS cultivation_manuals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    rarity ENUM('common', 'rare', 'epic', 'legendary', 'mythic') NOT NULL DEFAULT 'common',
    source_type ENUM('dungeon', 'world_boss', 'ancient_ruins', 'crafted') NOT NULL,
    dao_element ENUM('flame', 'water', 'wind', 'earth') NULL DEFAULT NULL,
    dao_alignment ENUM('orthodox', 'demonic', 'universal') NOT NULL DEFAULT 'universal',
    unlock_tier ENUM('none', 'basic', 'advanced', 'ultimate') NOT NULL DEFAULT 'none',
    unlock_technique_key VARCHAR(80) NULL DEFAULT NULL,
    technique_upgrade_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    cooldown_reduction_turns TINYINT UNSIGNED NOT NULL DEFAULT 0,
    passive_attack_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_defense_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_max_chi_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_dodge_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    description TEXT NULL,
    is_custom TINYINT(1) NOT NULL DEFAULT 0,
    creator_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_manual_source_rarity (source_type, rarity),
    INDEX idx_manual_element_alignment (dao_element, dao_alignment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_cultivation_manuals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    manual_id INT UNSIGNED NOT NULL,
    acquired_from ENUM('dungeon', 'world_boss', 'ancient_ruins', 'crafted') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manual_id) REFERENCES cultivation_manuals(id) ON DELETE CASCADE,
    INDEX idx_user_manuals (user_id, is_active),
    INDEX idx_manual_owner (manual_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cultivation_manual_recipes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    rarity ENUM('rare', 'epic', 'legendary') NOT NULL,
    required_level INT UNSIGNED NOT NULL DEFAULT 1,
    required_material_tier INT UNSIGNED NOT NULL DEFAULT 1,
    required_materials INT UNSIGNED NOT NULL DEFAULT 1,
    required_rune_fragments INT UNSIGNED NOT NULL DEFAULT 0,
    required_gold INT UNSIGNED NOT NULL DEFAULT 0,
    required_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    unlock_tier ENUM('none', 'advanced', 'ultimate') NOT NULL DEFAULT 'none',
    technique_upgrade_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    cooldown_reduction_turns TINYINT UNSIGNED NOT NULL DEFAULT 0,
    passive_attack_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_defense_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_max_chi_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    passive_dodge_pct DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cultivation_manuals (
    id, manual_key, name, rarity, source_type, dao_element, dao_alignment, unlock_tier, unlock_technique_key,
    technique_upgrade_pct, cooldown_reduction_turns, passive_attack_pct, passive_defense_pct, passive_max_chi_pct, passive_dodge_pct, description, is_custom, creator_user_id
) VALUES
(1, 'ember_breath_primer', 'Ember Breath Primer', 'common', 'dungeon', 'flame', 'universal', 'none', NULL, 0.000, 0, 0.050, 0.000, 0.020, 0.000, 'Steadies flame meridians and sharpens offensive intent.', 0, NULL),
(2, 'calm_lake_treatise', 'Calm Lake Treatise', 'common', 'dungeon', 'water', 'universal', 'none', NULL, 0.000, 0, 0.000, 0.020, 0.060, 0.000, 'A tranquil text that deepens reserves and steady breathing.', 0, NULL),
(3, 'wind_tracing_primer', 'Wind Tracing Primer', 'common', 'ancient_ruins', 'wind', 'universal', 'none', NULL, 0.000, 0, 0.020, 0.000, 0.000, 0.050, 'Illustrates footwork patterns left by vanished scouts.', 0, NULL),
(4, 'stone_skin_primer', 'Stone Skin Primer', 'common', 'dungeon', 'earth', 'universal', 'none', NULL, 0.000, 0, 0.000, 0.060, 0.030, 0.000, 'A foundational body-forging text that tempers resilience.', 0, NULL),
(5, 'scarlet_meridian_scroll', 'Scarlet Meridian Scroll', 'rare', 'dungeon', 'flame', 'universal', 'advanced', NULL, 0.080, 0, 0.060, 0.000, 0.000, 0.000, 'Unlocks deeper flame circulation and strengthens advanced fire techniques.', 0, NULL),
(6, 'tide_reversal_classic', 'Tide Reversal Classic', 'rare', 'dungeon', 'water', 'universal', 'advanced', NULL, 0.080, 0, 0.000, 0.030, 0.070, 0.000, 'An advanced current-changing method treasured by river sects.', 0, NULL),
(7, 'stormstep_record', 'Stormstep Record', 'rare', 'ancient_ruins', 'wind', 'universal', 'advanced', NULL, 0.080, 0, 0.030, 0.000, 0.000, 0.060, 'A stolen movement record from a lost courier lineage.', 0, NULL),
(8, 'mountain_bone_record', 'Mountain Bone Record', 'rare', 'dungeon', 'earth', 'universal', 'advanced', NULL, 0.080, 0, 0.000, 0.080, 0.040, 0.000, 'A battle manual teaching structure, weight, and stillness.', 0, NULL),
(9, 'fallen_sect_insight_scroll', 'Fallen Sect Insight Scroll', 'epic', 'ancient_ruins', NULL, 'universal', 'advanced', NULL, 0.120, 1, 0.040, 0.040, 0.040, 0.040, 'Fragments of a once-great sect reveal hidden efficiencies in every form.', 0, NULL),
(10, 'phoenix_immolation_canon', 'Phoenix Immolation Canon', 'legendary', 'world_boss', 'flame', 'universal', 'ultimate', NULL, 0.180, 1, 0.100, 0.000, 0.050, 0.000, 'Recovered from a sovereign fire spirit, this canon awakens ultimate flame killing arts.', 0, NULL),
(11, 'leviathan_abyss_scripture', 'Leviathan Abyss Scripture', 'legendary', 'world_boss', 'water', 'universal', 'ultimate', NULL, 0.180, 1, 0.000, 0.050, 0.120, 0.000, 'A scripture of abyssal tides that grants crushing patience and depth.', 0, NULL),
(12, 'storm_void_atlas', 'Storm Void Atlas', 'legendary', 'world_boss', 'wind', 'universal', 'ultimate', NULL, 0.180, 1, 0.060, 0.000, 0.000, 0.100, 'Maps impossible wind corridors and grants access to final storm arts.', 0, NULL),
(13, 'titan_root_tome', 'Titan Root Tome', 'legendary', 'world_boss', 'earth', 'universal', 'ultimate', NULL, 0.180, 1, 0.000, 0.120, 0.060, 0.000, 'A vast tome of rooted force favored by ancient defenders.', 0, NULL),
(14, 'ancestral_dao_compilation', 'Ancestral Dao Compilation', 'mythic', 'world_boss', NULL, 'universal', 'ultimate', NULL, 0.250, 2, 0.080, 0.080, 0.080, 0.080, 'A mythic compilation of ancestral annotations that perfects any cultivated path.', 0, NULL);

INSERT IGNORE INTO cultivation_manual_recipes (
    id, recipe_key, name, rarity, required_level, required_material_tier, required_materials, required_rune_fragments,
    required_gold, required_spirit_stones, unlock_tier, technique_upgrade_pct, cooldown_reduction_turns,
    passive_attack_pct, passive_defense_pct, passive_max_chi_pct, passive_dodge_pct
) VALUES
(1, 'forged_battle_commentary', 'Forged Battle Commentary', 'rare', 20, 2, 4, 2, 250, 1, 'advanced', 0.080, 0, 0.080, 0.000, 0.020, 0.000),
(2, 'jade_guard_compendium', 'Jade Guard Compendium', 'rare', 20, 2, 4, 2, 250, 1, 'advanced', 0.080, 0, 0.000, 0.080, 0.060, 0.000),
(3, 'windborne_insight_codex', 'Windborne Insight Codex', 'epic', 35, 3, 6, 4, 500, 2, 'advanced', 0.120, 1, 0.040, 0.020, 0.020, 0.060),
(4, 'ancestral_forged_scripture', 'Ancestral Forged Scripture', 'legendary', 50, 3, 8, 6, 900, 5, 'ultimate', 0.180, 1, 0.060, 0.060, 0.060, 0.040);

ALTER TABLE users
    ADD COLUMN dao_path_id INT UNSIGNED NULL DEFAULT NULL AFTER realm_id,
    ADD CONSTRAINT fk_users_dao_path FOREIGN KEY (dao_path_id) REFERENCES dao_paths(id) ON DELETE SET NULL,
    ADD INDEX idx_dao_path_id (dao_path_id);

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
    attacker_use_techniques BOOLEAN NOT NULL DEFAULT TRUE,
    defender_use_techniques BOOLEAN NOT NULL DEFAULT TRUE,
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

CREATE TABLE IF NOT EXISTS sect_library_manuals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    manual_id INT UNSIGNED NOT NULL,
    stored_by_user_id INT UNSIGNED NOT NULL,
    borrowed_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    stored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    borrowed_at DATETIME NULL DEFAULT NULL,
    due_at DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (manual_id) REFERENCES cultivation_manuals(id) ON DELETE CASCADE,
    FOREIGN KEY (stored_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (borrowed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sect_library (sect_id, borrowed_by_user_id),
    INDEX idx_library_manual (manual_id)
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

-- Tribulations table (major realm breakthrough survival records)
CREATE TABLE IF NOT EXISTS tribulations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    realm_id_before INT UNSIGNED NOT NULL,
    realm_id_after INT UNSIGNED NULL,
    tribulation_type ENUM('lightning','fire','demonic_heart','void','heavenly_judgment') NOT NULL,
    phase_count TINYINT UNSIGNED NOT NULL DEFAULT 3,
    failed_phase TINYINT UNSIGNED NULL DEFAULT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    difficulty_rating DECIMAL(6,3) NOT NULL DEFAULT 1.000,
    breakthrough_attempts_used INT UNSIGNED NOT NULL DEFAULT 0,
    pill_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    sect_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    rune_type VARCHAR(50) NULL DEFAULT NULL,
    start_chi BIGINT UNSIGNED NOT NULL DEFAULT 0,
    end_chi BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_taken BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (realm_id_before) REFERENCES realms(id) ON DELETE RESTRICT,
    FOREIGN KEY (realm_id_after) REFERENCES realms(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tribulation phase logs (3 phases per tribulation)
CREATE TABLE IF NOT EXISTS tribulation_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tribulation_id INT UNSIGNED NOT NULL,
    strike_number INT UNSIGNED NOT NULL,
    phase_name VARCHAR(100) NOT NULL,
    chi_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_after_defense BIGINT UNSIGNED NOT NULL DEFAULT 0,
    was_dodged BOOLEAN NOT NULL DEFAULT FALSE,
    chi_after BIGINT UNSIGNED NOT NULL,
    survival_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    phase_result ENUM('survived','failed') NOT NULL DEFAULT 'survived',
    message VARCHAR(255) NULL DEFAULT NULL,
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
    INDEX idx_final_rating (final_rating DESC),
    INDEX idx_wins_rating (wins DESC, final_rating DESC)
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
    INDEX idx_final_rating (final_rating DESC),
    INDEX idx_territories_rating (territories_controlled DESC, final_rating DESC)
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
