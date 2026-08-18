-- History of every email sent from the admin "Communication" page
-- (/admin/communication): one row per recipient (each selected partner
-- gets its own individual email, never a shared To/CC list), so the admin
-- can see from the page itself what was sent, to whom, and whether the
-- SMTP send actually succeeded.
CREATE TABLE IF NOT EXISTS admin_communication_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  partner_id INT DEFAULT NULL,
  partner_name VARCHAR(255) NOT NULL DEFAULT '',
  recipient_email VARCHAR(255) NOT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  attachment_name VARCHAR(255) DEFAULT NULL,
  status ENUM('SENT', 'FAILED') NOT NULL DEFAULT 'SENT',
  error_message VARCHAR(500) DEFAULT NULL,
  sent_by VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created_at (created_at),
  INDEX idx_partner (partner_id)
);
