-- Daily & Weekly Activity tasks (run once on your DB)
-- Tracks per-user progress per calendar day / ISO week; rewards granted on completion.

CREATE TABLE IF NOT EXISTS daily_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    task_key VARCHAR(32) NOT NULL,
    period_date DATE NOT NULL COMMENT 'Server calendar day (Y-m-d)',
    target_value INT UNSIGNED NOT NULL DEFAULT 1,
    progress INT UNSIGNED NOT NULL DEFAULT 0,
    reward_gold INT UNSIGNED NOT NULL DEFAULT 0,
    reward_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_day_task (user_id, period_date, task_key),
    INDEX idx_user_day (user_id, period_date),
    CONSTRAINT fk_daily_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    task_key VARCHAR(32) NOT NULL,
    week_start DATE NOT NULL COMMENT 'Monday of week (Y-m-d)',
    target_value INT UNSIGNED NOT NULL DEFAULT 1,
    progress INT UNSIGNED NOT NULL DEFAULT 0,
    reward_gold INT UNSIGNED NOT NULL DEFAULT 0,
    reward_spirit_stones INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_week_task (user_id, week_start, task_key),
    INDEX idx_user_week (user_id, week_start),
    CONSTRAINT fk_weekly_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
