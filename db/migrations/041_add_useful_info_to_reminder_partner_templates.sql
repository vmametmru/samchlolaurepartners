-- Fixes a reported bug where existing REMINDER_PARTNER templates (created
-- before this migration from PageController::adminTemplateCatalogFr()/En(),
-- which is now itself fixed to include this token for new templates) never
-- render {{useful_info}} because the token is simply absent from the saved
-- body_html — unlike REMINDER_CLIENT, whose templates already got this
-- token backfilled by migration 039.
-- Only appends the missing token when it isn't already present anywhere in
-- the template (so any admin/partner customization that already added it
-- manually is left untouched), for both the site-wide defaults
-- (default_email_templates) and every partner's own template
-- (email_templates).
UPDATE default_email_templates
SET body_html = CONCAT(body_html, '{{useful_info}}')
WHERE type = 'REMINDER_PARTNER'
  AND body_html NOT LIKE '%useful_info%';

UPDATE email_templates
SET body_html = CONCAT(body_html, '{{useful_info}}')
WHERE type = 'REMINDER_PARTNER'
  AND body_html NOT LIKE '%useful_info%';
