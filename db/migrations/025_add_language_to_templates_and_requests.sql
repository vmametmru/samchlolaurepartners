-- Allows email_templates to have one variant per language (FR/EN), so
-- client-facing emails (accusé réception, confirmation, annulation, rappel)
-- can be sent in the same language the guest used the site in, instead of
-- always sending the partner's single French template regardless of the
-- language the visitor actually browsed the site in.
ALTER TABLE email_templates
  ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'fr' AFTER type;

ALTER TABLE email_templates
  DROP INDEX unique_partner_type;

ALTER TABLE email_templates
  ADD UNIQUE KEY unique_partner_type_lang (partner_id, type, language);

-- Records which site language a visitor used when submitting a reservation
-- request, so RESERVATION_CONFIRMED/RESERVATION_CANCELLED/REMINDER emails
-- sent later (after the request row already exists) can still be sent in
-- that same language.
ALTER TABLE reservation_requests
  ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'fr' AFTER client_phone;
