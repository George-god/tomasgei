-- Heavenly Dao Administration Panel - Run after database_schema.sql
-- Adds ban and warning support for cultivators

USE cultivation_rpg;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin,
    ADD COLUMN IF NOT EXISTS ban_reason TEXT NULL AFTER is_banned,
    ADD COLUMN IF NOT EXISTS banned_at DATETIME NULL AFTER ban_reason,
    ADD COLUMN IF NOT EXISTS banned_by INT UNSIGNED NULL AFTER banned_at,
    ADD INDEX idx_is_banned (is_banned);

CREATE TABLE IF NOT EXISTS user_warnings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    admin_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_warnings_user (user_id),
    INDEX idx_user_warnings_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
