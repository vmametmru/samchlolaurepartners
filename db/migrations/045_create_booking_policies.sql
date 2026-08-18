-- Lets each partner create *several* named "Politique de réservation"
-- policies instead of a single FR/EN pair (partners.booking_policy_text(_en),
-- kept below as the legacy/plain-text fallback), and choose which one
-- applies on a given quote-request form (property "Tarifs & Disponibilités"
-- tab and /calendrier), see PageController::bookingPolicyText()/
-- partnerBookingPolicies() and ReservationsController::bookingPolicyIdFromInput().
-- text_fr/text_en store rich HTML (bold/underline/font-size) produced by the
-- WYSIWYG editor on /partner/settings, sanitized server-side before saving.
CREATE TABLE IF NOT EXISTS booking_policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  partner_id INT NOT NULL,
  label VARCHAR(190) NOT NULL,
  text_fr MEDIUMTEXT NOT NULL,
  text_en MEDIUMTEXT DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_booking_policies_partner (partner_id),
  CONSTRAINT fk_booking_policies_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time migration of each partner's existing single booking_policy_text
-- (if any) into a first, default policy row, so nobody loses their current
-- conditions when this feature ships.
INSERT INTO booking_policies (partner_id, label, text_fr, text_en, is_default)
SELECT id, 'Politique par défaut', booking_policy_text, booking_policy_text_en, 1
FROM partners
WHERE booking_policy_text IS NOT NULL AND TRIM(booking_policy_text) <> '';
