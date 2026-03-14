CREATE TABLE IF NOT EXISTS dao_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NULL DEFAULT NULL,
    description TEXT NOT NULL,
    context_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dao_records_event (event_type, created_at),
    INDEX idx_dao_records_user (user_id, created_at),
    INDEX idx_dao_records_target (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
