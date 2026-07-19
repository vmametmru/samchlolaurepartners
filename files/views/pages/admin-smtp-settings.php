<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>SMTP par défaut</h1>
  <form class="card card-body stack-md" method="post" action="/admin/smtp-settings">
    <label><span>Sécurité</span><input class="input" type="text" value="SSL/TLS (obligatoire)" disabled></label>
    <label><span>Serveur SMTP</span><input class="input" type="text" name="smtp_host" required value="<?= \App\View::e($smtpDefaults['SMTP_HOST'] ?? 'mail.grand-baie-maurice.com') ?>"></label>
    <label><span>Port SMTP</span><input class="input" type="number" name="smtp_port" required value="<?= \App\View::e((string) ($smtpDefaults['SMTP_PORT'] ?? '465')) ?>"></label>
    <label><span>Username SMTP</span><input class="input" type="email" name="smtp_user" required value="<?= \App\View::e($smtpDefaults['SMTP_USER'] ?? 'infos@grand-baie-maurice.com') ?>"></label>
    <label><span>Password SMTP</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($smtpDefaults['SMTP_PASS'] ?? '') ?>"></label>
    <label><span>Email d'envoi par défaut</span><input class="input" type="email" name="smtp_from_email" required value="<?= \App\View::e($smtpDefaults['SMTP_FROM_EMAIL'] ?? 'infos@grand-baie-maurice.com') ?>"></label>
    <label><span>Nom d'envoi par défaut</span><input class="input" type="text" name="smtp_from_name" value="<?= \App\View::e($smtpDefaults['SMTP_FROM_NAME'] ?? 'Grand Baie Maurice') ?>"></label>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>
</section>
