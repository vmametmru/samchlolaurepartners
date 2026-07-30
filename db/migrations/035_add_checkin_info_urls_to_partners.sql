-- Two partner-configurable URLs (one per language) pointing to check-in
-- instructions/useful info hosted elsewhere (e.g. a PDF or a page), used to
-- build the {{useful_info}} email button ("Renseignements utiles à
-- l'enregistrement" / "Useful check-in informations"), which links to
-- checkin_info_url_en when the recipient's email language is English, or
-- checkin_info_url_fr otherwise. Both are optional: the button is simply
-- omitted from emails when the relevant URL is empty.
ALTER TABLE partners
  ADD COLUMN checkin_info_url_fr VARCHAR(500) DEFAULT NULL AFTER instagram_url,
  ADD COLUMN checkin_info_url_en VARCHAR(500) DEFAULT NULL AFTER checkin_info_url_fr;
