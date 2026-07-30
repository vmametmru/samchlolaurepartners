-- The two check-in-info URLs used to build the {{useful_info}} email button
-- ("Renseignements utiles à l'enregistrement" / "Useful check-in
-- informations") are per-property information (e.g. a specific villa's
-- access code/key box instructions), not per-partner. Moved from the
-- "partners" table (added by migration 035) to
-- "lodgify_property_manual_columns", set manually per property in the
-- admin "Biens Lodgify" table, same as extra_person_fee/vat_rate.
ALTER TABLE lodgify_property_manual_columns
  ADD COLUMN checkin_info_url_fr VARCHAR(500) DEFAULT NULL AFTER vat_rate,
  ADD COLUMN checkin_info_url_en VARCHAR(500) DEFAULT NULL AFTER checkin_info_url_fr;

ALTER TABLE partners
  DROP COLUMN checkin_info_url_fr,
  DROP COLUMN checkin_info_url_en;
