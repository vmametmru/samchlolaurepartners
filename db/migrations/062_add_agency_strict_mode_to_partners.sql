-- "Mode Agence Strict" toggle on /partner/settings: when enabled, nothing
-- changes for the logged-in partner/admin themselves, but their clients
-- (anonymous visitors browsing the shared partner link) no longer see the
-- "Tarifs & Disponibilités" tab on /properties/{id}, cannot open /calendrier,
-- and cannot submit reservation requests — they only browse the property
-- catalog. Defaults to 0 (disabled) so existing partners are unaffected.
ALTER TABLE partners
  ADD COLUMN agency_strict_mode TINYINT(1) NOT NULL DEFAULT 0;
