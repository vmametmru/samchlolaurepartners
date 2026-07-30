-- A partner-uploaded PDF catalogue (their full property listing, meant to be
-- forwarded to their own clients), configurable from both /admin/partners
-- (admin form) and /partner/settings (partner self-service), same upload
-- pattern as logo_url. Optional: the "Télécharger le catalogue" button on
-- the partner dashboard is only shown when this is set.
ALTER TABLE partners
  ADD COLUMN catalog_pdf_url VARCHAR(500) DEFAULT NULL AFTER logo_url;
