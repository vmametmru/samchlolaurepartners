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
{{tarif_bloc}}
<p><strong>Message :</strong><br>{{message}}</p>
<hr>
<p>Veuillez traiter cette demande depuis votre espace partenaire.</p>');

-- 2. Client acknowledgement
INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES
(1, 'REQUEST_RECEIVED_CLIENT',
 'Votre demande de séjour est bien reçue - {{hebergement}}',
 '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
<div style="text-align:center;padding:28px 24px 16px;">
{{logo_partenaire}}
<p style="margin:14px 0 8px;font-size:17px;color:#374151;">Votre demande de séjour est bien reçue !</p>
<h2 style="margin:4px 0 0;font-size:22px;color:#111827;">{{hebergement}}</h2>
</div>
<div style="text-align:center;padding:0 24px 20px;">{{photo_bien}}</div>
<div style="padding:4px 24px 16px;">
<p style="margin:0 0 10px;font-size:15px;color:#111827;">Bonjour <strong>{{nom_client}}</strong>,</p>
<p style="margin:0;font-size:15px;color:#374151;">Un grand merci pour votre intérêt ! Nous avons bien reçu votre demande de réservation pour <strong>{{hebergement}}</strong>.</p>
</div>
<hr style="border:none;border-top:1px solid #e5e7eb;margin:0 24px;">
<div style="padding:16px 24px 8px;">
<p style="margin:0 0 8px;font-weight:bold;font-size:14px;color:#111827;">Vos Dates :</p>
<p style="margin:0;font-size:14px;color:#374151;">Du {{date_arrivee}} au {{date_depart}} =&gt; {{nuits}} nuit(s)</p>
</div>
<div style="padding:12px 24px 16px;">
<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Vos Voyageurs : <span style="font-weight:normal;font-size:13px;color:#6b7280;">{{multi_biens_note}}</span></p>
<table style="width:100%;border-collapse:collapse;font-size:14px;">
<tr><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#374151;">Nombre d''adulte(s):</td><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:bold;color:#111827;">{{adultes}}</td></tr>
<tr><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#374151;">Nombre d''enfant(s) &lt; 12 ans:</td><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:bold;color:#111827;">{{enfants}}</td></tr>
<tr><td style="padding:5px 0;color:#374151;">Nombre bébé(s) &lt; 3 ans:</td><td style="padding:5px 0;text-align:right;font-weight:bold;color:#111827;">{{bebes}}</td></tr>
</table>
</div>
{{tarif_bloc}}
<hr style="border:none;border-top:1px solid #e5e7eb;margin:0 24px;">
<div style="padding:16px 24px;">
<p style="margin:0 0 14px;font-weight:bold;font-size:15px;color:#111827;">Que se passe-t-il maintenant ?</p>
<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr>
<td style="width:32px;vertical-align:top;padding-top:2px;"><div style="width:26px;height:26px;border-radius:50%;background:#3b82f6;color:#fff;text-align:center;line-height:26px;font-size:14px;">i</div></td>
<td style="padding-left:12px;font-size:14px;color:#374151;vertical-align:top;">Votre demande a été transmise à notre équipe. Nous vérifions le planning et la disponibilité du logement sous 24 heures.</td>
</tr></table>
<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr>
<td style="width:32px;vertical-align:top;padding-top:2px;"><div style="width:26px;height:26px;border-radius:50%;background:#3b82f6;color:#fff;text-align:center;line-height:26px;font-size:14px;">i</div></td>
<td style="padding-left:12px;font-size:14px;color:#374151;vertical-align:top;">Si tout est au vert : vous recevrez un e-mail de confirmation officielle avec les informations concernant les modalités de paiement afin de bloquer définitivement vos dates.</td>
</tr></table>
<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr>
<td style="width:32px;vertical-align:top;padding-top:2px;"><div style="width:26px;height:26px;border-radius:50%;background:#3b82f6;color:#fff;text-align:center;line-height:26px;font-size:14px;">i</div></td>
<td style="padding-left:12px;font-size:14px;color:#374151;vertical-align:top;">⚠️ À noter : Tant que cette confirmation ne vous a pas été envoyée et que le paiement n''a pas été reçu, les dates restent temporairement disponibles sur le marché.</td>
</tr></table>
<p style="margin:0;font-size:14px;color:#374151;">Nous faisons au plus vite pour revenir vers vous !</p>
</div>
<hr style="border:none;border-top:1px solid #e5e7eb;margin:0 24px;">
<div style="padding:16px 24px;">
<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Statut de votre demande</p>
<span style="display:inline-block;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:20px;padding:5px 18px;font-size:13px;font-weight:bold;">En attente de vérification</span>
</div>
<hr style="border:none;border-top:1px solid #e5e7eb;margin:0 24px;">
<div style="padding:16px 24px 24px;font-size:13px;color:#374151;">
<p style="margin:0 0 10px;">Cordialement,</p>
<table style="border-collapse:collapse;"><tr>
<td style="vertical-align:top;padding-right:14px;">{{signature_photo}}</td>
<td style="vertical-align:middle;">
<p style="margin:0 0 3px;font-weight:bold;font-size:14px;color:#111827;">{{signature_nom}}</p>
<p style="margin:0 0 3px;">{{email_partenaire}} | {{telephone_partenaire}}</p>
<p style="margin:0;"><a href="{{lien_partenaire}}" style="color:#3b82f6;text-decoration:none;">{{lien_partenaire}}</a></p>
</td>
</tr></table>
</div>
</div>');

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
