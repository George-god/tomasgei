-- World region expansion for exploration system.
-- Run on existing databases that already applied database_world_map.sql.

USE cultivation_rpg;

ALTER TABLE world_regions ADD COLUMN resource_type VARCHAR(100) NOT NULL DEFAULT 'Mixed Resources';
ALTER TABLE world_regions ADD COLUMN exploration_encounters TEXT NULL;
ALTER TABLE world_regions ADD COLUMN hidden_dungeon_chance DECIMAL(5,2) NOT NULL DEFAULT 1.00;
ALTER TABLE world_regions ADD COLUMN can_be_captured TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO world_regions (id, name, difficulty, description, min_realm_id, resource_type, exploration_encounters, hidden_dungeon_chance, can_be_captured) VALUES
(1, 'Spirit Forest', 1, 'Outer Mortal Lands. Ancient trees, spirit herbs, and hidden trails shelter weaker beasts and wandering rogues.', 1, 'Herbs and spirit roots', 'Wild Beast, Forest Bandit, Rogue Cultivator', 1.00, 0),
(2, 'Iron Mountains', 2, 'Outer Mortal Lands. Jagged ridges rich in ore where mountain wolves and ruthless scavengers roam.', 1, 'Ore and metal-bearing stone', 'Mountain Wolf, Rogue Miner, Wild Beast', 1.00, 0),
(3, 'Mist Valley', 3, 'Outer Mortal Lands. Dense mist conceals herbs, spirit dew, and ambushes from lurking enemies.', 1, 'Mist herbs and spirit dew', 'Mist Serpent, Rogue Cultivator, Forest Predator', 1.10, 0),
(4, 'Thunder Plateau', 4, 'Spirit Wilderness. Storm-charged grasslands where thunder beasts gather around lightning-struck ore.', 2, 'Thunder ore and storm herbs', 'Thunder Beast, Storm Hawk, Plateau Raider', 1.25, 1),
(5, 'Blood Sand Dunes', 5, 'Spirit Wilderness. Red dunes hide relic fragments, buried ore, and dangerous desert predators.', 2, 'Desert minerals and relic shards', 'Sand Stalker, Blood Raider, Dune Beast', 1.30, 1),
(6, 'Frozen Spirit Lake', 6, 'Spirit Wilderness. A frozen lake filled with spirit ice, cold-resistant herbs, and prowling frost creatures.', 2, 'Spirit ice and frost herbs', 'Frost Wolf, Ice Wraith, Frozen Cultivator', 1.35, 1),
(7, 'Celestial Bamboo Grove', 7, 'Ancient Inner Realms. A sacred grove dense with refined spirit herbs and hidden guardians.', 3, 'Celestial herbs and bamboo essence', 'Bamboo Guardian, Spirit Ape, Celestial Disciple', 1.55, 1),
(8, 'Abyssal Canyon', 8, 'Ancient Inner Realms. Deep ravines rich in rare ore where abyssal creatures and fallen cultivators linger.', 3, 'Abyss ore and dark crystals', 'Abyss Beast, Fallen Cultivator, Canyon Reaper', 1.70, 1),
(9, 'Ruins of the Fallen Sect', 9, 'Ancient Inner Realms. Collapsed halls hold relics, elite enemies, and the strongest dungeon traces.', 4, 'Ancient relics and sect remnants', 'Fallen Elder, Ruin Guardian, Sect Shade', 2.00, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    difficulty = VALUES(difficulty),
    description = VALUES(description),
    min_realm_id = VALUES(min_realm_id),
    resource_type = VALUES(resource_type),
    exploration_encounters = VALUES(exploration_encounters),
    hidden_dungeon_chance = VALUES(hidden_dungeon_chance),
    can_be_captured = VALUES(can_be_captured);
