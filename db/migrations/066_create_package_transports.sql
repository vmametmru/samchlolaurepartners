-- "Transport" block of an "Offre Complète" (see db/migrations/064_create_packages.sql
-- and db/migrations/065_create_package_meals.sql): transfers/car hire offered
-- with the package, proposed to the client in its own step between "Vol" and
-- "Activités" on the public page.
--
-- Exactly the same shape as package_activities/package_meals (a mandatory
-- entry is always included in the price, an optional one can be ticked by the
-- client), so Packages::replaceExtras() handles all three identically.
--
-- Additive only: an offer created before this migration simply has no
-- transport row and behaves exactly as before.
CREATE TABLE IF NOT EXISTS package_transports (
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
  KEY idx_package_transports_package (package_id),
  CONSTRAINT fk_package_transports_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
