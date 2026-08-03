-- Fixes two reported bugs where existing email templates (created before
-- this migration from PageController::adminTemplateCatalogFr()/En(), which
-- is now itself fixed to include these tokens for new templates) are
-- missing variables that never render because the token is simply absent
-- from the saved body_html:
--   - RESERVATION_CONFIRMED (client confirmation email): {{politique_reservation}}
--   - REMINDER_CLIENT (pre-arrival reminder to the client): {{useful_info}}
-- Only appends the missing token when it isn't already present anywhere in
-- the template (so any admin/partner customization that already added it
-- manually is left untouched), for both the site-wide defaults
-- (default_email_templates) and every partner's own template
-- (email_templates).
UPDATE default_email_templates
SET body_html = CONCAT(body_html, '<p style="font-size:12px;color:#6b7280;">{{politique_reservation}}</p>')
WHERE type = 'RESERVATION_CONFIRMED'
  AND body_html NOT LIKE '%politique_reservation%';

UPDATE email_templates
SET body_html = CONCAT(body_html, '<p style="font-size:12px;color:#6b7280;">{{politique_reservation}}</p>')
WHERE type = 'RESERVATION_CONFIRMED'
  AND body_html NOT LIKE '%politique_reservation%';

UPDATE default_email_templates
SET body_html = CONCAT(body_html, '{{useful_info}}')
WHERE type = 'REMINDER_CLIENT'
  AND body_html NOT LIKE '%useful_info%';

UPDATE email_templates
SET body_html = CONCAT(body_html, '{{useful_info}}')
WHERE type = 'REMINDER_CLIENT'
  AND body_html NOT LIKE '%useful_info%';
