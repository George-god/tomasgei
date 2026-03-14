ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
    ADD INDEX IF NOT EXISTS idx_is_admin (is_admin);

CREATE TABLE IF NOT EXISTS bug_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(150) NOT NULL,
    status ENUM('observing', 'investigating', 'resolved') NOT NULL DEFAULT 'observing',
    admin_reply TEXT NULL,
    admin_user_id INT UNSIGNED NULL DEFAULT NULL,
    replied_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_bug_reports_user (user_id, created_at),
    INDEX idx_bug_reports_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
