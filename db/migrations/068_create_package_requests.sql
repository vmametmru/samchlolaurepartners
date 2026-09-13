-- Logs every submission of an "Offre Complète" (App\Packages,
-- db/migrations/064_create_packages.sql): one row per client submission,
-- independent of how many reservation_requests it actually creates (a
-- same-address multi-property offer submission spreads the party over
-- several properties, i.e. several reservation_requests — see
-- PackagesController::requestSameAddressSelection()).
--
-- status: 'open' (just submitted, nothing decided yet), 'validated'
-- (partner/admin confirms the offer request) or 'cancelled'.
--
-- Guarded the same way as db/migrations/064_create_packages.sql: each ALTER
-- is idempotent so a later failure never blocks a re-run on a "Duplicate
-- column" error from an earlier statement.
CREATE TABLE IF NOT EXISTS package_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  partner_id INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  client_name VARCHAR(190) DEFAULT NULL,
  client_email VARCHAR(190) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_package_requests_package (package_id),
  KEY idx_package_requests_partner_status (partner_id, status),
  CONSTRAINT fk_package_requests_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_requests_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ties every reservation_requests row created from an offer submission back
-- to the package_requests log entry it belongs to (one entry, one-or-more
-- reservation requests). package_id/package_summary (migration 064) are
-- kept as-is; this only adds the missing link to the new log table.
SET @rr_package_request_id_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND COLUMN_NAME = 'package_request_id'
);
SET @rr_package_request_id_sql = IF(
  @rr_package_request_id_exists = 0,
  'ALTER TABLE reservation_requests ADD COLUMN package_request_id INT DEFAULT NULL',
  'DO 0'
);
PREPARE rr_package_request_id_stmt FROM @rr_package_request_id_sql;
EXECUTE rr_package_request_id_stmt;
DEALLOCATE PREPARE rr_package_request_id_stmt;

SET @rr_package_request_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND INDEX_NAME = 'idx_reservation_requests_package_request'
);
SET @rr_package_request_index_sql = IF(
  @rr_package_request_index_exists = 0,
  'ALTER TABLE reservation_requests ADD KEY idx_reservation_requests_package_request (package_request_id)',
  'DO 0'
);
PREPARE rr_package_request_index_stmt FROM @rr_package_request_index_sql;
EXECUTE rr_package_request_index_stmt;
DEALLOCATE PREPARE rr_package_request_index_stmt;

-- Self-service toggle (set by the partner itself, not an admin, from
-- /partner/offres/demandes): when a "Parent" partner (principal of a
-- partner_links hierarchical pair, see files/PartnerLinks.php) enables this,
-- its own package_requests additionally show up on its "Child" partners'
-- /partner/offres/demandes page. Defaults to 0 so nothing changes until a
-- parent explicitly opts in.
SET @packages_force_child_visibility_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'packages_force_child_visibility'
);
SET @packages_force_child_visibility_sql = IF(
  @packages_force_child_visibility_exists = 0,
  'ALTER TABLE partners ADD COLUMN packages_force_child_visibility TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE packages_force_child_visibility_stmt FROM @packages_force_child_visibility_sql;
EXECUTE packages_force_child_visibility_stmt;
DEALLOCATE PREPARE packages_force_child_visibility_stmt;
