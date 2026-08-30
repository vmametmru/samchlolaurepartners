-- Admin-controlled toggle: whether a partner can see the analytics page.
-- Defaults to 0 (hidden) so existing partners don't suddenly see it.
ALTER TABLE partners ADD COLUMN analytics_visible TINYINT(1) NOT NULL DEFAULT 0;
