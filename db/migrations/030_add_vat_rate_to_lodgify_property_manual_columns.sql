-- Some Lodgify properties are VAT-registered but Lodgify's rate does not
-- include VAT (it must be added on top of both the base nightly rate and
-- the extra-person fee); properties that are not VAT-registered simply
-- have a 0 rate here, leaving their normal price unchanged. Set manually
-- per property in the admin "Biens Lodgify" table, same as
-- extra_person_fee/min_people.
ALTER TABLE lodgify_property_manual_columns
  ADD COLUMN vat_rate DECIMAL(5,2) DEFAULT NULL AFTER extra_person_fee;
