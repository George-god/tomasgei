-- PvP Stamina (anti-spam) - add columns to users
-- Run after database_schema.sql

USE cultivation_rpg;

ALTER TABLE users
    ADD COLUMN pvp_stamina INT NOT NULL DEFAULT 5,
    ADD COLUMN last_stamina_regen DATETIME NULL DEFAULT NULL;
