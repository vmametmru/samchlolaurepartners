-- LodgifyClient::getProperty()/getPropertyRoomsDetails() used to cache their
-- result for a flat 24h TTL even when a transient Lodgify hiccup (timeout,
-- rate limit, ...) left "images"/rooms empty — silently hiding the property
-- photo (e.g. the booking-request emails' {{photo_bien}} tag) for a full day
-- on every retry (see LodgifyClient.php getProperty()/getPropertyRoomsDetails()
-- for the fix: an empty result is now cached with a much shorter TTL).
-- Expire any already-cached "property"/"rooms" entries that currently hold no
-- images so the very next request refetches from Lodgify immediately instead
-- of waiting out the old, longer TTL.
UPDATE lodgify_cache
SET expires_at = created_at
WHERE (cache_key LIKE 'lodgify:v2:property:%' OR cache_key LIKE 'lodgify:v2:rooms:%')
  AND (
    data = '[]'
    OR data LIKE '%"images":[]%'
  );
