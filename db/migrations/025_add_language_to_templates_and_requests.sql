-- Allows email_templates to have one variant per language (FR/EN), so
-- client-facing emails (accusé réception, confirmation, annulation, rappel)
-- can be sent in the same language the guest used the site in, instead of
-- always sending the partner's single French template regardless of the
-- language the visitor actually browsed the site in.
--
-- Every step below is guarded (checked against information_schema first)
-- and the new unique_partner_type_lang index is added BEFORE the old
-- unique_partner_type one is dropped. unique_partner_type is the index
-- InnoDB uses internally to support the email_templates_ibfk_1 foreign key
-- (partner_id -> partners.id): dropping it first, before a replacement
-- index covering partner_id exists, fails with "Cannot drop index
-- 'unique_partner_type': needed in a foreign key constraint" (MySQL error
-- 1553), which aborted this migration on every run before it ever reached
-- the reservation_requests.language column below, causing persistent
-- "Unknown column 'et.language'" / "Unknown column 'rr.language'" errors.
SET @et_lang_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'language'
);
SET @et_lang_sql = IF(
  @et_lang_exists = 0,
  'ALTER TABLE email_templates ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''fr'' AFTER type',
  'DO 0'
);
PREPARE et_lang_stmt FROM @et_lang_sql;
EXECUTE et_lang_stmt;
DEALLOCATE PREPARE et_lang_stmt;

SET @new_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND INDEX_NAME = 'unique_partner_type_lang'
);
SET @add_new_index_sql = IF(
  @new_index_exists = 0,
  'ALTER TABLE email_templates ADD UNIQUE KEY unique_partner_type_lang (partner_id, type, language)',
  'DO 0'
);
PREPARE add_new_index_stmt FROM @add_new_index_sql;
EXECUTE add_new_index_stmt;
DEALLOCATE PREPARE add_new_index_stmt;

SET @old_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND INDEX_NAME = 'unique_partner_type'
);
SET @drop_old_index_sql = IF(
  @old_index_exists > 0,
  'ALTER TABLE email_templates DROP INDEX unique_partner_type',
  'DO 0'
);
PREPARE drop_old_index_stmt FROM @drop_old_index_sql;
EXECUTE drop_old_index_stmt;
DEALLOCATE PREPARE drop_old_index_stmt;

-- Records which site language a visitor used when submitting a reservation
-- request, so RESERVATION_CONFIRMED/RESERVATION_CANCELLED/REMINDER emails
-- sent later (after the request row already exists) can still be sent in
-- that same language.
SET @rr_lang_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation_requests' AND COLUMN_NAME = 'language'
);
SET @rr_lang_sql = IF(
  @rr_lang_exists = 0,
  'ALTER TABLE reservation_requests ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''fr'' AFTER client_phone',
  'DO 0'
);
PREPARE rr_lang_stmt FROM @rr_lang_sql;
EXECUTE rr_lang_stmt;
DEALLOCATE PREPARE rr_lang_stmt;
