ALTER TABLE users
    ADD INDEX idx_rating_wins_id (rating DESC, wins DESC, id ASC);

ALTER TABLE world_bosses
    ADD INDEX idx_alive_hp_end (is_alive, current_hp, end_time);

ALTER TABLE boss_damage_log
    ADD INDEX idx_boss_damage_order (boss_id, damage_dealt DESC, last_hit ASC, user_id ASC);

ALTER TABLE sects
    ADD INDEX idx_sect_exp_id (sect_exp DESC, id ASC);

ALTER TABLE sect_members
    ADD INDEX idx_sect_contribution_joined (sect_id, contribution DESC, joined_at ASC);

ALTER TABLE era_rankings
    ADD INDEX idx_wins_rating (wins DESC, final_rating DESC);

ALTER TABLE era_sect_rankings
    ADD INDEX idx_territories_rating (territories_controlled DESC, final_rating DESC);
