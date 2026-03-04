-- Rune Engraver profession (Phase 2.3). Run after database_professions_upgrade.sql (herb_plots, profession 3).

USE cultivation_rpg;

-- Rune Engraver profession
INSERT IGNORE INTO professions (id, name, description) VALUES
(4, 'Rune Engraver', 'Craft scroll runes from Rune Fragments. Higher level increases success rate.');

-- ============================================
-- ITEM TYPE: scroll. Rune Fragment material.
-- ============================================
ALTER TABLE item_templates MODIFY COLUMN type ENUM('weapon', 'armor', 'accessory', 'consumable', 'herb', 'pill', 'material', 'scroll') NOT NULL;
ALTER TABLE item_templates ADD COLUMN scroll_effect VARCHAR(30) NULL DEFAULT NULL COMMENT 'minor_attack, minor_defense, vitality, focus';

-- Rune Fragment (PvE 20% drop). Uses material type; no material_tier so it doesn't mix with blacksmith ores.
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, scroll_effect) VALUES
(56, 'Rune Fragment', 'material', 0, 0, 0, 0, 0, NULL);

-- Scrolls (crafted). scroll_effect used when activating.
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, scroll_effect) VALUES
(60, 'Minor Attack Rune', 'scroll', 0, 0, 0, 0, 0, 'minor_attack'),
(61, 'Minor Defense Rune', 'scroll', 0, 0, 0, 0, 0, 'minor_defense'),
(62, 'Vitality Rune', 'scroll', 0, 0, 0, 0, 0, 'vitality'),
(63, 'Focus Rune', 'scroll', 0, 0, 0, 0, 0, 'focus');

-- ============================================
-- USERS: active scroll (one at a time)
-- ============================================
ALTER TABLE users ADD COLUMN active_scroll_type VARCHAR(30) NULL DEFAULT NULL COMMENT 'minor_attack, minor_defense, vitality, focus';

-- ============================================
-- RUNE RECIPES
-- ============================================
CREATE TABLE IF NOT EXISTS rune_recipes (
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

INSERT IGNORE INTO rune_recipes (id, name, result_item_template_id, required_materials, gold_cost, base_success_rate, exp_reward) VALUES
(1, 'Minor Attack Rune', 60, 2, 20, 0.60, 18),
(2, 'Minor Defense Rune', 61, 2, 20, 0.60, 18),
(3, 'Vitality Rune', 62, 3, 30, 0.55, 22),
(4, 'Focus Rune', 63, 3, 35, 0.52, 25);
