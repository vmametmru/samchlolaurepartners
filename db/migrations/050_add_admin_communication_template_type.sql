-- Adds an admin-only email template type, ADMIN_COMMUNICATION, used by the
-- new /admin/communication page: the admin writes a message once and the
-- site sends it individually (one email per recipient, never a shared
-- To/CC list) to the email address registered for each selected partner,
-- through the default SMTP credentials of /admin/smtp-settings.
--
-- Unlike every other type, this one is never proposed on the per-partner
-- template pages (see PageController::adminTemplateCatalog()'s adminOnly
-- flag): only default_email_templates carries it. The email_templates enum
-- is widened all the same so both tables keep the exact same type list.
ALTER TABLE email_templates
  MODIFY COLUMN type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'RESERVATION_REOPENED',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER',
    'ADMIN_COMMUNICATION'
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
    'REMINDER_PARTNER',
    'ADMIN_COMMUNICATION'
  ) NOT NULL;

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'ADMIN_COMMUNICATION', 'fr',
  'Information importante',
  '<h2>Bonjour {{partenaire}},</h2><p>Nous souhaitions vous transmettre l''information suivante :</p><p>{{message}}</p><p>Pour toute question, n''hésitez pas à répondre à cet email.</p><p>Cordialement,<br><strong>{{expediteur}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'ADMIN_COMMUNICATION' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'ADMIN_COMMUNICATION', 'en',
  'Important information',
  '<h2>Hello {{partenaire}},</h2><p>We wanted to share the following information with you:</p><p>{{message}}</p><p>If you have any question, simply reply to this email.</p><p>Best regards,<br><strong>{{expediteur}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'ADMIN_COMMUNICATION' AND language = 'en'
);
