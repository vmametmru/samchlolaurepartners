-- Fourth block of an "Offre Complète" (see db/migrations/064_create_packages.sql):
-- "Restauration" — meal plans/catering offered with the package. Same shape
-- as package_activities: mandatory entries are always included in the price,
-- optional ones can be ticked by the client on the public page.
--
-- Additive only: an offer created before this migration simply has no meal
-- rows and behaves exactly as before.
CREATE TABLE IF NOT EXISTS package_meals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  label VARCHAR(190) NOT NULL,
  label_en VARCHAR(190) DEFAULT NULL,
  description MEDIUMTEXT DEFAULT NULL,
  description_en MEDIUMTEXT DEFAULT NULL,
  photo_url VARCHAR(500) DEFAULT NULL,
  price_mode VARCHAR(20) NOT NULL DEFAULT 'per_person',
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  KEY idx_package_meals_package (package_id),
  CONSTRAINT fk_package_meals_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
