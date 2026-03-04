-- Upgrade: 1 Main + 1 Secondary profession, Spirit Herbalist, herb_plots.
-- Run after database_blacksmith.sql (professions 1 and 2 exist).

USE cultivation_rpg;

-- ============================================
-- USER_PROFESSIONS: is_main -> role ENUM
-- ============================================
ALTER TABLE user_professions ADD COLUMN role ENUM('main','secondary') NOT NULL DEFAULT 'secondary' AFTER experience;
UPDATE user_professions SET role = 'main' WHERE is_main = 1;
UPDATE user_professions SET role = 'secondary' WHERE is_main = 0;
-- Keep only one secondary per user (smallest profession_id)
DELETE up1 FROM user_professions up1
INNER JOIN user_professions up2 ON up1.user_id = up2.user_id AND up1.profession_id > up2.profession_id AND up1.role = 'secondary' AND up2.role = 'secondary';
ALTER TABLE user_professions DROP COLUMN is_main;

-- ============================================
-- SPIRIT HERBALIST PROFESSION
-- ============================================
INSERT IGNORE INTO professions (id, name, description) VALUES
(3, 'Spirit Herbalist', 'Grow herbs in a personal plot. Higher level increases harvest yield.');

-- ============================================
-- HERB_PLOTS (1 active per user; growth 30 min)
-- ============================================
CREATE TABLE IF NOT EXISTS herb_plots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    planted_at TIMESTAMP NULL,
    ready_at TIMESTAMP NULL,
    is_harvested TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
