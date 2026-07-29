-- Seeds a default REMINDER schedule (5 days before arrival, active) for
-- every existing partner that doesn't already have one, so the "reminder
-- email sent to confirmed-reservation clients 5 days before arrival"
-- behaviour works out of the box without requiring a partner to manually
-- create it via the /api/email-schedules API (there is no admin UI for it).
INSERT INTO email_schedules (partner_id, days_before_arrival, template_type, active)
SELECT p.id, 5, 'REMINDER', 1
FROM partners p
WHERE NOT EXISTS (
  SELECT 1 FROM email_schedules es WHERE es.partner_id = p.id AND es.template_type = 'REMINDER'
);
