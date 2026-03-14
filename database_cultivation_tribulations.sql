-- Cultivation Tribulation system migration for existing databases.
-- Adds multi-phase breakthrough tribulation support.

USE cultivation_rpg;

CREATE TABLE IF NOT EXISTS tribulations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    realm_id_before INT UNSIGNED NOT NULL,
    realm_id_after INT UNSIGNED NULL,
    tribulation_type ENUM('lightning','fire','demonic_heart','void','heavenly_judgment') NOT NULL,
    phase_count TINYINT UNSIGNED NOT NULL DEFAULT 3,
    failed_phase TINYINT UNSIGNED NULL DEFAULT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    difficulty_rating DECIMAL(6,3) NOT NULL DEFAULT 1.000,
    breakthrough_attempts_used INT UNSIGNED NOT NULL DEFAULT 0,
    pill_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    sect_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    rune_type VARCHAR(50) NULL DEFAULT NULL,
    start_chi BIGINT UNSIGNED NOT NULL DEFAULT 0,
    end_chi BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_taken BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (realm_id_before) REFERENCES realms(id) ON DELETE RESTRICT,
    FOREIGN KEY (realm_id_after) REFERENCES realms(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tribulation_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tribulation_id INT UNSIGNED NOT NULL,
    strike_number INT UNSIGNED NOT NULL,
    phase_name VARCHAR(100) NOT NULL DEFAULT '',
    chi_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_dealt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    damage_after_defense BIGINT UNSIGNED NOT NULL DEFAULT 0,
    was_dodged BOOLEAN NOT NULL DEFAULT FALSE,
    chi_after BIGINT UNSIGNED NOT NULL,
    survival_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    phase_result ENUM('survived','failed') NOT NULL DEFAULT 'survived',
    message VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tribulation_id) REFERENCES tribulations(id) ON DELETE CASCADE,
    INDEX idx_tribulation_id (tribulation_id),
    INDEX idx_strike_number (strike_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tribulations ADD COLUMN tribulation_type ENUM('lightning','fire','demonic_heart','void','heavenly_judgment') NOT NULL DEFAULT 'lightning' AFTER realm_id_after;
ALTER TABLE tribulations ADD COLUMN phase_count TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER tribulation_type;
ALTER TABLE tribulations ADD COLUMN failed_phase TINYINT UNSIGNED NULL DEFAULT NULL AFTER phase_count;
ALTER TABLE tribulations ADD COLUMN difficulty_rating DECIMAL(6,3) NOT NULL DEFAULT 1.000 AFTER success;
ALTER TABLE tribulations ADD COLUMN breakthrough_attempts_used INT UNSIGNED NOT NULL DEFAULT 0 AFTER difficulty_rating;
ALTER TABLE tribulations ADD COLUMN pill_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000 AFTER breakthrough_attempts_used;
ALTER TABLE tribulations ADD COLUMN sect_bonus_applied DECIMAL(6,3) NOT NULL DEFAULT 0.000 AFTER pill_bonus_applied;
ALTER TABLE tribulations ADD COLUMN rune_type VARCHAR(50) NULL DEFAULT NULL AFTER sect_bonus_applied;
ALTER TABLE tribulations ADD COLUMN start_chi BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER rune_type;
ALTER TABLE tribulations ADD COLUMN end_chi BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER start_chi;

ALTER TABLE tribulation_logs ADD COLUMN phase_name VARCHAR(100) NOT NULL DEFAULT '' AFTER strike_number;
ALTER TABLE tribulation_logs ADD COLUMN chi_before BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER phase_name;
ALTER TABLE tribulation_logs ADD COLUMN survival_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER chi_after;
ALTER TABLE tribulation_logs ADD COLUMN phase_result ENUM('survived','failed') NOT NULL DEFAULT 'survived' AFTER survival_percent;
ALTER TABLE tribulation_logs ADD COLUMN message VARCHAR(255) NULL DEFAULT NULL AFTER phase_result;
