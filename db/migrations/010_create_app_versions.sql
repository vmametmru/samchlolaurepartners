CREATE TABLE IF NOT EXISTS app_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(50) NOT NULL,
  deployed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deployed_by VARCHAR(255) NOT NULL DEFAULT 'system',
  notes TEXT DEFAULT NULL,
  rolled_back_at DATETIME DEFAULT NULL,
  INDEX idx_deployed_at (deployed_at)
);
