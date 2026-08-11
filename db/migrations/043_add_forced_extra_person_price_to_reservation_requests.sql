-- Persists the "Forcer le prix des personne(s) supplémentaire(s)" override
-- (logged-in partner/admin only, see ReservationsController::
-- computeItemQuote()), mirroring migration 042's quote_room_base_before_
-- commission/quote_price_forced for the room-rate override:
-- quote_extra_person_price_forced flags that quote_extra_person_total was
-- manually overridden, and quote_extra_person_base_before_commission stores
-- the raw Lodgify extra-person fee (VAT included, commission excluded) used
-- as the floor for that override. Both are needed so confirmation/
-- cancellation emails (sendReservationStatusEmail(), which recomputes the
-- breakdown from the persisted quote_* columns) can still derive the
-- correct commission on the extra-person fee — (quote_extra_person_total -
-- quote_extra_person_base_before_commission) — instead of wrongly
-- re-deriving it from quote_partner_rate as if the price had not been
-- manually forced.
ALTER TABLE reservation_requests
  ADD COLUMN quote_extra_person_base_before_commission DECIMAL(10,2) DEFAULT NULL AFTER quote_price_forced,
  ADD COLUMN quote_extra_person_price_forced TINYINT(1) NOT NULL DEFAULT 0 AFTER quote_extra_person_base_before_commission;
