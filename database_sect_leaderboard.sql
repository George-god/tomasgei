-- Sect Leaderboard (Phase 2.6). Indexes for ranking by sect_exp.

USE cultivation_rpg;

-- Index for ORDER BY sect_exp DESC (ranking)
ALTER TABLE sects ADD INDEX idx_sect_exp (sect_exp);

-- sect_members already has INDEX idx_sect (sect_id) from Phase 2.4; no change needed.
