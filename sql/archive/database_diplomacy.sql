-- Diplomacy: sect relations (NAP, rivalry), sect reputation (run on existing DB)

USE cultivation_rpg;

ALTER TABLE sects
    ADD COLUMN IF NOT EXISTS sect_reputation INT NOT NULL DEFAULT 1000 AFTER sect_exp;

CREATE TABLE IF NOT EXISTS sect_relations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lower_sect_id INT UNSIGNED NOT NULL,
    higher_sect_id INT UNSIGNED NOT NULL,
    nap_status ENUM('none', 'pending', 'active') NOT NULL DEFAULT 'none',
    nap_proposed_by_sect_id INT UNSIGNED NULL DEFAULT NULL,
    nap_started_at DATETIME NULL DEFAULT NULL,
    is_rival TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sect_relations_pair (lower_sect_id, higher_sect_id),
    FOREIGN KEY (lower_sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (higher_sect_id) REFERENCES sects(id) ON DELETE CASCADE,
    FOREIGN KEY (nap_proposed_by_sect_id) REFERENCES sects(id) ON DELETE SET NULL,
    INDEX idx_sect_relations_lower (lower_sect_id),
    INDEX idx_sect_relations_higher (higher_sect_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
