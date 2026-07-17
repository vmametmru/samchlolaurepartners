-- Follow-up to 017: remove the redundant "⏳ Votre demande de séjour est bien
-- reçue !" heading (and "– {{partenaire}}" subject suffix) now that the
-- subject line already carries that text, and only ever change rows still
-- holding the exact previous default so partners who customised their
-- template keep their own wording (see 002_default_email_templates.sql /
-- install.php for the current default).
UPDATE email_templates
SET subject = '⏳ Votre demande de séjour est bien reçue !',
    body_html = '<p>Bonjour {{nom_client}},</p>
<p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>
{{photo_bien}}
<p>Voici le récapitulatif de votre demande :</p>
<ul>
  <li><strong>Hébergement :</strong> {{hebergement}}</li>
  <li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li>
  <li><strong>Statut :</strong> 🔄 En attente de vérification</li>
</ul>
<h3>Que se passe-t-il maintenant ?</h3>
<p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p>
<p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p>
<p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p>
<p>Nous faisons au plus vite pour revenir vers vous !</p>
<p>Chaleureusement,</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND subject = '⏳ Votre demande de séjour est bien reçue ! – {{partenaire}}'
  AND body_html = '<h2>⏳ Votre demande de séjour est bien reçue !</h2>
<p>Bonjour {{nom_client}},</p>
<p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>
{{photo_bien}}
<p>Voici le récapitulatif de votre demande :</p>
<ul>
  <li><strong>Hébergement :</strong> {{hebergement}}</li>
  <li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li>
  <li><strong>Statut :</strong> 🔄 En attente de vérification</li>
</ul>
<h3>Que se passe-t-il maintenant ?</h3>
<p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p>
<p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p>
<p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p>
<p>Nous faisons au plus vite pour revenir vers vous !</p>
<p>Chaleureusement,</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>';

UPDATE email_templates
SET subject = '⏳ Votre demande de séjour est bien reçue !',
    body_html = '<p>Bonjour {{nom_client}},</p><p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>{{photo_bien}}<p>Voici le récapitulatif de votre demande :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li><li><strong>Statut :</strong> 🔄 En attente de vérification</li></ul><h3>Que se passe-t-il maintenant ?</h3><p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p><p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p><p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p><p>Nous faisons au plus vite pour revenir vers vous !</p><p>Chaleureusement,</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND subject = '⏳ Votre demande de séjour est bien reçue ! – {{partenaire}}'
  AND body_html = '<h2>⏳ Votre demande de séjour est bien reçue !</h2><p>Bonjour {{nom_client}},</p><p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>{{photo_bien}}<p>Voici le récapitulatif de votre demande :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li><li><strong>Statut :</strong> 🔄 En attente de vérification</li></ul><h3>Que se passe-t-il maintenant ?</h3><p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p><p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p><p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p><p>Nous faisons au plus vite pour revenir vers vous !</p><p>Chaleureusement,</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>';

-- Fix the client-facing partner link (rendered via {{lien_partenaire}}) still
-- pointing at a stale non-https / localhost install-time URL instead of the
-- live production site. Broad match (not just the exact default) since the
-- value may have been hand-edited to "http://grand-baie-maurice.com" (still
-- wrong scheme) rather than left at the literal install default. Any value
-- already on https:// (a deliberately configured custom domain) is left
-- untouched. Note: as of this migration, email image/link generation no
-- longer even relies on this setting (see Auth::currentBaseUrl(), used by
-- ReservationsController/AccountController) — it now derives the scheme+host
-- from the actual request, so this setting can no longer go stale for that
-- purpose. This UPDATE remains as a safety net for any other APP_URL reader.
UPDATE settings
SET value = 'https://www.grand-baie-maurice.com',
    updated_at = NOW()
WHERE `key` = 'APP_URL'
  AND (value = '' OR value IS NULL OR value LIKE 'http://%');
