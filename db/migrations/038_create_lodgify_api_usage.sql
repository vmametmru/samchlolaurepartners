-- Rolling per-minute counter of outgoing Lodgify API calls, used by
-- LodgifyClient to stay below Lodgify's request threshold: every HTTP call
-- atomically increments the counter of the current minute, and calls beyond
-- LODGIFY_MAX_CALLS_PER_MINUTE are refused locally (cached/stale data is
-- served instead) rather than being sent and answered with HTTP 429.
CREATE TABLE IF NOT EXISTS lodgify_api_usage (
  minute_slot VARCHAR(12) NOT NULL PRIMARY KEY,
  calls INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
