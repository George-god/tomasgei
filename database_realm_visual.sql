-- Realm visual polish: description for display.
-- Skip this file if your realms table already has a description column.

USE cultivation_rpg;

ALTER TABLE realms ADD COLUMN description TEXT NULL AFTER name;
