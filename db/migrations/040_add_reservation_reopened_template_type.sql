-- Adds a new client-facing email template type, RESERVATION_REOPENED, sent
-- when a partner reopens a confirmed reservation back to "Ouverte" (pending)
-- status from the agency dashboard (/partner/reservations) — see
-- ReservationsController::reopenForPartner(). Cancellation already has its
-- own RESERVATION_CANCELLED template regardless of the previous status, so
-- only the "back to pending" transition needed a brand new template.
ALTER TABLE email_templates
  MODIFY COLUMN type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'RESERVATION_REOPENED',
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
    'RESERVATION_REOPENED',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER'
  ) NOT NULL;

-- Seeds a starter RESERVATION_REOPENED default (fr/en), so the new email
-- has content to send right away for every partner without a customized
-- template, the same way migration 033 seeded REMINDER_PARTNER.
INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_REOPENED', 'fr',
  'Votre réservation repasse en attente',
  '<h2>Votre réservation est repassée en attente</h2><p>Bonjour {{nom_client}},</p><p>Nous vous informons que votre réservation pour <strong>{{hebergement}}</strong> ({{dates}}) est provisoirement repassée en attente de confirmation.</p><p>Nous revenons vers vous très prochainement.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_REOPENED' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_REOPENED', 'en',
  'Your reservation is back to pending',
  '<h2>Your reservation is back to pending</h2><p>Hello {{nom_client}},</p><p>We are letting you know that your reservation for <strong>{{hebergement}}</strong> ({{dates}}) has been temporarily put back to pending confirmation.</p><p>We will get back to you shortly.</p><p>Best regards,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_REOPENED' AND language = 'en'
);
