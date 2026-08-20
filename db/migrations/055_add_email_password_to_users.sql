-- Add encrypted email password field for IMAP webmail access
ALTER TABLE users ADD COLUMN email_password VARCHAR(500) DEFAULT NULL AFTER password_hash;
