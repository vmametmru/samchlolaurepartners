-- Reset all REQUEST_RECEIVED_CLIENT templates that don't already use the new
-- styled layout (identified by the absence of the logo_partenaire placeholder).
-- This covers cases where migration 022 did not match (e.g. template had already
-- been customised and no longer contained {{partenaire}}).
UPDATE email_templates
SET subject  = 'Votre demande de séjour est bien reçue - {{hebergement}}',
    body_html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
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
<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Vos Voyageurs :</p>
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
</div>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND body_html NOT LIKE '%logo_partenaire%';
