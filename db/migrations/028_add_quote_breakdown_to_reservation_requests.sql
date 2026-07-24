-- Persists the price breakdown computed at submission time (previously only
-- ever passed transiently into the confirmation email variables and never
-- stored), so the partner-facing reservation detail page and future emails
-- can always show "Tarif Normal / Commissions Partenaire / Personnes
-- Additionnels / Nettoyage / Total Voyageur" for a request exactly as they
-- were when the guest booked, instead of needing a live (and possibly
-- since-changed) Lodgify rate re-fetch.
ALTER TABLE reservation_requests
  ADD COLUMN quote_currency VARCHAR(10) DEFAULT NULL AFTER message,
  ADD COLUMN quote_nights SMALLINT UNSIGNED DEFAULT NULL AFTER quote_currency,
  ADD COLUMN quote_room_total DECIMAL(10,2) DEFAULT NULL AFTER quote_nights,
  ADD COLUMN quote_partner_rate DECIMAL(5,2) DEFAULT NULL AFTER quote_room_total,
  ADD COLUMN quote_commission_total DECIMAL(10,2) DEFAULT NULL AFTER quote_partner_rate,
  ADD COLUMN quote_extra_person_total DECIMAL(10,2) DEFAULT NULL AFTER quote_commission_total,
  ADD COLUMN quote_cleaning_total DECIMAL(10,2) DEFAULT NULL AFTER quote_extra_person_total,
  ADD COLUMN quote_tourist_tax_total DECIMAL(10,2) DEFAULT NULL AFTER quote_cleaning_total,
  ADD COLUMN quote_total_traveler DECIMAL(10,2) DEFAULT NULL AFTER quote_tourist_tax_total;
