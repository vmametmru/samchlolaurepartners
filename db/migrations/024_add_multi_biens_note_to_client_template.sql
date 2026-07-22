-- The "Calendrier" board lets a visitor request several properties at once
-- (one separate confirmation email is sent per property). Add the new
-- {{multi_biens_note}} variable right after the "Vos Voyageurs :" label in
-- any template that still has the exact default wording, so multi-property
-- requests clarify that the guest counts shown apply to the combined
-- selection (e.g. "Pour les 2 biens sélectionnés"). Templates that were
-- already customised and no longer match this literal string are left
-- untouched — the admin can insert {{multi_biens_note}} manually from the
-- template editor's variable picker if desired.
UPDATE email_templates
SET body_html = REPLACE(
    body_html,
    '<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Vos Voyageurs :</p>',
    '<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Vos Voyageurs : <span style="font-weight:normal;font-size:13px;color:#6b7280;">{{multi_biens_note}}</span></p>'
),
    updated_at = NOW()
WHERE type IN ('REQUEST_RECEIVED_CLIENT', 'REQUEST_RECEIVED_PARTNER')
  AND body_html LIKE '%Vos Voyageurs :</p>%';
