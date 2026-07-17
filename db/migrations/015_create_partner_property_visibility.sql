CREATE TABLE IF NOT EXISTS partner_property_visibility (
  id INT AUTO_INCREMENT PRIMARY KEY,
  partner_id INT NOT NULL,
  property_id VARCHAR(100) NOT NULL,
  visibility ENUM('full','partial','none') NOT NULL DEFAULT 'full',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_partner_property (partner_id, property_id),
  INDEX idx_partner (partner_id)
);
