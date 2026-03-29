-- Bloodline unique combat abilities, Dao/manual resonance, and PvP matchup multipliers.
-- Run after database_bloodlines.sql.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS bloodline_abilities (
    bloodline_id INT UNSIGNED NOT NULL PRIMARY KEY,
    ability_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(700) NOT NULL DEFAULT '',
    combat_damage_out_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Outgoing damage multiplier additive (e.g. 0.04 = +4%)',
    combat_damage_taken_reduction_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Fraction of damage shaved when hit (e.g. 0.05 = 5%)',
    combat_crit_chance_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_dodge_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_counter_bonus DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    combat_lifesteal_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Added to lifesteal potency when lifesteal procs',
    resonance_dao_element ENUM('flame','water','wind','earth') NULL DEFAULT NULL COMMENT 'Match user Dao path element',
    resonance_dao_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0400 COMMENT 'Mult bonus to ability combat values + passives when element matches',
    resonance_min_manuals TINYINT UNSIGNED NOT NULL DEFAULT 1,
    resonance_manual_bonus_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0150 COMMENT 'Per active manual at or above threshold',
    resonance_manual_bonus_cap_pct DECIMAL(8,4) NOT NULL DEFAULT 0.0600,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ba_bloodline FOREIGN KEY (bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bloodline_interactions (
    attacker_bloodline_id INT UNSIGNED NOT NULL,
    defender_bloodline_id INT UNSIGNED NOT NULL,
    matchup_outgoing_mult DECIMAL(6,4) NOT NULL DEFAULT 1.0000 COMMENT 'Attacker damage multiplier vs this defender bloodline',
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

-- Cycle of counters: crimson > labyrinth > war_buddha > heaven > crimson
INSERT IGNORE INTO bloodline_interactions (attacker_bloodline_id, defender_bloodline_id, matchup_outgoing_mult, description) VALUES
(1, 4, 1.0650, 'Crimson momentum hunts those who hide in patterns.'),
(4, 3, 1.0650, 'The maze exhausts open warfare—footing fails, openings widen.'),
(3, 2, 1.0650, 'Relentless strikes shatter calm judgement born of tribulation.'),
(2, 1, 1.0650, 'Measured heaven-force smothers raw, bloody hunger.');
