CREATE TABLE IF NOT EXISTS tourist_tax (
  id INT AUTO_INCREMENT PRIMARY KEY,
  per_person_per_night DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  applies_to_foreigners_only TINYINT(1) NOT NULL DEFAULT 1,
  applies_to_children TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default row
INSERT IGNORE INTO tourist_tax (id, per_person_per_night, applies_to_foreigners_only, applies_to_children)
VALUES (1, 0.00, 1, 0);
