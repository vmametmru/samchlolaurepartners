-- Splits the single "REMINDER" email (previously sent identically to both
-- the client and, as a copy, the partner — see Scheduler::runOnce()) into
-- two independently editable templates: REMINDER_CLIENT (sent to the
-- guest) and REMINDER_PARTNER (sent to the partner, may contain
-- partner-only variables such as commission_partenaire/paiement_a_
-- samchlolaure). Both are still sent at the same time by the same
-- email_schedules row (Scheduler::runOnce() now looks up both types
-- whenever it processes a 'REMINDER' schedule), so no schema change is
-- needed on email_schedules.template_type itself.
ALTER TABLE email_templates
  MODIFY COLUMN type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER'
  ) NOT NULL;

ALTER TABLE default_email_templates
  MODIFY COLUMN type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER'
  ) NOT NULL;

-- Existing REMINDER templates become the client-facing variant (same
-- content/behaviour as before this change).
UPDATE email_templates SET type = 'REMINDER_CLIENT' WHERE type = 'REMINDER';
UPDATE default_email_templates SET type = 'REMINDER_CLIENT' WHERE type = 'REMINDER';

-- Seeds a starter REMINDER_PARTNER template (a copy of the REMINDER_CLIENT
-- one it replaces) for every partner/language and for the site-wide
-- defaults that already had a REMINDER_CLIENT template, so nothing stops
-- sending after this migration — an admin/partner can then customize the
-- partner copy separately (e.g. add commission_partenaire).
INSERT INTO email_templates (partner_id, type, language, subject, body_html)
SELECT et.partner_id, 'REMINDER_PARTNER', et.language, et.subject, et.body_html
FROM email_templates et
WHERE et.type = 'REMINDER_CLIENT'
  AND NOT EXISTS (
    SELECT 1 FROM email_templates et2
    WHERE et2.partner_id = et.partner_id AND et2.type = 'REMINDER_PARTNER' AND et2.language = et.language
  );

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'REMINDER_PARTNER', det.language, det.subject, det.body_html
FROM default_email_templates det
WHERE det.type = 'REMINDER_CLIENT'
  AND NOT EXISTS (
    SELECT 1 FROM default_email_templates det2
    WHERE det2.type = 'REMINDER_PARTNER' AND det2.language = det.language
  );

-- email_schedules.partner_id becomes nullable: NULL now means "every
-- partner" ("Tous les partenaires"), so a single schedule row can trigger
-- the reminder for every partner's confirmed reservations instead of
-- requiring one row per partner.
ALTER TABLE email_schedules
  MODIFY COLUMN partner_id INT NULL;

-- Consolidates every existing per-partner REMINDER schedule into a single
-- global one (partner_id NULL), since the reminder no longer needs a
-- partner to be chosen.
INSERT INTO email_schedules (partner_id, days_before_arrival, template_type, active)
SELECT NULL, 5, 'REMINDER', 1
WHERE NOT EXISTS (
  SELECT 1 FROM email_schedules WHERE partner_id IS NULL AND template_type = 'REMINDER'
);

DELETE FROM email_schedules WHERE template_type = 'REMINDER' AND partner_id IS NOT NULL;
