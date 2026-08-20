-- Create IMAP user accounts table
CREATE TABLE IF NOT EXISTS user_imap_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    imap_server VARCHAR(255) NOT NULL,
    imap_port INT NOT NULL DEFAULT 993,
    imap_username VARCHAR(255) NOT NULL,
    imap_password_encrypted VARCHAR(1024) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- Create IMAP emails table
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

-- Create IMAP folders table (for tracking sync state)
CREATE TABLE IF NOT EXISTS imap_folders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_user_folder (user_id, folder_name)
);
