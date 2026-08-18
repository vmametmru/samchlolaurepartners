-- Reverts migration 048: partners.notify_on_client_edit turned the
-- client-edit notification emails (ReservationsController::
-- updatePublicRequest()) into a general opt-out setting on
-- /partner/settings. A client editing their own pending request must
-- always notify the partner/client — there is no opt-out for that path.
-- The only opt-out is (and always was meant to be) the per-request
-- "Ne pas notifier le client par email" checkbox on the partner's own
-- reservation edit page (/partner/reservations/{id}), which does not use
-- this column. Idempotent (checked against information_schema) so it is
-- safe to run again on installs where migration 048 never applied.
SET @notify_on_client_edit_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'notify_on_client_edit'
);
SET @notify_on_client_edit_sql = IF(
  @notify_on_client_edit_exists > 0,
  'ALTER TABLE partners DROP COLUMN notify_on_client_edit',
  'DO 0'
);
PREPARE notify_on_client_edit_stmt FROM @notify_on_client_edit_sql;
EXECUTE notify_on_client_edit_stmt;
DEALLOCATE PREPARE notify_on_client_edit_stmt;
