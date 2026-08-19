-- Adds two new customizable email template types for when an already
-- submitted reservation request is edited and resent, whether the change
-- was made by the client themselves (via their public "Partager le lien"
-- page, see ReservationsController::updatePublicRequest()) or by the
-- partner (via /partner/reservations/{id}, see
-- ReservationsController::updateForPartner()):
--   - RESERVATION_MODIFIED_PARTNER: notifies the partner that the client
--     has just edited and resent their own request (previously a plain,
--     non-customizable notification, see sendClientEditNotificationEmail()).
--   - RESERVATION_MODIFIED_CLIENT: notifies the client that their request
--     was modified, whether by themselves (self-edit confirmation, see
--     sendClientSelfEditConfirmationEmail()) or by the partner (see
--     sendPartnerEditNotificationEmail()) — both share the same
--     client-facing template type, since the {{detail_modification}}
--     variable already summarizes exactly what changed.
ALTER TABLE email_templates
  MODIFY COLUMN type ENUM(
    'REQUEST_RECEIVED_PARTNER',
    'REQUEST_RECEIVED_CLIENT',
    'RESERVATION_CONFIRMED',
    'RESERVATION_CANCELLED',
    'RESERVATION_REOPENED',
    'RESERVATION_MODIFIED_PARTNER',
    'RESERVATION_MODIFIED_CLIENT',
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
    'RESERVATION_MODIFIED_PARTNER',
    'RESERVATION_MODIFIED_CLIENT',
    'REMINDER',
    'REMINDER_CLIENT',
    'REMINDER_PARTNER',
    'ADMIN_COMMUNICATION'
  ) NOT NULL;

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_MODIFIED_PARTNER', 'fr',
  'Demande de réservation modifiée par le client - {{nom_client}}',
  '<h2>Demande de réservation modifiée</h2><p>{{nom_client}} a modifié et renvoyé sa demande de réservation pour <strong>{{hebergement}}</strong> ({{dates}}).</p><p><strong>Détail du changement :</strong></p>{{detail_modification}}<p><a href="{{lien_demande_partenaire}}">Voir la demande</a></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_MODIFIED_PARTNER' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_MODIFIED_PARTNER', 'en',
  'Booking request modified by the client - {{nom_client}}',
  '<h2>Booking request modified</h2><p>{{nom_client}} has modified and resent their booking request for <strong>{{hebergement}}</strong> ({{dates}}).</p><p><strong>Change details:</strong></p>{{detail_modification}}<p><a href="{{lien_demande_partenaire}}">View the request</a></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_MODIFIED_PARTNER' AND language = 'en'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_MODIFIED_CLIENT', 'fr',
  'Votre demande de réservation a été modifiée',
  '<h2>Votre demande de réservation a été modifiée</h2><p>Bonjour {{nom_client}},</p><p>Votre demande de réservation pour <strong>{{hebergement}}</strong> a bien été mise à jour avec les dates du {{date_arrivee}} au {{date_depart}}.</p><p><strong>Détail du changement :</strong></p>{{detail_modification}}<p>Cordialement,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_MODIFIED_CLIENT' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'RESERVATION_MODIFIED_CLIENT', 'en',
  'Your booking request has been modified',
  '<h2>Your booking request has been modified</h2><p>Hello {{nom_client}},</p><p>Your booking request for <strong>{{hebergement}}</strong> has been updated with the dates from {{date_arrivee}} to {{date_depart}}.</p><p><strong>Change details:</strong></p>{{detail_modification}}<p>Best regards,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'RESERVATION_MODIFIED_CLIENT' AND language = 'en'
);
