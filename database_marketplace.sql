-- Phase 2 marketplace: listings table.
-- Run after database_phase1.sql (inventory, item_templates, users with gold).

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS marketplace_listings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id INT UNSIGNED NOT NULL,
    item_template_id INT UNSIGNED NOT NULL,
    price BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
    FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_template_id) REFERENCES item_templates(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_seller (seller_user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
