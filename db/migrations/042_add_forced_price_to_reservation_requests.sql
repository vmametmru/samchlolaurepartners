-- Persists the "Forcer le prix de la nuit" override (logged-in partner/admin
-- only, see ReservationsController::computeItemQuote()): quote_price_forced
-- flags that quote_room_total was manually overridden rather than computed
-- from the partner's markup_percent, and quote_room_base_before_commission
-- stores the raw Lodgify rate (VAT included, commission excluded) used as
-- the floor for that override. Both are needed so confirmation/cancellation
-- emails (sendReservationStatusEmail(), which recomputes the breakdown from
-- the persisted quote_* columns) can still derive the correct commission —
-- (quote_room_total - quote_room_base_before_commission) — instead of
-- wrongly re-deriving it from quote_partner_rate as if the price had not
-- been manually forced.
ALTER TABLE reservation_requests
  ADD COLUMN quote_room_base_before_commission DECIMAL(10,2) DEFAULT NULL AFTER quote_vat_rate,
  ADD COLUMN quote_price_forced TINYINT(1) NOT NULL DEFAULT 0 AFTER quote_room_base_before_commission;
