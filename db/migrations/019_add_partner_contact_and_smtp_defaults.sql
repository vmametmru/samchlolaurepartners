ALTER TABLE partners
  ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER email,
  ADD COLUMN facebook_url VARCHAR(500) DEFAULT NULL AFTER phone,
  ADD COLUMN tiktok_url VARCHAR(500) DEFAULT NULL AFTER facebook_url,
  ADD COLUMN instagram_url VARCHAR(500) DEFAULT NULL AFTER tiktok_url;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
  ('SMTP_SECURITY', 'ssl');

UPDATE settings SET `value` = 'mail.grand-baie-maurice.com' WHERE `key` = 'SMTP_HOST' AND (`value` IS NULL OR `value` = '');
UPDATE settings SET `value` = '465' WHERE `key` = 'SMTP_PORT' AND (`value` IS NULL OR `value` = '');
UPDATE settings SET `value` = 'infos@grand-baie-maurice.com' WHERE `key` = 'SMTP_USER' AND (`value` IS NULL OR `value` = '');
UPDATE settings SET `value` = 'infos@grand-baie-maurice.com' WHERE `key` = 'SMTP_FROM_EMAIL' AND (`value` IS NULL OR `value` = '');
