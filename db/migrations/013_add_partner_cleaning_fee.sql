ALTER TABLE partners
  ADD COLUMN cleaning_fee_per_person_per_night DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER markup_percent;
