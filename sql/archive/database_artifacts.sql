-- Artifacts: equippable and/or active relics with rarity, bonuses, abilities, evolution.
-- Apply database_artifacts.sql after database_schema.sql (requires users).

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS artifacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artifact_key VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(700) NOT NULL DEFAULT '',
    rarity ENUM('common','uncommon','rare','epic','legendary','mythic') NOT NULL DEFAULT 'common',
    can_equip TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can occupy an equip slot (max 3)',
    can_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can occupy an active aura slot (max 2)',
    is_unique TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'At most one copy per player',
    is_evolving TINYINT(1) NOT NULL DEFAULT 0,
    evolution_max_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    passive_attack_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    passive_defense_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    passive_max_chi_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_out_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_taken_reduction_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_crit_bonus DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_dodge_bonus DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_counter_bonus DECIMAL(8,4) NOT NULL DEFAULT 0,
    combat_lifesteal_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    ability_name VARCHAR(120) NOT NULL DEFAULT '',
    ability_description VARCHAR(550) NOT NULL DEFAULT '',
    drop_world_boss_weight INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Relative weight when a boss drop procs',
    drop_dungeon_weight INT UNSIGNED NOT NULL DEFAULT 0,
    drop_event_tag VARCHAR(64) NULL DEFAULT NULL COMMENT 'Match scheduled_events.event_name substring or exact',
    drop_event_bp INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Basis points success when event matches (daily cap in app)',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rarity (rarity),
    INDEX idx_drop_event (drop_event_tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_artifacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    artifact_id INT UNSIGNED NOT NULL,
    evolution_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    equip_slot TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1-3 when socketed as equip',
    active_slot TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1-2 when socketed as active',
    acquired_source VARCHAR(32) NOT NULL DEFAULT '',
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_user_artifact (user_id, artifact_id),
    INDEX idx_equip (user_id, equip_slot),
    INDEX idx_active (user_id, active_slot),
    CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ua_artifact FOREIGN KEY (artifact_id) REFERENCES artifacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_event_artifact_daily (
    user_id INT UNSIGNED NOT NULL,
    roll_date DATE NOT NULL,
    PRIMARY KEY (user_id, roll_date),
    CONSTRAINT fk_uead_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO artifacts (
    id, artifact_key, name, description, rarity,
    can_equip, can_active, is_unique, is_evolving, evolution_max_tier,
    passive_attack_pct, passive_defense_pct, passive_max_chi_pct,
    combat_out_pct, combat_taken_reduction_pct, combat_crit_bonus, combat_dodge_bonus, combat_counter_bonus, combat_lifesteal_bonus_pct,
    ability_name, ability_description,
    drop_world_boss_weight, drop_dungeon_weight, drop_event_tag, drop_event_bp, sort_order
) VALUES
(1, 'shard_winter', 'Shard of the Winter Bell', 'A splinter from a bell that once called down aurora qi. Favors cold endurance.', 'rare',
    1, 0, 0, 1, 5,
    0.0150, 0.0200, 0.0120, 0.0100, 0.0200, 0.0050, 0.0000, 0.0000, 0.0000,
    'Frost Echo', 'When struck, a trace of aurora shaves a sliver of incoming force.',
    25, 20, NULL, 0, 1),
(2, 'ember_codex', 'Ember-Wrought Codex', 'Metal plates etched in forge-light; hungry for momentum.', 'epic',
    1, 1, 1, 1, 5,
    0.0280, 0.0000, 0.0000, 0.0350, 0.0000, 0.0180, 0.0000, 0.0000, 0.0000,
    'Forge Verse', 'Each earnest strike channels stored heat into the next opening.',
    40, 35, NULL, 0, 2),
(3, 'tide_lantern', 'Tideglass Lantern', 'Catches wandering spirits of sea-mist; steadies meridians.', 'rare',
    0, 1, 0, 0, 1,
    0.0000, 0.0120, 0.0350, 0.0000, 0.0150, 0.0000, 0.0120, 0.0000, 0.0100,
    'Mist Shelter', 'A soft field misreads enemy timing—minor dodge and trickle sustain.',
    15, 45, NULL, 0, 3),
(4, 'void_compass', 'Void-Bronze Compass', 'Needle does not point north—it points toward pressure.', 'legendary',
    1, 0, 1, 1, 5,
    0.0200, 0.0200, 0.0180, 0.0200, 0.0250, 0.0120, 0.0100, 0.0150, 0.0000,
    'Pressure Sense', 'Reads the flow of battle; bonuses tilt toward breaking stalemates.',
    80, 15, NULL, 0, 4),
(5, 'harvest_sigil', 'Harvest Sigil', 'Issued when the sect opens grain-store vaults under a full moon.', 'uncommon',
    0, 1, 0, 0, 1,
    0.0050, 0.0050, 0.0180, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000, 0.0000,
    'Abundant Breath', 'During celestial harvests, chi thickens slightly in the lungs.',
    5, 10, 'harvest', 120, 5),
(6, 'tribulation_splinter', 'Tribulation Splinter', 'Lightning glass fused into a palm-sized wedge.', 'common',
    1, 0, 0, 1, 3,
    0.0080, 0.0080, 0.0060, 0.0050, 0.0080, 0.0060, 0.0000, 0.0000, 0.0000,
    'Storm-Kissed', 'Carries a faint tribulation scent; sparks extra crit chance.',
    10, 60, NULL, 0, 6);
