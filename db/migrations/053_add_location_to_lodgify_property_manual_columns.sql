-- "Emplacement" (location) is a free-text label set manually per property
-- in the admin "Biens Lodgify" table, same as sofa_bed_count/vat_rate. It
-- lets several properties sharing the same physical location (e.g. same
-- residence/villa complex) be grouped and filtered from the "Calendrier"
-- availability board.
ALTER TABLE lodgify_property_manual_columns
  ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER checkin_info_url_en;
