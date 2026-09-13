-- "Offres Complètes" (packages): an agency can bundle a flight, one or
-- several of its accommodations and activities into a single offer shown on
-- a dedicated public page (/offres, /offres/{id}).
--
-- Everything here is additive and hidden by default: partners.packages_visible
-- is 0 for every existing partner, so neither the partner management page
-- (/partner/offres) nor the public pages exist until an admin explicitly
-- enables the option for a given partner (mirrors analytics_visible, see
-- db/migrations/060_add_analytics_visible_to_partners.sql).
--
-- Migrator::run() only records this file once *every* statement below has
-- succeeded, so each ALTER is guarded against information_schema (same
-- pattern as db/migrations/025_add_language_to_templates_and_requests.sql):
-- a failure on a later statement would otherwise make the next run stop on
-- a "Duplicate column" error here and never reach the rest of the schema.
SET @packages_visible_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'packages_visible'
);
SET @packages_visible_sql = IF(
  @packages_visible_exists = 0,
  'ALTER TABLE partners ADD COLUMN packages_visible TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE packages_visible_stmt FROM @packages_visible_sql;
EXECUTE packages_visible_stmt;
DEALLOCATE PREPARE packages_visible_stmt;

-- expires_at is stored in UTC like every other timestamp in this database,
-- but is entered/displayed in GMT+4 (Île Maurice) on both the partner form
-- and the public page.
-- stock_mode: 'none' (unlimited), 'bookings' (offer closes after N requests)
-- or 'persons' (offer closes once N travellers have booked it) — the partner
-- chooses which unit the stock is counted in.
CREATE TABLE IF NOT EXISTS packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  partner_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  title_en VARCHAR(190) DEFAULT NULL,
  description MEDIUMTEXT DEFAULT NULL,
  description_en MEDIUMTEXT DEFAULT NULL,
  photo_url VARCHAR(500) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  all_properties TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME DEFAULT NULL,
  stock_mode VARCHAR(20) NOT NULL DEFAULT 'none',
  stock_limit INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_packages_partner (partner_id),
  CONSTRAINT fk_packages_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Several flight options per package (e.g. "Économie" / "Affaires"): the
-- client picks exactly one of them on the public page.
-- price_mode: 'per_person' (amount x travellers) or 'per_group' (flat amount).
CREATE TABLE IF NOT EXISTS package_flights (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  label VARCHAR(190) NOT NULL,
  airline VARCHAR(190) DEFAULT NULL,
  cabin_class VARCHAR(190) DEFAULT NULL,
  description MEDIUMTEXT DEFAULT NULL,
  price_mode VARCHAR(20) NOT NULL DEFAULT 'per_person',
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  KEY idx_package_flights_package (package_id),
  CONSTRAINT fk_package_flights_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Only used when packages.all_properties = 0.
CREATE TABLE IF NOT EXISTS package_properties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  property_id INT NOT NULL,
  UNIQUE KEY uniq_package_property (package_id, property_id),
  CONSTRAINT fk_package_properties_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- is_mandatory = 1: always included in the price and not unselectable by the
-- client; 0: optional extra the client may tick on the public page.
CREATE TABLE IF NOT EXISTS package_activities (
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
  KEY idx_package_activities_package (package_id),
  CONSTRAINT fk_package_activities_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A reservation request created from an offer stays a completely normal
-- request (same partner/admin screens, same emails, same /r/{token} link);
-- these two columns only record which offer it came from and what the client
-- selected in it. Existing requests keep NULL and behave exactly as before.
--
-- package_id is indexed: Packages::usedStock() aggregates on it for every
-- stock-limited offer (once per offer on the management/public lists), which
-- would otherwise scan the whole reservation_requests table each time.
SET @rr_package_id_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND COLUMN_NAME = 'package_id'
);
SET @rr_package_id_sql = IF(
  @rr_package_id_exists = 0,
  'ALTER TABLE reservation_requests ADD COLUMN package_id INT DEFAULT NULL',
  'DO 0'
);
PREPARE rr_package_id_stmt FROM @rr_package_id_sql;
EXECUTE rr_package_id_stmt;
DEALLOCATE PREPARE rr_package_id_stmt;

SET @rr_package_summary_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND COLUMN_NAME = 'package_summary'
);
SET @rr_package_summary_sql = IF(
  @rr_package_summary_exists = 0,
  'ALTER TABLE reservation_requests ADD COLUMN package_summary MEDIUMTEXT DEFAULT NULL',
  'DO 0'
);
PREPARE rr_package_summary_stmt FROM @rr_package_summary_sql;
EXECUTE rr_package_summary_stmt;
DEALLOCATE PREPARE rr_package_summary_stmt;

SET @rr_package_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND INDEX_NAME = 'idx_reservation_requests_package'
);
SET @rr_package_index_sql = IF(
  @rr_package_index_exists = 0,
  'ALTER TABLE reservation_requests ADD KEY idx_reservation_requests_package (package_id, status)',
  'DO 0'
);
PREPARE rr_package_index_stmt FROM @rr_package_index_sql;
EXECUTE rr_package_index_stmt;
DEALLOCATE PREPARE rr_package_index_stmt;
