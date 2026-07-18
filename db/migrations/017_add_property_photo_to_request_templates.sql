UPDATE email_templates
SET body_html = '<h2>Nouvelle demande de réservation</h2><p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p><p><strong>Hébergement :</strong> {{hebergement}}</p>{{photo_bien}}<p><strong>Dates :</strong> {{dates}}</p><p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p><p><strong>Message :</strong><br>{{message}}</p><hr><p>Veuillez traiter cette demande depuis votre espace partenaire.</p>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_PARTNER'
  AND body_html = '<h2>Nouvelle demande de réservation</h2><p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p><p><strong>Hébergement :</strong> {{hebergement}}</p><p><strong>Dates :</strong> {{dates}}</p><p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p><p><strong>Message :</strong><br>{{message}}</p><hr><p>Veuillez traiter cette demande depuis votre espace partenaire.</p>';

UPDATE email_templates
SET body_html = '<h2>Votre demande a bien été reçue</h2><p>Bonjour {{nom_client}},</p><p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p>{{photo_bien}}<p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>',
    updated_at = NOW()
WHERE type = 'REQUEST_RECEIVED_CLIENT'
  AND body_html = '<h2>Votre demande a bien été reçue</h2><p>Bonjour {{nom_client}},</p><p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p><p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>';
