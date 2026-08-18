-- Lets each partner override the global "Politique de réservation" text
-- (previously only configurable admin-wide at /admin/politique-reservation)
-- with their own FR/EN conditions, editable from /partner/settings. NULL
-- means "no override yet": PageController::bookingPolicyText() then falls
-- back to the existing global default, so every partner keeps showing the
-- current text until they explicitly set their own.
ALTER TABLE partners
  ADD COLUMN booking_policy_text TEXT DEFAULT NULL AFTER catalog_pdf_url,
  ADD COLUMN booking_policy_text_en TEXT DEFAULT NULL AFTER booking_policy_text;
