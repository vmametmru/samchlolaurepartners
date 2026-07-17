-- The client acknowledgement email's "Voici le récapitulatif de votre
-- demande" list only ever showed Hébergement/Dates/Statut — the requested
-- party size (adults / children / babies) was silently dropped even though
-- it's collected on the booking form. Add it to the recap list. Only ever
-- change rows still holding the exact previous default (see 018) so
-- partners who customised their template keep their own wording (see
-- 002_default_email_templates.sql / install.php for the current default).
UPDATE email_templates
SET body_html = '<p>Bonjour {{nom_client}},</p>
<p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>
{{photo_bien}}
<p>Voici le récapitulatif de votre demande :</p>
<ul>
  <li><strong>Hébergement :</strong> {{hebergement}}</li>
  <li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li>
  <li><strong>Nombre d''adulte(s) :</strong> {{adultes}}</li>
  <li><strong>Nombre d''enfant(s) &lt; 12 ans :</strong> {{enfants}}</li>
  <li><strong>Nombre de Bébé(s) &lt; 5 ans :</strong> {{bebes}}</li>
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
  AND subject = '⏳ Votre demande de séjour est bien reçue !'
  AND body_html = '<p>Bonjour {{nom_client}},</p>
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
SET body_html = '<p>Bonjour {{nom_client}},</p><p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>{{photo_bien}}<p>Voici le récapitulatif de votre demande :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li><li><strong>Nombre d''adulte(s) :</strong> {{adultes}}</li><li><strong>Nombre d''enfant(s) &lt; 12 ans :</strong> {{enfants}}</li><li><strong>Nombre de Bébé(s) &lt; 5 ans :</strong> {{bebes}}</li><li><strong>Statut :</strong> 🔄 En attente de vérification</li></ul><h3>Que se passe-t-il maintenant ?</h3><p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p><p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p><p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p><p>Nous faisons au plus vite pour revenir vers vous !</p><p>Chaleureusement,</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND subject = '⏳ Votre demande de séjour est bien reçue !'
  AND body_html = '<p>Bonjour {{nom_client}},</p><p>Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>{{photo_bien}}<p>Voici le récapitulatif de votre demande :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Dates souhaitées :</strong> Du {{date_arrivee}} au {{date_depart}}</li><li><strong>Statut :</strong> 🔄 En attente de vérification</li></ul><h3>Que se passe-t-il maintenant ?</h3><p>Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</p><p>Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</p><p>⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</p><p>Nous faisons au plus vite pour revenir vers vous !</p><p>Chaleureusement,</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>';
