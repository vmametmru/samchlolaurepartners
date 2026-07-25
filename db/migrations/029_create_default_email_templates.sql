-- Adds a "default" email template per type/language, managed by the admin
-- outside of any single partner, used as the fallback whenever a partner
-- has not created their own template for that type/language yet (see
-- ReservationsController::findEmailTemplate()). Kept as a separate table
-- (rather than allowing a NULL/sentinel partner_id on email_templates)
-- since email_templates.partner_id is NOT NULL with a FOREIGN KEY to
-- partners(id), and a default template must exist independently of any
-- partner.
CREATE TABLE IF NOT EXISTS default_email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'REMINDER'
  ) NOT NULL,
  language VARCHAR(5) NOT NULL DEFAULT 'fr',
  subject VARCHAR(500) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_type_lang (type, language)
);
