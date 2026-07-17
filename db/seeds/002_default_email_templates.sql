-- Default email templates for partner ID 1
-- Replace partner_id = 1 with the actual partner ID, or run per partner

-- 1. Partner notification on new request
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REQUEST_RECEIVED_PARTNER',
 'Nouvelle demande de réservation - {{nom_client}}',
 '<h2>Nouvelle demande de réservation</h2>
<p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p>
<p><strong>Hébergement :</strong> {{hebergement}}</p>
<p><strong>Dates :</strong> {{dates}}</p>
<p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p>
<p><strong>Message :</strong><br>{{message}}</p>
<hr>
<p>Veuillez traiter cette demande depuis votre espace partenaire.</p>');

-- 2. Client acknowledgement
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REQUEST_RECEIVED_CLIENT',
 '⏳ Votre demande de séjour est bien reçue !',
 '<p>Bonjour {{nom_client}},</p>
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
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td>{{signature_photo}}</td><td style="padding-left:10px;vertical-align:middle;"><strong>{{signature_nom}}</strong><br>{{lien_partenaire}}<br>{{telephone_partenaire}}</td></tr></table>');

-- 3. Reservation confirmed
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'RESERVATION_CONFIRMED',
 'Votre réservation est confirmée ! 🎉',
 '<h2>Réservation confirmée</h2>
<p>Bonjour {{nom_client}},</p>
<p>Nous avons le plaisir de vous confirmer votre réservation :</p>
<ul>
  <li><strong>Hébergement :</strong> {{hebergement}}</li>
  <li><strong>Arrivée :</strong> {{date_arrivee}}</li>
  <li><strong>Départ :</strong> {{date_depart}}</li>
  <li><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</li>
</ul>
{{notes}}
<p>À très bientôt à l''île Maurice !</p>
<p>Cordialement,<br><strong>{{partenaire}}</strong></p>');

-- 4. Reservation cancelled
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'RESERVATION_CANCELLED',
 'Annulation de votre réservation',
 '<h2>Votre réservation a été annulée</h2>
<p>Bonjour {{nom_client}},</p>
<p>Nous vous informons que votre réservation pour <strong>{{hebergement}}</strong> ({{dates}}) a malheureusement dû être annulée.</p>
<p>N''hésitez pas à nous contacter pour explorer d''autres options.</p>
<p>Cordialement,<br><strong>{{partenaire}}</strong></p>');

-- 5. Reminder (J-30)
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REMINDER',
 'Rappel : votre séjour approche ! 🌴',
 '<h2>Votre séjour approche !</h2>
<p>Bonjour {{nom_client}},</p>
<p>Nous vous rappelons que votre séjour à <strong>{{hebergement}}</strong> approche :</p>
<ul>
  <li><strong>Arrivée :</strong> {{date_arrivee}}</li>
  <li><strong>Départ :</strong> {{date_depart}}</li>
</ul>
<p>N''hésitez pas à nous contacter si vous avez des questions.</p>
<p>À bientôt,<br><strong>{{partenaire}}</strong></p>');
