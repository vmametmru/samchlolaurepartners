-- "Mode Agence Strict" toggle on /partner/settings: when enabled, nothing
-- changes for the logged-in partner/admin themselves, but their clients
-- (anonymous visitors browsing the shared partner link) no longer see the
-- the "Tarifs & Disponibilités" tab on /properties/{id} is relabeled
-- "Disponibilités"; /calendrier remains available, but prices, the cart,
-- and reservation requests are hidden/blocked — they only browse the property
-- catalog.
ALTER TABLE partners
  ADD COLUMN agency_strict_mode TINYINT(1) NOT NULL DEFAULT 0;
