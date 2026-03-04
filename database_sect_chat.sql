-- Sect Chat (Phase 2.5). Lightweight, sect-only. No WebSockets.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS sect_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sect_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sect_created (sect_id, created_at),
    FOREIGN KEY (sect_id) REFERENCES sects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN last_chat_message_at DATETIME NULL DEFAULT NULL;
