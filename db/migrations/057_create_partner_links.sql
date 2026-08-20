-- Links two (or more, via several pairs) partner accounts together so a
-- partner user can instantly switch into a linked partner's own account
-- (see files/PartnerLinks.php and PageController::partnerSwitchAccount())
-- without re-entering credentials — a kind of automatic logout/login into
-- the linked account. Each pair is stored once, canonically ordered
-- (partner_id_a < partner_id_b): the relation is symmetric, so linking A to
-- B from A's admin dialog is the exact same row as linking B to A from B's.
CREATE TABLE IF NOT EXISTS partner_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    partner_id_a INT NOT NULL,
    partner_id_b INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id_a) REFERENCES partners(id) ON DELETE CASCADE,
    FOREIGN KEY (partner_id_b) REFERENCES partners(id) ON DELETE CASCADE,
    UNIQUE KEY unique_pair (partner_id_a, partner_id_b)
);
