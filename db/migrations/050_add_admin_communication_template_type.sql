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
  '{{sujet}}',
  '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;"><p style="margin:0 0 12px;font-size:15px;color:#111827;">Bonjour <strong>{{partenaire}}</strong>,</p><div style="font-size:15px;color:#374151;">{{message}}</div>{{piece_jointe}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;"><p style="margin:0;font-size:13px;color:#6b7280;">Cordialement,<br><strong>{{expediteur}}</strong></p></div>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'ADMIN_COMMUNICATION' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'ADMIN_COMMUNICATION', 'en',
  '{{sujet}}',
  '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;"><p style="margin:0 0 12px;font-size:15px;color:#111827;">Hello <strong>{{partenaire}}</strong>,</p><div style="font-size:15px;color:#374151;">{{message}}</div>{{piece_jointe}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;"><p style="margin:0;font-size:13px;color:#6b7280;">Best regards,<br><strong>{{expediteur}}</strong></p></div>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'ADMIN_COMMUNICATION' AND language = 'en'
);
