-- Default email templates for partner ID 1
-- Replace partner_id = 1 with the actual partner ID, or run per partner

-- 1. Partner notification on new request
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REQUEST_RECEIVED_PARTNER',
 'Nouvelle demande de réservation - {{nom_client}}',
 '<h2>Nouvelle demande de réservation</h2>
<p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p>
<p><strong>Hébergement :</strong> {{hebergement}}</p>
{{photo_bien}}
<p><strong>Dates :</strong> {{dates}}</p>
<p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p>
<p><strong>Message :</strong><br>{{message}}</p>
<hr>
<p>Veuillez traiter cette demande depuis votre espace partenaire.</p>');

-- 2. Client acknowledgement
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REQUEST_RECEIVED_CLIENT',
 'Votre demande de réservation a bien été reçue',
 '<h2>Votre demande a bien été reçue</h2>
<p>Bonjour {{nom_client}},</p>
<p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p>
{{photo_bien}}
<p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p>
<p>Cordialement,<br><strong>{{partenaire}}</strong></p>');

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
