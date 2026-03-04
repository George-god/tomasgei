-- Phase 2 currency: gold and spirit stones.
-- Run after database_schema.sql.

USE cultivation_rpg;

ALTER TABLE users ADD COLUMN gold BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN spirit_stones BIGINT NOT NULL DEFAULT 0;
