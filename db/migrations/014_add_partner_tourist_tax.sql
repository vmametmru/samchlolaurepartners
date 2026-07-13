ALTER TABLE partners
  ADD COLUMN tourist_tax_per_person_per_night DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER cleaning_fee_per_person_per_night;
