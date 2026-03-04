-- Phase 1 Core Gameplay - Run after main database_schema.sql
-- No professions, crafting, world bosses, or advanced features.
-- Includes: item_templates (with drop_chance), inventory, equipment_slots, npcs, pve_battles, seeds.
--
-- If item_templates already exists without drop_chance, run once:
--   ALTER TABLE item_templates ADD COLUMN drop_chance FLOAT NOT NULL DEFAULT 0 AFTER hp_bonus;

USE cultivation_rpg;

-- ============================================
-- ITEM TEMPLATES (flat stat bonuses only)
-- ============================================
CREATE TABLE IF NOT EXISTS item_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('weapon', 'armor', 'accessory') NOT NULL,
    attack_bonus INT NOT NULL DEFAULT 0,
    defense_bonus INT NOT NULL DEFAULT 0,
    hp_bonus INT NOT NULL DEFAULT 0,
    drop_chance FLOAT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INVENTORY (references item_templates)
-- ============================================
CREATE TABLE IF NOT EXISTS inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    item_template_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    is_equipped TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_template_id) REFERENCES item_templates(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_item_template_id (item_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EQUIPMENT SLOTS (references inventory.id)
-- ============================================
CREATE TABLE IF NOT EXISTS equipment_slots (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    weapon_id INT UNSIGNED NULL,
    armor_id INT UNSIGNED NULL,
    accessory_1_id INT UNSIGNED NULL,
    accessory_2_id INT UNSIGNED NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (weapon_id) REFERENCES inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (armor_id) REFERENCES inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (accessory_1_id) REFERENCES inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (accessory_2_id) REFERENCES inventory(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NPCs (basic - chi reward only)
-- ============================================
CREATE TABLE IF NOT EXISTS npcs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    realm_id INT UNSIGNED NOT NULL DEFAULT 1,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    base_hp BIGINT UNSIGNED NOT NULL DEFAULT 100,
    base_attack INT UNSIGNED NOT NULL DEFAULT 10,
    base_defense INT UNSIGNED NOT NULL DEFAULT 10,
    reward_chi INT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (realm_id) REFERENCES realms(id) ON DELETE RESTRICT,
    INDEX idx_realm_id (realm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PVE BATTLES (for logs only, chi reward applied on win)
-- ============================================
CREATE TABLE IF NOT EXISTS pve_battles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    npc_id INT UNSIGNED NOT NULL,
    winner ENUM('user', 'npc') NOT NULL,
    user_chi_start BIGINT UNSIGNED NOT NULL,
    user_chi_after BIGINT UNSIGNED NOT NULL,
    turns INT UNSIGNED NOT NULL DEFAULT 0,
    chi_reward INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (npc_id) REFERENCES npcs(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed item templates (flat bonuses; drop_chance for PvE drops)
INSERT IGNORE INTO item_templates (id, name, type, attack_bonus, defense_bonus, hp_bonus, drop_chance) VALUES
(1, 'Rusty Blade', 'weapon', 5, 0, 0, 0.25),
(2, 'Spirit Robe', 'armor', 0, 5, 20, 0.20),
(3, 'Jade Pendant', 'accessory', 2, 2, 10, 0.15);

-- Seed NPCs
INSERT IGNORE INTO npcs (id, name, realm_id, level, base_hp, base_attack, base_defense, reward_chi) VALUES
(1, 'Wild Beast', 1, 1, 80, 8, 5, 15),
(2, 'Rogue Cultivator', 1, 2, 100, 10, 8, 25);
