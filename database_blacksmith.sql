-- Phase 2 Blacksmith profession (core).
-- Run after database_alchemist.sql (professions, user_professions, item_templates with herb/pill).

USE cultivation_rpg;

-- Add Blacksmith profession
INSERT IGNORE INTO professions (id, name, description) VALUES
(2, 'Blacksmith', 'Craft weapons and armor from materials. Higher level increases success rate.');

-- ============================================
-- ITEM TYPE: material
-- ============================================
ALTER TABLE item_templates MODIFY COLUMN type ENUM('weapon', 'armor', 'accessory', 'consumable', 'herb', 'pill', 'material') NOT NULL;

-- Material (PvE drops)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus) VALUES
(30, 'Iron Ore', 'material', 0, 0, 0, 0, 0);

-- Craftable equipment (result items)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus) VALUES
(31, 'Iron Sword', 'weapon', 5, 0, 0, 0, 0),
(32, 'Iron Armor', 'armor', 0, 4, 20, 0, 0),
(33, 'Reinforced Ring', 'accessory', 2, 2, 0, 0, 0);

-- ============================================
-- CRAFTING RECIPES (Blacksmith)
-- ============================================
CREATE TABLE IF NOT EXISTS crafting_recipes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    result_item_template_id INT UNSIGNED NOT NULL,
    required_materials INT UNSIGNED NOT NULL DEFAULT 1,
    gold_cost INT UNSIGNED NOT NULL DEFAULT 0,
    base_success_rate FLOAT NOT NULL DEFAULT 0.5,
    exp_reward INT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (result_item_template_id) REFERENCES item_templates(id) ON DELETE CASCADE,
    INDEX idx_result (result_item_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO crafting_recipes (id, name, result_item_template_id, required_materials, gold_cost, base_success_rate, exp_reward) VALUES
(1, 'Iron Sword', 31, 2, 15, 0.65, 20),
(2, 'Iron Armor', 32, 3, 25, 0.55, 25),
(3, 'Reinforced Ring', 33, 2, 20, 0.60, 18);
