-- Phase 2 Alchemist profession (core).
-- Run after database_phase1.sql, database_breakthrough.sql (item_templates with consumable).

USE cultivation_rpg;

-- ============================================
-- PROFESSIONS
-- ============================================
CREATE TABLE IF NOT EXISTS professions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO professions (id, name, description) VALUES
(1, 'Alchemist', 'Craft pills from herbs. Higher level increases success rate.');

-- ============================================
-- USER PROFESSIONS
-- ============================================
CREATE TABLE IF NOT EXISTS user_professions (
    user_id INT UNSIGNED NOT NULL,
    profession_id INT UNSIGNED NOT NULL,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    experience INT UNSIGNED NOT NULL DEFAULT 0,
    is_main TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, profession_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (profession_id) REFERENCES professions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ITEM TYPES: herb, pill
-- ============================================
ALTER TABLE item_templates MODIFY COLUMN type ENUM('weapon', 'armor', 'accessory', 'consumable', 'herb', 'pill') NOT NULL;

-- Herbs (for alchemy; PvE can drop these)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus) VALUES
(10, 'Spirit Grass', 'herb', 0, 0, 0, 0, 0),
(11, 'Moon Petal', 'herb', 0, 0, 0, 0, 0),
(12, 'Sun Root', 'herb', 0, 0, 0, 0, 0);

-- Pills (crafted; result_item_template_id in recipes)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus) VALUES
(20, 'Healing Pill', 'pill', 0, 0, 0, 0, 0),
(21, 'Qi Restoration Pill', 'pill', 0, 0, 0, 0, 0);

-- ============================================
-- ALCHEMY RECIPES
-- ============================================
CREATE TABLE IF NOT EXISTS alchemy_recipes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    result_item_template_id INT UNSIGNED NOT NULL,
    required_herbs INT UNSIGNED NOT NULL DEFAULT 1,
    gold_cost INT UNSIGNED NOT NULL DEFAULT 0,
    base_success_rate FLOAT NOT NULL DEFAULT 0.5,
    exp_reward INT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (result_item_template_id) REFERENCES item_templates(id) ON DELETE CASCADE,
    INDEX idx_result (result_item_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO alchemy_recipes (id, name, result_item_template_id, required_herbs, gold_cost, base_success_rate, exp_reward) VALUES
(1, 'Healing Pill', 20, 2, 10, 0.60, 15),
(2, 'Qi Restoration Pill', 21, 3, 25, 0.50, 25);
