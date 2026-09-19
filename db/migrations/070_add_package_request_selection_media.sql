-- Follow-up to db/migrations/068_create_package_requests.sql and
-- db/migrations/069_add_package_offer_commission_and_templates.sql: snapshots
-- the *title and photo* of what the client actually picked in an "Offre
-- Complète" (App\Packages), so email templates can reference
-- {{offre_image}}/{{offre_vol_titre}}/{{offre_vol_image}}/
-- {{offre_transport_titres}}/{{offre_transport_images}}/
-- {{offre_activites_titres}}/{{offre_activites_images}}/
-- {{offre_restauration_titres}}/{{offre_restauration_images}} (see
-- PackageRequests::log()/selectionVariables(),
-- ReservationsController::packageOfferVariables(),
-- View::emailTemplateVariableCatalog()).
--
-- Exactly one flight is ever chosen (flight_title/flight_photo_url), while
-- transports/activities/meals can each have several selected/mandatory
-- entries, so those three are stored as small JSON arrays of
-- {"title": ..., "photo_url": ...} objects instead of extra columns.
--
-- Snapshotted at submission time (never re-read from package_flights/
-- package_transports/package_activities/package_meals afterwards), like the
-- step-total columns added in migration 069: an option renamed, re-photographed
-- or deleted after the request was submitted must never change what a
-- later confirmation/cancellation email shows.
ALTER TABLE package_requests ADD COLUMN flight_title VARCHAR(190) DEFAULT NULL;
ALTER TABLE package_requests ADD COLUMN flight_photo_url VARCHAR(500) DEFAULT NULL;
ALTER TABLE package_requests ADD COLUMN transports_json MEDIUMTEXT DEFAULT NULL;
ALTER TABLE package_requests ADD COLUMN activities_json MEDIUMTEXT DEFAULT NULL;
ALTER TABLE package_requests ADD COLUMN meals_json MEDIUMTEXT DEFAULT NULL;
