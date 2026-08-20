-- Observational log of each login session (see files/UserSessions.php and
-- Auth::establishSession()) powering the admin "qui est connecté" panel and
-- per-user connection history dialog. Auth itself stays fully stateless
-- (JWT-only) — this table never gates access, it only records it, keyed by
-- a random session id ("sid") embedded in the JWT payload at issuance.
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    ended_reason VARCHAR(20) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_id (session_id),
    INDEX idx_user_started (user_id, started_at),
    INDEX idx_last_seen (last_seen_at)
);
