-- Phase 2 refactor: query optimization indexes.
-- Run after database_sect_contribution.sql and database_marketplace.sql.

USE cultivation_rpg;

-- Marketplace: active listings by created_at (getActiveListings)
ALTER TABLE marketplace_listings ADD INDEX idx_status_created (status, created_at);
