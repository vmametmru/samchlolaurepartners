-- Once a devis (quote) has been generated for a reservation request, the
-- "Changer d'hébergement" button on the client's own public link (files/
-- views/pages/reservation-public.php) is hidden/disabled by default: the
-- client should no longer be able to swap properties on a quoted request
-- without the partner's knowledge. client_can_change_property lets the
-- partner re-enable it per-request via a checkbox next to "Changer
-- d'hébergement" on /partner/reservations/{id} (see
-- PageController::partnerToggleClientPropertyChange()) whenever they're
-- happy for the client to pick a different property themselves. Defaults
-- to 0 (hidden) — before any quote exists the button is always shown
-- regardless of this flag, so existing pending requests are unaffected.
ALTER TABLE reservation_requests
  ADD COLUMN client_can_change_property TINYINT(1) NOT NULL DEFAULT 0;
