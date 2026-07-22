UPDATE email_templates
SET body_html = CONCAT(body_html, '{{tarif_bloc}}')
WHERE type IN ('REQUEST_RECEIVED_PARTNER', 'REQUEST_RECEIVED_CLIENT')
  AND body_html NOT LIKE '%{{tarif_bloc}}%';
