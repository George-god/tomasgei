-- Sect Contribution (Phase 2.7). No skill tree, no sect bank.

USE cultivation_rpg;

ALTER TABLE sect_members ADD COLUMN contribution INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE sect_members ADD INDEX idx_sect_contribution (sect_id, contribution);
