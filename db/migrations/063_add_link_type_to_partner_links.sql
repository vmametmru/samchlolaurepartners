-- Adds support for a second kind of partner link (see files/PartnerLinks.php).
--
-- "Directe" (link_type = 'direct') is the original behaviour: a symmetric
-- link, either partner can switch into the other's account.
--
-- "Hiérarchique" (link_type = 'hierarchical') is directional: only
-- principal_partner_id (one of partner_id_a/partner_id_b) can switch into
-- the other ("child") account; the child gets no switch button at all and
-- cannot switch into the principal nor into any of its siblings.
ALTER TABLE partner_links
  ADD COLUMN link_type ENUM('direct', 'hierarchical') NOT NULL DEFAULT 'direct',
  ADD COLUMN principal_partner_id INT NULL,
  ADD FOREIGN KEY (principal_partner_id) REFERENCES partners(id) ON DELETE CASCADE;
