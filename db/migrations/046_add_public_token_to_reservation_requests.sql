-- Adds a unique, unguessable public_token to reservation_requests so a
-- partner can share a link (e.g. via WhatsApp) to an online, editable copy
-- of a client's reservation request (see
-- ReservationsController::ensurePublicToken()/findByToken()). Only ever
-- generated server-side (bin2hex(random_bytes(16))), never derived from the
-- row id, so it cannot be guessed/enumerated. last_client_update_at tracks
-- the last time the client themselves (not the partner) edited the request
-- via that public link, so the partner UI can show "Dernière modification
-- par le client le ...".
ALTER TABLE reservation_requests
  ADD COLUMN public_token VARCHAR(64) DEFAULT NULL,
  ADD COLUMN last_client_update_at DATETIME DEFAULT NULL,
  ADD UNIQUE KEY uniq_public_token (public_token);
