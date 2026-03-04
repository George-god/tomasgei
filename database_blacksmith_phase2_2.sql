-- Blacksmith Phase 2.2: material tiers, gear tiers, quality roll, level requirements.
-- Run after database_blacksmith.sql.

USE cultivation_rpg;

-- ============================================
-- item_templates: material_tier, gear_tier
-- ============================================
ALTER TABLE item_templates ADD COLUMN material_tier INT UNSIGNED NULL DEFAULT NULL COMMENT '1=Iron, 2=Refined, 3=Spirit Steel';
ALTER TABLE item_templates ADD COLUMN gear_tier INT UNSIGNED NULL DEFAULT NULL COMMENT '1/2/3 for craftable gear';

UPDATE item_templates SET material_tier = 1 WHERE id = 30;
UPDATE item_templates SET gear_tier = 1 WHERE id IN (31, 32, 33);

-- New materials (tier 2, 3)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, material_tier, gear_tier) VALUES
(35, 'Refined Iron', 'material', 0, 0, 0, 0, 0, 2, NULL),
(36, 'Spirit Steel', 'material', 0, 0, 0, 0, 0, 3, NULL);

-- Tier 1 Excellent variants (+10% stats)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, material_tier, gear_tier) VALUES
(34, 'Iron Sword (Excellent)', 'weapon', 6, 0, 0, 0, 0, NULL, 1),
(37, 'Iron Armor (Excellent)', 'armor', 0, 5, 22, 0, 0, NULL, 1),
(38, 'Reinforced Ring (Excellent)', 'accessory', 3, 3, 0, 0, 0, NULL, 1);

-- Tier 2 gear: +8-10 ATK weapon, armor +8 def +40 hp range, accessory +4
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, material_tier, gear_tier) VALUES
(40, 'Refined Iron Blade', 'weapon', 9, 0, 0, 0, 0, NULL, 2),
(41, 'Refined Iron Blade (Excellent)', 'weapon', 10, 0, 0, 0, 0, NULL, 2),
(42, 'Refined Plate', 'armor', 0, 8, 40, 0, 0, NULL, 2),
(43, 'Refined Plate (Excellent)', 'armor', 0, 9, 44, 0, 0, NULL, 2),
(44, 'Steel Band', 'accessory', 4, 4, 0, 0, 0, NULL, 2),
(45, 'Steel Band (Excellent)', 'accessory', 5, 5, 0, 0, 0, NULL, 2);

-- Tier 3 gear: +14-16 ATK, armor +12 def +60 hp, accessory +6
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance, breakthrough_bonus, material_tier, gear_tier) VALUES
(50, 'Spirit Steel Saber', 'weapon', 15, 0, 0, 0, 0, NULL, 3),
(51, 'Spirit Steel Saber (Excellent)', 'weapon', 16, 0, 0, 0, 0, NULL, 3),
(52, 'Spirit Cuirass', 'armor', 0, 12, 60, 0, 0, NULL, 3),
(53, 'Spirit Cuirass (Excellent)', 'armor', 0, 13, 66, 0, 0, NULL, 3),
(54, 'Spirit Ring', 'accessory', 6, 6, 0, 0, 0, NULL, 3),
(55, 'Spirit Ring (Excellent)', 'accessory', 7, 7, 0, 0, 0, NULL, 3);

-- ============================================
-- crafting_recipes: required_material_tier, required_profession_level, result_excellent
-- ============================================
ALTER TABLE crafting_recipes ADD COLUMN required_material_tier INT UNSIGNED NOT NULL DEFAULT 1 AFTER result_item_template_id;
ALTER TABLE crafting_recipes ADD COLUMN required_profession_level INT UNSIGNED NOT NULL DEFAULT 1 AFTER exp_reward;
ALTER TABLE crafting_recipes ADD COLUMN result_item_template_id_excellent INT UNSIGNED NULL DEFAULT NULL AFTER result_item_template_id;
ALTER TABLE crafting_recipes ADD CONSTRAINT fk_result_excellent FOREIGN KEY (result_item_template_id_excellent) REFERENCES item_templates(id) ON DELETE SET NULL;

UPDATE crafting_recipes SET required_material_tier = 1, required_profession_level = 1, result_item_template_id_excellent = 34 WHERE id = 1;
UPDATE crafting_recipes SET required_material_tier = 1, required_profession_level = 1, result_item_template_id_excellent = 37 WHERE id = 2;
UPDATE crafting_recipes SET required_material_tier = 1, required_profession_level = 1, result_item_template_id_excellent = 38 WHERE id = 3;

INSERT IGNORE INTO crafting_recipes (id, name, result_item_template_id, result_item_template_id_excellent, required_material_tier, required_materials, gold_cost, base_success_rate, exp_reward, required_profession_level) VALUES
(4, 'Refined Iron Blade', 40, 41, 2, 2, 35, 0.60, 28, 5),
(5, 'Refined Plate', 42, 43, 2, 3, 45, 0.55, 32, 5),
(6, 'Steel Band', 44, 45, 2, 2, 40, 0.58, 30, 5),
(7, 'Spirit Steel Saber', 50, 51, 3, 2, 60, 0.55, 35, 10),
(8, 'Spirit Cuirass', 52, 53, 3, 3, 75, 0.50, 40, 10),
(9, 'Spirit Ring', 54, 55, 3, 2, 65, 0.52, 38, 10);
