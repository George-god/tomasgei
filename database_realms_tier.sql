-- Realm Tier: single multiplier, controlled exponential scaling.
-- Run after database_schema.sql. users.realm_id already exists.
-- If required_level or multiplier already exist, skip the corresponding ALTER.

USE cultivation_rpg;

ALTER TABLE realms ADD COLUMN required_level INT UNSIGNED NOT NULL DEFAULT 1 AFTER name;
ALTER TABLE realms ADD COLUMN multiplier FLOAT NOT NULL DEFAULT 1.00 AFTER required_level;

-- Controlled exponential scaling (example values)
UPDATE realms SET required_level = min_level, multiplier = 1.00 WHERE id = 1;
UPDATE realms SET required_level = min_level, multiplier = 1.12 WHERE id = 2;
UPDATE realms SET required_level = min_level, multiplier = 1.25 WHERE id = 3;
UPDATE realms SET required_level = min_level, multiplier = 1.40 WHERE id = 4;
UPDATE realms SET required_level = min_level, multiplier = 1.57 WHERE id = 5;
