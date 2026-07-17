-- The REQUEST_RECEIVED_CLIENT template was already created (via INSERT IGNORE in
-- 002_default_email_templates.sql / install.php) for every existing partner before
-- the new "en attente de vérification" wording, property photo and signature block
-- were introduced. INSERT IGNORE never updates a pre-existing row, so partners kept
-- receiving the old email even after the seed file was changed. Only refresh rows
-- still holding the previous default text, so partners who already customised their
-- template keep their own wording.
UPDATE email_templates
SET subject = '⏳ Votre demande de séjour est bien reçue ! – {{partenaire}}',
    body_html = '<h2>⏳ Votre demande de séjour est bien reçue !</h2>
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
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND body_html = '<h2>Votre demande a bien été reçue</h2>
<p>Bonjour {{nom_client}},</p>
<p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p>
<p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p>
<p>Cordialement,<br><strong>{{partenaire}}</strong></p>';

UPDATE email_templates
SET subject = '⏳ Votre demande de séjour est bien reçue ! – {{partenaire}}',
    body_html = '<h2>⏳ Votre demande de séjour est bien reçue !</h2><p>Bonjour {{nom_client}},</p><p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>{{photo_bien}}<p>Voici le récapitulatif de votre demande :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li><li><strong>Statut :</strong> 🔄 En attente de vérification</li></ul><h3>Que se passe-t-il maintenant ?</h3><p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p><p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p><p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p><p>Nous faisons au plus vite pour revenir vers vous !</p><p>Chaleureusement,</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND subject = 'Votre demande de réservation a bien été reçue';
