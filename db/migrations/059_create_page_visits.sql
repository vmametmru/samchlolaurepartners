-- Tracks every page visit for the analytics dashboard. Each row records
-- a single page view: who visited (visitor_type: client/partner/admin),
-- which partner's context they were on (partner_id), which page, how long
-- they stayed, and geographic/device metadata.
CREATE TABLE IF NOT EXISTS page_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT DEFAULT NULL,
    visitor_type ENUM('client','partner','admin') NOT NULL DEFAULT 'client',
    user_id INT DEFAULT NULL,
    page_url VARCHAR(500) NOT NULL,
    page_title VARCHAR(255) DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    country_code VARCHAR(5) DEFAULT NULL,
    country_name VARCHAR(100) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    referrer VARCHAR(500) DEFAULT NULL,
    session_id VARCHAR(64) DEFAULT NULL,
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_visited (partner_id, visited_at),
    INDEX idx_visitor_type (visitor_type),
    INDEX idx_page_url (page_url(191)),
    INDEX idx_country (country_code),
    INDEX idx_session (session_id),
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL
);
