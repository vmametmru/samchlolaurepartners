<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Paramètres du compte</h1>
  <form class="card card-body stack-md" method="post" action="/partner/settings" enctype="multipart/form-data">
    <label><span>Code partenaire</span><input class="input" type="text" value="<?= \App\View::e($partnerData['subdomain'] ?? '') ?>" disabled></label>
    <label><span>Nom du partenaire</span><input class="input" type="text" name="name" value="<?= \App\View::e($partnerData['name'] ?? '') ?>"></label>
    <label><span>Email de contact</span><input class="input" type="email" name="email" value="<?= \App\View::e($partnerData['email'] ?? '') ?>"></label>
    <label><span>No de téléphone</span><input class="input" type="tel" name="phone" value="<?= \App\View::e($partnerData['phone'] ?? '') ?>"></label>
    <label><span>Page Facebook</span><input class="input" type="url" name="facebook_url" placeholder="https://facebook.com/..." value="<?= \App\View::e($partnerData['facebook_url'] ?? '') ?>"></label>
    <label><span>Page TikTok</span><input class="input" type="url" name="tiktok_url" placeholder="https://tiktok.com/@..." value="<?= \App\View::e($partnerData['tiktok_url'] ?? '') ?>"></label>
    <label><span>Page Instagram</span><input class="input" type="url" name="instagram_url" placeholder="https://instagram.com/..." value="<?= \App\View::e($partnerData['instagram_url'] ?? '') ?>"></label>
    <div class="logo-upload-card">
      <label><span>Logo</span><input class="input" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
      <?php if (!empty($partnerData['logo_url'])): ?>
        <div class="logo-preview-wrap">
          <img src="<?= \App\View::e($partnerData['logo_url']) ?>" alt="Logo partenaire" class="logo-preview-small">
          <label class="logo-remove-chip" title="Supprimer le logo">
            <input type="checkbox" name="remove_logo" value="1">
            <span aria-hidden="true">🗑️</span>
            <span>Effacer</span>
          </label>
        </div>
      <?php endif; ?>
    </div>
    <label><span>Couleur principale</span><div class="color-row"><input type="color" name="primary_color" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>"><input class="input" type="text" name="primary_color_text" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>" data-sync-color></div></label>
    <h2 class="section-title">Configuration SMTP</h2>
    <p class="muted">Sécurité: SSL/TLS (obligatoire). Si vous laissez vide, les paramètres admin seront utilisés.</p>
    <div class="form-grid cols-2">
      <label><span>Hôte SMTP</span><input class="input" type="text" name="smtp_host" value="<?= \App\View::e($partnerData['smtp_host'] ?? ($smtpDefaults['smtp_host'] ?? 'mail.grand-baie-maurice.com')) ?>"></label>
      <label><span>Port SMTP</span><input class="input" type="number" name="smtp_port" value="<?= \App\View::e((string) ($partnerData['smtp_port'] ?? ($smtpDefaults['smtp_port'] ?? '465'))) ?>"></label>
      <label><span>Utilisateur SMTP</span><input class="input" type="text" name="smtp_user" value="<?= \App\View::e($partnerData['smtp_user'] ?? ($smtpDefaults['smtp_user'] ?? 'infos@grand-baie-maurice.com')) ?>"></label>
      <label><span>Mot de passe SMTP</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($partnerData['smtp_pass'] ?? '') ?>"></label>
    </div>
    <p class="muted">Email d'envoi par défaut (admin): <?= \App\View::e($smtpDefaults['smtp_from_email'] ?? 'infos@grand-baie-maurice.com') ?></p>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>
</section>
