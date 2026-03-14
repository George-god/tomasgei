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
