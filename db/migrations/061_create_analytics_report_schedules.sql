-- Per-partner configuration for automatic weekly analytics PDF reports.
-- When enabled=1 the scheduler sends a PDF to the partner's email at
-- the configured day_of_week (0=Sunday..6=Saturday) and time_of_day (HH:MM).
CREATE TABLE IF NOT EXISTS analytics_report_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    day_of_week TINYINT NOT NULL DEFAULT 1,
    time_of_day VARCHAR(5) NOT NULL DEFAULT '08:00',
    last_sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_partner (partner_id),
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
);
