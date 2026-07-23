-- Repairs installs affected by a Migrator bug where migration 025 was
-- recorded as "applied" even though its ALTER TABLE statements (each
-- preceded by a block of "--" comment lines) were silently skipped, so
-- email_templates.language / reservation_requests.language never actually
-- got created (causing "Unknown column 'et.language'" errors on the admin
-- templates pages). Every step below is idempotent (checked against
-- information_schema first) so it is safe to run again even on installs
-- where migration 025 already applied correctly.
SET @et_lang_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'language'
);
SET @et_lang_sql = IF(
  @et_lang_exists = 0,
  'ALTER TABLE email_templates ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''fr'' AFTER type',
  'SELECT 1'
);
PREPARE et_lang_stmt FROM @et_lang_sql;
EXECUTE et_lang_stmt;
DEALLOCATE PREPARE et_lang_stmt;

SET @old_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND INDEX_NAME = 'unique_partner_type'
);
SET @drop_old_index_sql = IF(
  @old_index_exists > 0,
  'ALTER TABLE email_templates DROP INDEX unique_partner_type',
  'SELECT 1'
);
PREPARE drop_old_index_stmt FROM @drop_old_index_sql;
EXECUTE drop_old_index_stmt;
DEALLOCATE PREPARE drop_old_index_stmt;

SET @new_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND INDEX_NAME = 'unique_partner_type_lang'
);
SET @add_new_index_sql = IF(
  @new_index_exists = 0,
  'ALTER TABLE email_templates ADD UNIQUE KEY unique_partner_type_lang (partner_id, type, language)',
  'SELECT 1'
);
PREPARE add_new_index_stmt FROM @add_new_index_sql;
EXECUTE add_new_index_stmt;
DEALLOCATE PREPARE add_new_index_stmt;

SET @rr_lang_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND COLUMN_NAME = 'language'
);
SET @rr_lang_sql = IF(
  @rr_lang_exists = 0,
  'ALTER TABLE reservation_requests ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''fr'' AFTER client_phone',
  'SELECT 1'
);
PREPARE rr_lang_stmt FROM @rr_lang_sql;
EXECUTE rr_lang_stmt;
DEALLOCATE PREPARE rr_lang_stmt;
