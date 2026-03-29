-- Bloodline system: ancestral legacies unlocked by accomplishments; one active at a time; awakening upgrades; tier evolution & mutations.
-- Run after database_schema.sql (requires users, boss_damage_log, tribulations, dungeon_runs, titles, item_templates).

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS bloodlines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bloodline_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    unlock_type ENUM('boss_damage', 'tribulation_wins', 'pvp_wins', 'dungeon_clears') NOT NULL,
    unlock_value INT UNSIGNED NOT NULL DEFAULT 1,
    base_attack_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    base_defense_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    base_max_chi_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    base_cultivation_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    base_breakthrough_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    effect_key VARCHAR(40) NULL DEFAULT NULL,
    effect_value DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    effect_description VARCHAR(400) NULL DEFAULT NULL,
    awakening_max TINYINT UNSIGNED NOT NULL DEFAULT 5,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unlock (unlock_type, unlock_value),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bloodlines (
    id, bloodline_key, name, description, unlock_type, unlock_value,
    base_attack_pct, base_defense_pct, base_max_chi_pct, base_cultivation_pct, base_breakthrough_pct,
    effect_key, effect_value, effect_description, awakening_max, sort_order
) VALUES
(1, 'crimson_sovereign', 'Crimson Sovereign Bloodline', 'Forged in endless strikes against world-shaking beasts. Your meridians carry their crushing momentum.',
    'boss_damage', 50000,
    0.0120, 0.0040, 0.0060, 0.0040, 0.0030,
    'world_boss_damage', 0.0600, 'World boss attacks deal additional damage (scales with awakening).', 5, 1),
(2, 'heaven_tempered', 'Heaven-Tempered Soul Line', 'Those who endure heaven''s judgment without breaking inherit a calmer, sharper dao.',
    'tribulation_wins', 3,
    0.0050, 0.0080, 0.0100, 0.0050, 0.0080,
    'tribulation_mitigation', 0.0300, 'Extra mitigation during breakthrough tribulation phases (scales with awakening).', 5, 2),
(3, 'war_buddha', 'War Buddha Heritage', 'Countless duels refined your intent into an immovable fighting spirit.',
    'pvp_wins', 25,
    0.0100, 0.0060, 0.0040, 0.0030, 0.0040,
    'none', 0.0000, 'Pure combat lineage—bonuses focus on attack and breakthrough poise.', 5, 3),
(4, 'labyrinth_born', 'Labyrinth-Born Bloodline', 'You walked the deep halls until the maze recognized you as kin.',
    'dungeon_clears', 10,
    0.0040, 0.0100, 0.0080, 0.0060, 0.0030,
    'dungeon_gold_reward', 0.1000, 'Bonus gold from dungeon boss chests (scales with awakening).', 5, 4);

CREATE TABLE IF NOT EXISTS bloodline_mutation_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mutation_key VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(400) NOT NULL DEFAULT '',
    passive_bonus_mult DECIMAL(8,4) NOT NULL DEFAULT 0.0400,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bloodline_mutation_templates (id, mutation_key, display_name, description, passive_bonus_mult, sort_order) VALUES
(1, 'vermilion_fork', 'Vermilion Fork', 'Your meridians branch into a crimson lattice—raw intensity, imperfect control.', 0.0450, 1),
(2, 'azure_inversion', 'Azure Inversion', 'Yin currents reverse along one vessel; your lineage whispers of drowned stars.', 0.0400, 2),
(3, 'goldleaf_seal', 'Goldleaf Seal', 'A latent seal frays; power leaks outward as warm, steady amplification.', 0.0550, 3),
(4, 'void_humor', 'Void Humor', 'An emptiness mixes with your blood—not hunger, but quiet subtraction.', 0.0350, 4),
(5, 'thunder_root', 'Thunder Root', 'A tribulation echo roots in the bone. Your pulse answers storms first.', 0.0500, 5);

CREATE TABLE IF NOT EXISTS user_bloodlines (
    user_id INT UNSIGNED NOT NULL,
    bloodline_id INT UNSIGNED NOT NULL,
    unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    awakening_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    evolution_tier ENUM('awakened','evolved','transcendent','mythic') NOT NULL DEFAULT 'awakened',
    mutation_template_id INT UNSIGNED NULL DEFAULT NULL,
    mutation_stack TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, bloodline_id),
    INDEX idx_user_active (user_id, is_active),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE,
    FOREIGN KEY (mutation_template_id) REFERENCES bloodline_mutation_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, scroll_effect, rarity, material_tier, gear_tier) VALUES
(80, 'Lineage Catalyst', 'consumable', 0, 0, 0, 0, 0, NULL, 'rare', NULL, NULL);

CREATE TABLE IF NOT EXISTS bloodline_evolution (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bloodline_id INT UNSIGNED NOT NULL,
    from_tier ENUM('awakened','evolved','transcendent') NOT NULL,
    to_tier ENUM('evolved','transcendent','mythic') NOT NULL,
    success_chance_pct DECIMAL(5,2) NOT NULL,
    mutation_chance_pct DECIMAL(5,2) NOT NULL DEFAULT 0.0000,
    required_gold INT UNSIGNED NOT NULL DEFAULT 0,
    required_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    required_material_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    required_material_qty INT UNSIGNED NOT NULL DEFAULT 0,
    required_title_id INT UNSIGNED NULL DEFAULT NULL,
    required_item_template_id INT UNSIGNED NULL DEFAULT NULL,
    required_item_qty INT UNSIGNED NOT NULL DEFAULT 0,
    failure_extra_gold INT UNSIGNED NOT NULL DEFAULT 0,
    failure_chi_loss_pct DECIMAL(5,2) NOT NULL DEFAULT 0.0000,
    failure_awakening_levels TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bloodline_from_tier (bloodline_id, from_tier),
    INDEX idx_bloodline (bloodline_id),
    CONSTRAINT fk_be_bloodline FOREIGN KEY (bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_be_title FOREIGN KEY (required_title_id) REFERENCES titles(id) ON DELETE SET NULL,
    CONSTRAINT fk_be_item FOREIGN KEY (required_item_template_id) REFERENCES item_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bloodline_evolution (
    id, bloodline_id, from_tier, to_tier, success_chance_pct, mutation_chance_pct,
    required_gold, required_spirit_stones, required_material_tier, required_material_qty,
    required_title_id, required_item_template_id, required_item_qty,
    failure_extra_gold, failure_chi_loss_pct, failure_awakening_levels, sort_order
) VALUES
(1, 1, 'awakened', 'evolved', 58.00, 14.00, 4800, 12, 2, 4, 6, 80, 1, 1800, 7.50, 0, 1),
(2, 1, 'evolved', 'transcendent', 49.00, 9.00, 14000, 28, 3, 6, 9, 80, 2, 4200, 11.00, 1, 2),
(3, 1, 'transcendent', 'mythic', 41.00, 6.00, 28000, 45, 3, 10, 10, 80, 3, 9000, 14.00, 1, 3),
(4, 2, 'awakened', 'evolved', 58.00, 14.00, 4800, 12, 2, 4, 6, 80, 1, 1800, 7.50, 0, 1),
(5, 2, 'evolved', 'transcendent', 49.00, 9.00, 14000, 28, 3, 6, 9, 80, 2, 4200, 11.00, 1, 2),
(6, 2, 'transcendent', 'mythic', 41.00, 6.00, 28000, 45, 3, 10, 10, 80, 3, 9000, 14.00, 1, 3),
(7, 3, 'awakened', 'evolved', 58.00, 14.00, 4800, 12, 2, 4, 2, 80, 1, 1800, 7.50, 0, 1),
(8, 3, 'evolved', 'transcendent', 49.00, 9.00, 14000, 28, 3, 6, 3, 80, 2, 4200, 11.00, 1, 2),
(9, 3, 'transcendent', 'mythic', 41.00, 6.00, 28000, 45, 3, 10, 10, 80, 3, 9000, 14.00, 1, 3),
(10, 4, 'awakened', 'evolved', 58.00, 14.00, 4800, 12, 2, 4, 4, 80, 1, 1800, 7.50, 0, 1),
(11, 4, 'evolved', 'transcendent', 49.00, 9.00, 14000, 28, 3, 6, 5, 80, 2, 4200, 11.00, 1, 2),
(12, 4, 'transcendent', 'mythic', 41.00, 6.00, 28000, 45, 3, 10, 5, 80, 3, 9000, 14.00, 1, 3);

CREATE TABLE IF NOT EXISTS bloodline_abilities (
    bloodline_id INT UNSIGNED NOT NULL PRIMARY KEY,
    ability_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(700) NOT NULL DEFAULT '',
    combat_damage_out_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_damage_taken_reduction_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_crit_chance_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_dodge_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_counter_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_lifesteal_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    resonance_dao_element ENUM('flame','water','wind','earth') NULL DEFAULT NULL,
    resonance_dao_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0400,
    resonance_min_manuals TINYINT UNSIGNED NOT NULL DEFAULT 1,
    resonance_manual_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0150,
    resonance_manual_bonus_cap_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0600,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ba_bloodline FOREIGN KEY (bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bloodline_interactions (
    attacker_bloodline_id INT UNSIGNED NOT NULL,
    defender_bloodline_id INT UNSIGNED NOT NULL,
    matchup_outgoing_mult DECIMAL(6,4) NOT NULL DEFAULT 1.0000,
    description VARCHAR(400) NOT NULL DEFAULT '',
    PRIMARY KEY (attacker_bloodline_id, defender_bloodline_id),
    CONSTRAINT fk_bi_att FOREIGN KEY (attacker_bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_bi_def FOREIGN KEY (defender_bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bloodline_abilities (
    bloodline_id, ability_key, name, description,
    combat_damage_out_pct, combat_damage_taken_reduction_pct, combat_crit_chance_bonus, combat_dodge_bonus, combat_counter_bonus, combat_lifesteal_bonus_pct,
    resonance_dao_element, resonance_dao_bonus_pct, resonance_min_manuals, resonance_manual_bonus_pct, resonance_manual_bonus_cap_pct
) VALUES
(1, 'sovereign_maw', 'Sovereign Maw', 'Predator instinct: your strikes bite deeper; critical openings come easier. Resonates with flame-aligned Dao and technique manuals.',
    0.0420, 0.0000, 0.0220, 0.0000, 0.0000, 0.0000,
    'flame', 0.0450, 1, 0.0150, 0.0650),
(2, 'trial_aegis', 'Trial Aegis', 'Heaven-forged restraint: incoming harm slides off meridians tuned to endurance. Resonates with water-aligned Dao and study.',
    0.0000, 0.0550, 0.0100, 0.0000, 0.0000, 0.0000,
    'water', 0.0450, 1, 0.0150, 0.0650),
(3, 'war_sutra_pulse', 'War Sutra Pulse', 'Battle rhythm: you punish overextensions with brutal counters. Resonates with wind-aligned Dao and combat treatises.',
    0.0320, 0.0000, 0.0120, 0.0000, 0.0480, 0.0000,
    'wind', 0.0450, 1, 0.0150, 0.0650),
(4, 'maze_sense', 'Maze Sense', 'Labyrinth sight: you slip strikes that should have connected. Resonates with earth-aligned Dao and geomantic manuals.',
    0.0250, 0.0200, 0.0000, 0.0350, 0.0000, 0.0000,
    'earth', 0.0450, 1, 0.0150, 0.0650);

INSERT IGNORE INTO bloodline_interactions (attacker_bloodline_id, defender_bloodline_id, matchup_outgoing_mult, description) VALUES
(1, 4, 1.0650, 'Crimson momentum hunts those who hide in patterns.'),
(4, 3, 1.0650, 'The maze exhausts open warfare—footing fails, openings widen.'),
(3, 2, 1.0650, 'Relentless strikes shatter calm judgement born of tribulation.'),
(2, 1, 1.0650, 'Measured heaven-force smothers raw, bloody hunger.');
