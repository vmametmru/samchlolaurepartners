-- Flight options (package_flights, see db/migrations/064_create_packages.sql)
-- had no photo, unlike the other offer blocks (package_activities/meals/
-- transports already have photo_url). Adds the same column so a photo can be
-- attached when creating/editing a flight option, in the same way as the
-- other steps.
ALTER TABLE package_flights ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL;
