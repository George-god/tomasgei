CREATE TABLE IF NOT EXISTS dao_techniques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dao_path_id INT UNSIGNED NOT NULL,
    technique_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    tier ENUM('basic', 'advanced', 'ultimate') NOT NULL,
    damage_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
    cooldown_turns TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cost_type ENUM('stamina', 'hp', 'corruption') NOT NULL,
    cost_value DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    special_effect VARCHAR(50) NOT NULL DEFAULT 'none',
    effect_value DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dao_path_id) REFERENCES dao_paths(id) ON DELETE CASCADE,
    INDEX idx_dao_path_tier (dao_path_id, tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dao_techniques (
    id, dao_path_id, technique_key, name, tier, damage_multiplier, cooldown_turns, cost_type, cost_value, special_effect, effect_value, description
) VALUES
(1, 1, 'ember_palm', 'Ember Palm', 'basic', 1.200, 2, 'stamina', 15.00, 'burn', 0.080, 'A focused palm strike wreathed in controlled flame.'),
(2, 1, 'crimson_wave_slash', 'Crimson Wave Slash', 'advanced', 1.450, 4, 'stamina', 28.00, 'burn', 0.120, 'A sweeping arc of righteous flame that scorches the target.'),
(3, 1, 'vermilion_sun_cataclysm', 'Vermilion Sun Cataclysm', 'ultimate', 1.900, 6, 'hp', 6.00, 'burn', 0.180, 'Condenses a blazing sun into one devastating strike.'),
(4, 2, 'ripple_cut', 'Ripple Cut', 'basic', 1.100, 2, 'stamina', 15.00, 'heal', 0.100, 'A fluid cut that restores a sliver of vitality.'),
(5, 2, 'deep_tide_sever', 'Deep Tide Sever', 'advanced', 1.350, 4, 'stamina', 26.00, 'heal', 0.140, 'Crashing tides wash wounds away while battering the foe.'),
(6, 2, 'ocean_mirror_domain', 'Ocean Mirror Domain', 'ultimate', 1.650, 6, 'hp', 5.00, 'stone_guard', 0.180, 'A reflected ocean current shields the user after impact.'),
(7, 3, 'gale_step_strike', 'Gale Step Strike', 'basic', 1.150, 2, 'stamina', 14.00, 'windstep', 0.100, 'A swift strike that leaves afterimages in the wind.'),
(8, 3, 'sky_rend_tempest', 'Sky Rend Tempest', 'advanced', 1.400, 4, 'stamina', 26.00, 'windstep', 0.150, 'A storm-laced lunge that sharpens the next evasion.'),
(9, 3, 'nine_heavens_velocity', 'Nine Heavens Velocity', 'ultimate', 1.750, 6, 'hp', 5.00, 'windstep', 0.220, 'Velocity from the upper heavens tears through everything ahead.'),
(10, 4, 'stonebreaker_fist', 'Stonebreaker Fist', 'basic', 1.180, 2, 'stamina', 14.00, 'stone_guard', 0.100, 'A stable fist that raises a brief earthen guard.'),
(11, 4, 'mountain_shaking_descent', 'Mountain Shaking Descent', 'advanced', 1.420, 4, 'stamina', 27.00, 'stone_guard', 0.140, 'Mountain force crashes down and hardens the cultivator.'),
(12, 4, 'world_root_bastion', 'World Root Bastion', 'ultimate', 1.700, 6, 'hp', 5.00, 'reflect', 0.180, 'Roots into the world itself and returns incoming force.'),
(13, 5, 'bloodfire_claw', 'Bloodfire Claw', 'basic', 1.300, 2, 'hp', 4.00, 'burn', 0.100, 'Demonic flames feed on the user to rip into the enemy.'),
(14, 5, 'hellflame_surge', 'Hellflame Surge', 'advanced', 1.600, 4, 'hp', 7.00, 'burn', 0.150, 'A feral surge of hellfire scorches all restraint away.'),
(15, 5, 'infernal_devouring_sun', 'Infernal Devouring Sun', 'ultimate', 2.100, 6, 'corruption', 28.00, 'burn', 0.220, 'The infernal sun devours purity for unmatched destructive power.'),
(16, 6, 'abyssal_drain', 'Abyssal Drain', 'basic', 1.200, 2, 'corruption', 10.00, 'heal', 0.140, 'Dark waters leech vitality from the foe.'),
(17, 6, 'black_tide_collapse', 'Black Tide Collapse', 'advanced', 1.500, 4, 'corruption', 18.00, 'heal', 0.180, 'An abyssal wave collapses inward and restores the user.'),
(18, 6, 'sea_of_grievances', 'Sea of Grievances', 'ultimate', 1.950, 6, 'corruption', 30.00, 'stone_guard', 0.200, 'A grieving sea swallows force and turns it aside.'),
(19, 7, 'ghost_gale_reap', 'Ghost Gale Reap', 'basic', 1.280, 2, 'hp', 3.00, 'windstep', 0.120, 'A reaping gale that slices both foe and meridian.'),
(20, 7, 'voidstep_slaughter', 'Voidstep Slaughter', 'advanced', 1.620, 4, 'hp', 6.00, 'windstep', 0.180, 'A slaughtering step through distorted wind currents.'),
(21, 7, 'thousand_wraith_tempest', 'Thousand Wraith Tempest', 'ultimate', 2.050, 6, 'corruption', 26.00, 'windstep', 0.240, 'Wraithlike winds carve the battlefield into ribbons.'),
(22, 8, 'gravecrusher_stance', 'Gravecrusher Stance', 'basic', 1.250, 2, 'corruption', 12.00, 'stone_guard', 0.120, 'A grave-heavy stance that blunts the next assault.'),
(23, 8, 'abyssal_mountain_slam', 'Abyssal Mountain Slam', 'advanced', 1.580, 4, 'corruption', 20.00, 'reflect', 0.150, 'An abyssal mountain crash that punishes retaliation.'),
(24, 8, 'netherworld_citadel', 'Netherworld Citadel', 'ultimate', 1.900, 6, 'corruption', 32.00, 'reflect', 0.220, 'A citadel of the underworld repels all who strike it.');

ALTER TABLE battle_challenges
    ADD COLUMN IF NOT EXISTS attacker_use_techniques TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN IF NOT EXISTS defender_use_techniques TINYINT(1) NOT NULL DEFAULT 1 AFTER attacker_use_techniques;
