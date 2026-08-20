<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Configuration du serveur de messagerie</h1>
  
  <h2>SMTP (Envoi d'emails)</h2>
  <form class="card card-body stack-md" method="post" action="/admin/email-server-settings">
    <label><span>Sécurité</span><input class="input" type="text" value="SSL/TLS (obligatoire)" disabled></label>
    <label><span>Serveur SMTP</span><input class="input" type="text" name="smtp_host" required value="<?= \App\View::e($smtpDefaults['SMTP_HOST'] ?? 'mail.grand-baie-maurice.com') ?>"></label>
    <label><span>Port SMTP</span><input class="input" type="number" name="smtp_port" required value="<?= \App\View::e((string) ($smtpDefaults['SMTP_PORT'] ?? '465')) ?>"></label>
    <label><span>Username SMTP</span><input class="input" type="email" name="smtp_user" required value="<?= \App\View::e($smtpDefaults['SMTP_USER'] ?? 'infos@grand-baie-maurice.com') ?>"></label>
    <label><span>Password SMTP</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($smtpDefaults['SMTP_PASS'] ?? '') ?>"></label>
    <label><span>Email d'envoi par défaut</span><input class="input" type="email" name="smtp_from_email" required value="<?= \App\View::e($smtpDefaults['SMTP_FROM_EMAIL'] ?? 'infos@grand-baie-maurice.com') ?>"></label>
    <label><span>Nom d'envoi par défaut</span><input class="input" type="text" name="smtp_from_name" value="<?= \App\View::e($smtpDefaults['SMTP_FROM_NAME'] ?? 'Grand Baie Maurice') ?>"></label>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>

  <h2>IMAP (Réception d'emails - Webmail)</h2>
  <p class="text-muted">
    Configurez ici le serveur IMAP pour le domaine email. 
    Les partenaires et admins pourront consulter et envoyer des emails via le webmail intégré.
  </p>
  <form class="card card-body stack-md" method="post" action="/admin/email-server-settings">
    <label><span>Serveur IMAP</span><input class="input" type="text" name="imap_host" required value="<?= \App\View::e($smtpDefaults['IMAP_HOST'] ?? 'mail.grand-baie-maurice.com') ?>"></label>
    <label><span>Port IMAP</span><input class="input" type="number" name="imap_port" required value="<?= \App\View::e((string) ($smtpDefaults['IMAP_PORT'] ?? '993')) ?>"></label>
    <label><span>Utilisateur IMAP</span><input class="input" type="email" name="imap_user" required value="<?= \App\View::e($smtpDefaults['IMAP_USER'] ?? 'infos@grand-baie-maurice.com') ?>"></label>
    <label><span>Mot de passe IMAP</span><input class="input" type="password" name="imap_pass" value="<?= \App\View::e($smtpDefaults['IMAP_PASS'] ?? '') ?>"></label>
    <label><span>Domaine email (pour webmail)</span><input class="input" type="text" name="email_domain" required value="<?= \App\View::e($smtpDefaults['EMAIL_DOMAIN'] ?? 'grand-baie-maurice.com') ?>"></label>
    <small class="muted">Domaine utilisé pour le webmail (ex: grand-baie-maurice.com)</small>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>

  <h2>Signature DKIM</h2>
  <p class="text-muted">
    DKIM étant "valide" chez l'hébergeur ne garantit pas que chaque email envoyé par le site
    soit réellement signé : le serveur SMTP peut accepter le message sans le signer, ce qui
    fait passer le SPF mais échouer le DKIM. En renseignant ci-dessous le domaine, le sélecteur
    et la clé privée DKIM (générés côté hébergeur/DNS), le site signera lui-même chaque email
    sortant. Laisser vide pour désactiver.
  </p>
  <form class="card card-body stack-md" method="post" action="/admin/email-server-settings">
    <label><span>Domaine DKIM</span><input class="input" type="text" name="dkim_domain" placeholder="grand-baie-maurice.com" value="<?= \App\View::e($smtpDefaults['DKIM_DOMAIN'] ?? '') ?>"></label>
    <label><span>Sélecteur DKIM</span><input class="input" type="text" name="dkim_selector" placeholder="default" value="<?= \App\View::e($smtpDefaults['DKIM_SELECTOR'] ?? '') ?>"></label>
    <label><span>Clé privée DKIM (PEM)</span><textarea class="input" name="dkim_private_key" rows="8" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?= \App\View::e($smtpDefaults['DKIM_PRIVATE_KEY'] ?? '') ?></textarea></label>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>
</section>
