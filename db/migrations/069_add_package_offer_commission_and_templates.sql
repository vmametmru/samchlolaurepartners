-- Follow-up to db/migrations/068_create_package_requests.sql, covering the
-- 3-part "Offres Complètes" request (see PackageRequests.php,
-- ReservationsController.php, PageController.php):
--
-- 1) Snapshots of each offer step's total (Vol/Transport/Activités/
--    Restauration), captured at submission time so the commission owed on
--    an offer never drifts if the offer's own prices are edited afterwards.
ALTER TABLE package_requests ADD COLUMN flight_total DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE package_requests ADD COLUMN transport_total DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE package_requests ADD COLUMN activity_total DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE package_requests ADD COLUMN meal_total DECIMAL(10,2) NOT NULL DEFAULT 0;

-- 2) Per-partner, per-step commission percentage owed to SamChloLaure on
--    "Offres Complètes" (only meaningful when partners.packages_visible is
--    enabled for that partner): {{commission_offres_completes}} =
--    flight_total * packages_commission_flight_percent / 100
--    + transport_total * packages_commission_transport_percent / 100
--    + activity_total * packages_commission_activity_percent / 100
--    + meal_total * packages_commission_meal_percent / 100
-- (see View::emailTemplateVariableCatalog(), PackageRequests::commissionVariables()).
ALTER TABLE partners ADD COLUMN packages_commission_flight_percent DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE partners ADD COLUMN packages_commission_transport_percent DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE partners ADD COLUMN packages_commission_activity_percent DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE partners ADD COLUMN packages_commission_meal_percent DECIMAL(5,2) NOT NULL DEFAULT 0;

-- 3) Four dedicated, customizable email template types for requests
--    originating from an "Offre Complète" (App\Packages) instead of an
--    ordinary property reservation request, so partners can style/word them
--    differently from REQUEST_RECEIVED_*/RESERVATION_* (e.g. via a
--    Canva-designed template uploaded on /admin/templates/default):
--      - PACKAGE_REQUEST_RECEIVED_PARTNER: partner notification, mirrors
--        REQUEST_RECEIVED_PARTNER.
--      - PACKAGE_REQUEST_RECEIVED_CLIENT: client acknowledgement, mirrors
--        REQUEST_RECEIVED_CLIENT.
--      - PACKAGE_REQUEST_CONFIRMED / PACKAGE_REQUEST_CANCELLED: client
--        status notifications, mirror RESERVATION_CONFIRMED/CANCELLED.
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
    'ADMIN_COMMUNICATION',
    'PACKAGE_REQUEST_RECEIVED_PARTNER',
    'PACKAGE_REQUEST_RECEIVED_CLIENT',
    'PACKAGE_REQUEST_CONFIRMED',
    'PACKAGE_REQUEST_CANCELLED'
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
    'ADMIN_COMMUNICATION',
    'PACKAGE_REQUEST_RECEIVED_PARTNER',
    'PACKAGE_REQUEST_RECEIVED_CLIENT',
    'PACKAGE_REQUEST_CONFIRMED',
    'PACKAGE_REQUEST_CANCELLED'
  ) NOT NULL;

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_RECEIVED_PARTNER', 'fr',
  'Nouvelle demande d''offre complète - {{nom_client}}',
  '<h2>Nouvelle demande d''offre complète</h2><p><strong>Offre :</strong> {{offre_titre}}</p><p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p><p><strong>Hébergement :</strong> {{hebergement}}</p><p><strong>Dates :</strong> {{dates}}</p><p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p>{{offre_recap_bloc}}<p><strong>Message :</strong><br>{{message}}</p><hr><p>Veuillez traiter cette demande depuis votre espace partenaire (Offres Complètes &gt; Demandes).</p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_RECEIVED_PARTNER' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_RECEIVED_PARTNER', 'en',
  'New complete offer request - {{nom_client}}',
  '<h2>New complete offer request</h2><p><strong>Offer:</strong> {{offre_titre}}</p><p><strong>Client:</strong> {{nom_client}} ({{email_client}})</p><p><strong>Property:</strong> {{hebergement}}</p><p><strong>Dates:</strong> {{dates}}</p><p><strong>Guests:</strong> {{adultes}} adult(s), {{enfants}} child(ren)</p>{{offre_recap_bloc}}<p><strong>Message:</strong><br>{{message}}</p><hr><p>Please handle this request from your partner dashboard (Offres Complètes &gt; Demandes).</p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_RECEIVED_PARTNER' AND language = 'en'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_RECEIVED_CLIENT', 'fr',
  'Votre demande d''offre complète est bien reçue - {{offre_titre}}',
  '<h2>Votre demande d''offre complète est bien reçue !</h2><p>Bonjour {{nom_client}},</p><p>Nous avons bien reçu votre demande pour l''offre <strong>{{offre_titre}}</strong>.</p><p><strong>Hébergement :</strong> {{hebergement}}</p><p><strong>Dates :</strong> {{dates}} ({{nuits}} nuit(s))</p>{{offre_recap_bloc}}<p>Cordialement,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_RECEIVED_CLIENT' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_RECEIVED_CLIENT', 'en',
  'Your complete offer request has been received - {{offre_titre}}',
  '<h2>Your complete offer request has been received!</h2><p>Hello {{nom_client}},</p><p>We have received your request for the offer <strong>{{offre_titre}}</strong>.</p><p><strong>Property:</strong> {{hebergement}}</p><p><strong>Dates:</strong> {{dates}} ({{nuits}} night(s))</p>{{offre_recap_bloc}}<p>Best regards,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_RECEIVED_CLIENT' AND language = 'en'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_CONFIRMED', 'fr',
  'Votre offre complète est confirmée ! 🎉',
  '<h2>Offre complète confirmée</h2><p>Bonjour {{nom_client}},</p><p>Nous avons le plaisir de vous confirmer votre offre <strong>{{offre_titre}}</strong> :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Arrivée :</strong> {{date_arrivee}}</li><li><strong>Départ :</strong> {{date_depart}}</li><li><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</li></ul>{{offre_recap_bloc}}{{notes}}<p>À très bientôt à l''île Maurice !</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_CONFIRMED' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_CONFIRMED', 'en',
  'Your complete offer is confirmed! 🎉',
  '<h2>Complete offer confirmed</h2><p>Hello {{nom_client}},</p><p>We are pleased to confirm your offer <strong>{{offre_titre}}</strong>:</p><ul><li><strong>Property:</strong> {{hebergement}}</li><li><strong>Arrival:</strong> {{date_arrivee}}</li><li><strong>Departure:</strong> {{date_depart}}</li><li><strong>Guests:</strong> {{adultes}} adult(s), {{enfants}} child(ren)</li></ul>{{offre_recap_bloc}}{{notes}}<p>See you soon in Mauritius!</p><p>Best regards,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_CONFIRMED' AND language = 'en'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_CANCELLED', 'fr',
  'Annulation de votre offre complète',
  '<h2>Votre offre complète a été annulée</h2><p>Bonjour {{nom_client}},</p><p>Nous vous informons que votre offre <strong>{{offre_titre}}</strong> ({{dates}}) a malheureusement dû être annulée.</p><p>N''hésitez pas à nous contacter pour explorer d''autres options.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_CANCELLED' AND language = 'fr'
);

INSERT INTO default_email_templates (type, language, subject, body_html)
SELECT 'PACKAGE_REQUEST_CANCELLED', 'en',
  'Your complete offer has been cancelled',
  '<h2>Your complete offer has been cancelled</h2><p>Hello {{nom_client}},</p><p>We regret to inform you that your offer <strong>{{offre_titre}}</strong> ({{dates}}) had to be cancelled.</p><p>Please feel free to contact us to explore other options.</p><p>Best regards,<br><strong>{{partenaire}}</strong></p>'
WHERE NOT EXISTS (
  SELECT 1 FROM default_email_templates WHERE type = 'PACKAGE_REQUEST_CANCELLED' AND language = 'en'
);
