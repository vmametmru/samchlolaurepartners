<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Paramètres du compte</h1>
  <form class="card card-body stack-md" method="post" action="/partner/settings" enctype="multipart/form-data" data-catalog-form>
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
    <div class="logo-upload-card">
      <label><span>Catalogue PDF (20 Mo max)</span><input class="input" type="file" name="catalog_pdf" accept="application/pdf" data-catalog-input></label>
      <p class="muted">Ce catalogue sera téléchargeable depuis votre tableau de bord pour l'envoyer à vos clients.</p>
      <?php if (!empty($partnerData['catalog_pdf_url'])): ?>
        <div class="logo-preview-wrap">
          <a href="<?= \App\View::e($partnerData['catalog_pdf_url']) ?>" target="_blank" rel="noopener">📄 Voir le catalogue actuel</a>
          <label class="logo-remove-chip" title="Supprimer le catalogue">
            <input type="checkbox" name="remove_catalog_pdf" value="1">
            <span aria-hidden="true">🗑️</span>
            <span>Effacer</span>
          </label>
        </div>
      <?php endif; ?>
      <div class="update-progress" data-catalog-progress hidden
           data-label-uploading="Envoi du catalogue…"
           data-label-done="Terminé">
        <div class="update-progress-track"><span class="update-progress-bar" data-catalog-progress-bar style="width:0%"></span></div>
        <p class="update-progress-label"><span data-catalog-progress-text>Envoi du catalogue…</span> <span data-catalog-progress-pct>0%</span></p>
      </div>
    </div>
    <label><span>Couleur principale</span><div class="color-row"><input type="color" name="primary_color" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>"><input class="input" type="text" name="primary_color_text" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>" data-sync-color></div></label>
    <h2 class="section-title">Configuration SMTP</h2>
    <p class="muted">
      Sécurité: SSL/TLS (obligatoire). Si vous laissez vide, les paramètres admin seront utilisés.
      <br>Votre identifiant email SMTP: <strong><?= \App\View::e($partnerData['user_email'] ?? '') ?></strong>
    </p>
    <div class="form-grid cols-2">
      <label><span>Hôte SMTP</span><input class="input" type="text" name="smtp_host" value="<?= \App\View::e($partnerData['smtp_host'] ?? ($smtpDefaults['smtp_host'] ?? 'mail.grand-baie-maurice.com')) ?>"></label>
      <label><span>Port SMTP</span><input class="input" type="number" name="smtp_port" value="<?= \App\View::e((string) ($partnerData['smtp_port'] ?? ($smtpDefaults['smtp_port'] ?? '465'))) ?>"></label>
      <label><span>Utilisateur SMTP</span><input class="input" type="text" name="smtp_user" value="<?= \App\View::e($partnerData['smtp_user'] ?? ($smtpDefaults['smtp_user'] ?? 'infos@grand-baie-maurice.com')) ?>"></label>
      <label><span>Mot de passe SMTP</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($partnerData['smtp_pass'] ?? '') ?>"></label>
    </div>
    <p class="muted">Email d'envoi par défaut (admin): <?= \App\View::e($smtpDefaults['smtp_from_email'] ?? 'infos@grand-baie-maurice.com') ?></p>

    <h2 class="section-title">Accès Webmail (IMAP)</h2>
    <p class="muted">
      Le webmail utilise votre identifiant email: <strong><?= \App\View::e($partnerData['user_email'] ?? '') ?></strong>
      (serveur <?= \App\View::e($smtpDefaults['imap_host'] ?? 'mail.grand-baie-maurice.com') ?>).
      Configurez votre mot de passe email depuis <a href="/account">votre profil</a>.
    </p>
    <button class="btn-primary" type="submit">Sauvegarder</button>
  </form>

  <div class="card card-body stack-md">
    <h2 class="section-title">Politiques de réservation</h2>
    <p class="muted">Créez autant de politiques de réservation/annulation que nécessaire (ex: "Standard", "Longue durée") et choisissez celle qui s'applique lors d'une demande de réservation depuis le calendrier ou l'onglet "Tarifs &amp; Disponibilités". La politique marquée "par défaut" est celle affichée sous le calendrier de vos pages hébergement et utilisée dans vos emails via la variable <code>{{politique_reservation}}</code>.</p>

    <?php foreach (($bookingPolicies ?? []) as $policy): ?>
      <form class="policy-card stack-md" method="post" action="/partner/settings/policies/<?= (int) $policy['id'] ?>">
        <div class="form-grid cols-2">
          <label><span>Nom de la politique</span><input class="input" type="text" name="label" value="<?= \App\View::e((string) $policy['label']) ?>" required></label>
          <label class="policy-default-flag">
            <input type="radio" name="default_policy_radio" disabled<?= !empty($policy['is_default']) ? ' checked' : '' ?>>
            <span><?= !empty($policy['is_default']) ? 'Politique par défaut' : 'Non par défaut' ?></span>
          </label>
        </div>
        <label>
          <span>Texte (Français)</span>
          <div class="policy-editor" data-policy-editor contenteditable="true"><?= (string) $policy['text_fr'] ?></div>
          <input type="hidden" name="text_fr" data-policy-editor-input>
        </label>
        <label>
          <span>Texte (Anglais) — affiché quand le site est en anglais</span>
          <div class="policy-editor" data-policy-editor contenteditable="true"><?= (string) ($policy['text_en'] ?? '') ?></div>
          <input type="hidden" name="text_en" data-policy-editor-input>
        </label>
        <div class="policy-card-actions">
          <button class="btn-primary" type="submit">Sauvegarder</button>
          <?php if (empty($policy['is_default'])): ?>
            <button class="btn-secondary" type="submit" formaction="/partner/settings/policies/<?= (int) $policy['id'] ?>/default">Définir par défaut</button>
          <?php endif; ?>
          <button class="btn-secondary policy-delete-btn" type="submit" formaction="/partner/settings/policies/<?= (int) $policy['id'] ?>/delete" data-confirm="Supprimer cette politique de réservation ?">Supprimer</button>
        </div>
      </form>
    <?php endforeach; ?>

    <form class="policy-card stack-md" method="post" action="/partner/settings/policies">
      <h3 class="section-title">Ajouter une politique</h3>
      <label><span>Nom de la politique</span><input class="input" type="text" name="label" placeholder="Ex: Standard, Longue durée…" required></label>
      <label>
        <span>Texte (Français)</span>
        <div class="policy-editor" data-policy-editor contenteditable="true"></div>
        <input type="hidden" name="text_fr" data-policy-editor-input>
      </label>
      <label>
        <span>Texte (Anglais) — affiché quand le site est en anglais</span>
        <div class="policy-editor" data-policy-editor contenteditable="true"></div>
        <input type="hidden" name="text_en" data-policy-editor-input>
      </label>
      <button class="btn-primary" type="submit">Créer la politique</button>
    </form>
  </div>
</section>
