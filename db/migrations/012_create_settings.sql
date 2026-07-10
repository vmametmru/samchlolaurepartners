CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(190) NOT NULL PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (`key`, `value`) VALUES
  ('APP_ENV', 'production'),
  ('APP_URL', 'http://localhost:8080'),
  ('PORT', '8080'),
  ('JWT_SECRET', ''),
  ('APP_COOKIE_DOMAIN', ''),
  ('LODGIFY_API_KEY', ''),
  ('LODGIFY_BASE_URL', 'https://api.lodgify.com/v2'),
  ('SMTP_HOST', 'localhost'),
  ('SMTP_PORT', '1025'),
  ('SMTP_USER', ''),
  ('SMTP_PASS', ''),
  ('SMTP_FROM_EMAIL', 'no-reply@example.com'),
  ('SMTP_FROM_NAME', 'samchlolaurepartners'),
  ('CORS_ORIGIN', 'http://localhost:8080'),
  ('TRUSTED_PROXIES', '');
