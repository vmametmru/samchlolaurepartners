<?php declare(strict_types=1); $action = $editing ? '/admin/partners/' . (int) $partnerData['id'] : '/admin/partners'; ?>
<section class="container section-lg narrow">
  <h1><?= $editing ? 'Modifier le partenaire' : 'Nouveau partenaire' ?></h1>
  <form class="card card-body stack-md" method="post" action="<?= \App\View::e($action) ?>" enctype="multipart/form-data" data-catalog-form>
    <label><span>Nom *</span><input class="input" type="text" name="name" required value="<?= \App\View::e($partnerData['name'] ?? '') ?>"></label>
    <label><span>Code Partenaire *</span><input class="input" type="text" name="subdomain" <?= $editing ? 'disabled' : '' ?> required value="<?= \App\View::e($partnerData['subdomain'] ?? '') ?>"></label>
    <label><span>Email de contact *</span><input class="input" type="email" name="email" required value="<?= \App\View::e($partnerData['email'] ?? '') ?>"></label>
    <label><span>No de téléphone</span><input class="input" type="tel" name="phone" value="<?= \App\View::e($partnerData['phone'] ?? '') ?>"></label>
    <label><span>Page Facebook</span><input class="input" type="url" name="facebook_url" value="<?= \App\View::e($partnerData['facebook_url'] ?? '') ?>"></label>
    <label><span>Page TikTok</span><input class="input" type="url" name="tiktok_url" value="<?= \App\View::e($partnerData['tiktok_url'] ?? '') ?>"></label>
    <label><span>Page Instagram</span><input class="input" type="url" name="instagram_url" value="<?= \App\View::e($partnerData['instagram_url'] ?? '') ?>"></label>
    <label><span>Marge % *</span><input class="input" type="number" name="markup_percent" min="0" max="100" step="0.5" value="<?= \App\View::e((string) ($partnerData['markup_percent'] ?? 0)) ?>"></label>
    <label><span>Nettoyage (coût par nuit et par personne) *</span><input class="input" type="number" name="cleaning_fee_per_person_per_night" min="0" step="0.01" value="<?= \App\View::e((string) ($partnerData['cleaning_fee_per_person_per_night'] ?? 0)) ?>"></label>
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
    <label><span>Couleur principale</span><div class="color-row"><input type="color" name="primary_color" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>"><input class="input" type="text" value="<?= \App\View::e($partnerData['primary_color'] ?? '#E61E4D') ?>" data-sync-color></div></label>
    <h2 class="section-title">SMTP</h2>
    <div class="form-grid cols-2">
      <label><span>Hôte</span><input class="input" type="text" name="smtp_host" value="<?= \App\View::e($partnerData['smtp_host'] ?? '') ?>"></label>
      <label><span>Port</span><input class="input" type="number" name="smtp_port" value="<?= \App\View::e((string) ($partnerData['smtp_port'] ?? '')) ?>"></label>
      <label><span>Utilisateur</span><input class="input" type="text" name="smtp_user" value="<?= \App\View::e($partnerData['smtp_user'] ?? '') ?>"></label>
      <label><span>Mot de passe</span><input class="input" type="password" name="smtp_pass" value="<?= \App\View::e($partnerData['smtp_pass'] ?? '') ?>"></label>
    </div>
    <label class="inline-check"><input type="checkbox" name="active" <?= empty($partnerData) || !isset($partnerData['active']) || (int) $partnerData['active'] === 1 ? 'checked' : '' ?>> Partenaire actif</label>
    <div class="button-row"><button class="btn-primary" type="submit">Sauvegarder</button><a class="btn-secondary" href="/admin/partners">Annuler</a></div>
  </form>
  <?php if ($editing && \App\Database::columnExists('partners', 'analytics_visible')): ?>
    <form method="post" action="/admin/partners/<?= (int) $partnerData['id'] ?>/analytics-toggle" class="mt-16">
      <label class="inline-check">
        <input type="checkbox" name="analytics_visible" value="1" onchange="this.form.submit()" <?= !empty($partnerData['analytics_visible']) && (int) $partnerData['analytics_visible'] === 1 ? 'checked' : '' ?>>
        Autoriser ce partenaire à voir le tableau d'analyse
      </label>
      <noscript><div class="button-row" style="margin-top:.75rem;"><button class="btn-primary" type="submit">Sauvegarder</button></div></noscript>
    </form>
  <?php endif; ?>
</section>
