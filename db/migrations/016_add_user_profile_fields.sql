ALTER TABLE users
  ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER email,
  ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name,
  ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER last_name,
  ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL AFTER phone,
  ADD COLUMN reset_token VARCHAR(100) DEFAULT NULL AFTER photo_url,
  ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token,
  ADD INDEX idx_reset_token (reset_token);
