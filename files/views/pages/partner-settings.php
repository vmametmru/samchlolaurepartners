<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Paramètres du compte</h1>
  <form class="card card-body stack-md" method="post" action="/partner/settings">
    <label><span>Nom du partenaire</span><input class="input" type="text" name="name" value="<?= \App\View::e($partnerData['name'] ?? '') ?>"></label>
    <label><span>Email de contact</span><input class="input" type="email" name="email" value="<?= \App\View::e($partnerData['email'] ?? '') ?>"></label>
    <label><span>URL du logo</span><input class="input" type="url" name="logo_url" value="<?= \App\View::e($partnerData['logo_url'] ?? '') ?>"></label>
    <label><span>Couleur principale</span><div class="color-row"><input type="color" name="primary_color" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>"><input class="input" type="text" name="primary_color_text" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>" data-sync-color></div></label>
    <h2 class="section-title">Configuration SMTP</h2>
    <div class="form-grid cols-2">
      <label><span>Hôte SMTP</span><input class="input" type="text" name="smtp_host" value="<?= \App\View::e($partnerData['smtp_host'] ?? '') ?>"></label>
      <label><span>Port SMTP</span><input class="input" type="number" name="smtp_port" value="<?= \App\View::e((string) ($partnerData['smtp_port'] ?? '')) ?>"></label>
      <label><span>Utilisateur SMTP</span><input class="input" type="text" name="smtp_user" value="<?= \App\View::e($partnerData['smtp_user'] ?? '') ?>"></label>
      <label><span>Mot de passe SMTP</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($partnerData['smtp_pass'] ?? '') ?>"></label>
    </div>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>
</section>
