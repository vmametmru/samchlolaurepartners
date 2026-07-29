-- Migration 033 split the REMINDER *template* into REMINDER_CLIENT and
-- REMINDER_PARTNER, but kept a single combined 'REMINDER' row on
-- email_schedules that triggered both templates simultaneously (see
-- Scheduler::runOnce()). This migration finishes the split by turning that
-- one cron entry into two fully independent ones — REMINDER_CLIENT and
-- REMINDER_PARTNER — each editable (days before arrival, active) on its
-- own from /admin/cron, as requested.
ALTER TABLE email_schedules
  MODIFY COLUMN template_type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER'
  ) NOT NULL DEFAULT 'REMINDER_CLIENT';

-- Every existing combined 'REMINDER' row becomes two rows (same
-- partner_id/days_before_arrival/active), unless the equivalent
-- REMINDER_CLIENT/REMINDER_PARTNER row already exists.
INSERT INTO email_schedules (partner_id, days_before_arrival, template_type, active)
SELECT es.partner_id, es.days_before_arrival, 'REMINDER_CLIENT', es.active
FROM email_schedules es
WHERE es.template_type = 'REMINDER'
  AND NOT EXISTS (
    SELECT 1 FROM email_schedules es2
    WHERE es2.template_type = 'REMINDER_CLIENT'
      AND (es2.partner_id <=> es.partner_id)
  );

INSERT INTO email_schedules (partner_id, days_before_arrival, template_type, active)
SELECT es.partner_id, es.days_before_arrival, 'REMINDER_PARTNER', es.active
FROM email_schedules es
WHERE es.template_type = 'REMINDER'
  AND NOT EXISTS (
    SELECT 1 FROM email_schedules es2
    WHERE es2.template_type = 'REMINDER_PARTNER'
      AND (es2.partner_id <=> es.partner_id)
  );

DELETE FROM email_schedules WHERE template_type = 'REMINDER';
