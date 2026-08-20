-- Ensures the imap_emails table exists (webmail feature). This table was
-- previously created by 054_create_imap_tables.sql, which also created
-- user_imap_accounts and imap_folders for a per-user IMAP account model.
-- The feature was later simplified to a single centralized IMAP server
-- (see 055_add_email_password_to_users.sql), making those two tables
-- obsolete, so 054 was removed — but imap_emails is still required by
-- ImapManager/EmailController, hence recreating it here (idempotent via
-- IF NOT EXISTS, so this is a no-op on any environment where 054 already
-- ran and the table exists).
CREATE TABLE IF NOT EXISTS imap_emails (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    subject VARCHAR(500) NOT NULL,
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    to_emails TEXT,
    cc_emails TEXT,
    body_html LONGTEXT,
    body_text LONGTEXT,
    received_at TIMESTAMP,
    is_read TINYINT(1) DEFAULT 0,
    folder VARCHAR(255) DEFAULT 'INBOX',
    uid INT,
    imap_message_id VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_folder (folder),
    INDEX idx_is_read (is_read),
    INDEX idx_received_at (received_at),
    UNIQUE KEY unique_message_id (user_id, imap_message_id)
);
