-- Bloodline evolution: tier progression (awakened → evolved → transcendent → mythic).
-- Requires database_bloodlines.sql. Adds materials, title achievements, catalyst item, success/fail rolls, optional mutations.
USE cultivation_rpg;

-- Mutation templates: rolled on successful evolution (see mutation_chance_pct on bloodline_evolution).
CREATE TABLE IF NOT EXISTS bloodline_mutation_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mutation_key VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(400) NOT NULL DEFAULT '',
    passive_bonus_mult DECIMAL(8,4) NOT NULL DEFAULT 0.0400 COMMENT 'Extra multiplier on bloodline passives & effect value (e.g. 0.04 = +4%)',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bloodline_mutation_templates (id, mutation_key, display_name, description, passive_bonus_mult, sort_order) VALUES
(1, 'vermilion_fork', 'Vermilion Fork', 'Your meridians branch into a crimson lattice—raw intensity, imperfect control.', 0.0450, 1),
(2, 'azure_inversion', 'Azure Inversion', 'Yin currents reverse along one vessel; your lineage whispers of drowned stars.', 0.0400, 2),
(3, 'goldleaf_seal', 'Goldleaf Seal', 'A latent seal frays; power leaks outward as warm, steady amplification.', 0.0550, 3),
(4, 'void_humor', 'Void Humor', 'An emptiness mixes with your blood—not hunger, but quiet subtraction.', 0.0350, 4),
(5, 'thunder_root', 'Thunder Root', 'A tribulation echo roots in the bone. Your pulse answers storms first.', 0.0500, 5);

ALTER TABLE user_bloodlines
    ADD COLUMN IF NOT EXISTS evolution_tier ENUM('awakened','evolved','transcendent','mythic') NOT NULL DEFAULT 'awakened' AFTER awakening_level,
    ADD COLUMN IF NOT EXISTS mutation_template_id INT UNSIGNED NULL DEFAULT NULL AFTER evolution_tier,
    ADD COLUMN IF NOT EXISTS mutation_stack TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mutation_template_id;

-- MySQL 8.0.29+ supports ADD COLUMN IF NOT EXISTS; for older servers, add columns manually.
-- On re-run, omit the ADD CONSTRAINT if fk_ub_mutation_template already exists.

ALTER TABLE user_bloodlines
    ADD CONSTRAINT fk_ub_mutation_template FOREIGN KEY (mutation_template_id) REFERENCES bloodline_mutation_templates(id) ON DELETE SET NULL;

INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, scroll_effect, rarity, material_tier, gear_tier) VALUES
(80, 'Lineage Catalyst', 'consumable', 0, 0, 0, 0, 0, NULL, 'rare', NULL, NULL);

CREATE TABLE IF NOT EXISTS bloodline_evolution (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bloodline_id INT UNSIGNED NOT NULL,
    from_tier ENUM('awakened','evolved','transcendent') NOT NULL,
    to_tier ENUM('evolved','transcendent','mythic') NOT NULL,
    success_chance_pct DECIMAL(5,2) NOT NULL COMMENT '0–100',
    mutation_chance_pct DECIMAL(5,2) NOT NULL DEFAULT 0.0000 COMMENT 'On success, extra roll to mutate / deepen mutation',
    required_gold INT UNSIGNED NOT NULL DEFAULT 0,
    required_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    required_material_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    required_material_qty INT UNSIGNED NOT NULL DEFAULT 0,
    required_title_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Must own this title (achievement)',
    required_item_template_id INT UNSIGNED NULL DEFAULT NULL,
    required_item_qty INT UNSIGNED NOT NULL DEFAULT 0,
    failure_extra_gold INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Taken in addition to attempt cost when roll fails',
    failure_chi_loss_pct DECIMAL(5,2) NOT NULL DEFAULT 0.0000 COMMENT 'Percent of max chi stripped from current chi',
    failure_awakening_levels TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Awakening levels lost (min level 1)',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bloodline_from_tier (bloodline_id, from_tier),
    INDEX idx_bloodline (bloodline_id),
    CONSTRAINT fk_be_bloodline FOREIGN KEY (bloodline_id) REFERENCES bloodlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_be_title FOREIGN KEY (required_title_id) REFERENCES titles(id) ON DELETE SET NULL,
    CONSTRAINT fk_be_item FOREIGN KEY (required_item_template_id) REFERENCES item_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One evolution track per catalog bloodline (IDs 1–4 from database_bloodlines.sql seeds).
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
