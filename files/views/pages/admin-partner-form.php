<?php declare(strict_types=1); $action = $editing ? '/admin/partners/' . (int) $partnerData['id'] : '/admin/partners'; ?>
<section class="container section-lg narrow">
  <h1><?= $editing ? 'Modifier le partenaire' : 'Nouveau partenaire' ?></h1>
  <form class="card card-body stack-md" method="post" action="<?= \App\View::e($action) ?>">
    <label><span>Nom *</span><input class="input" type="text" name="name" required value="<?= \App\View::e($partnerData['name'] ?? '') ?>"></label>
    <label><span>Code Partenaire *</span><input class="input" type="text" name="subdomain" <?= $editing ? 'disabled' : '' ?> required value="<?= \App\View::e($partnerData['subdomain'] ?? '') ?>"></label>
    <label><span>Email de contact *</span><input class="input" type="email" name="email" required value="<?= \App\View::e($partnerData['email'] ?? '') ?>"></label>
    <label><span>Marge % *</span><input class="input" type="number" name="markup_percent" min="0" max="100" step="0.5" value="<?= \App\View::e((string) ($partnerData['markup_percent'] ?? 0)) ?>"></label>
    <label><span>Nettoyage (coût par nuit et par personne) *</span><input class="input" type="number" name="cleaning_fee_per_person_per_night" min="0" step="0.01" value="<?= \App\View::e((string) ($partnerData['cleaning_fee_per_person_per_night'] ?? 0)) ?>"></label>
    <label><span>URL du logo</span><input class="input" type="url" name="logo_url" value="<?= \App\View::e($partnerData['logo_url'] ?? '') ?>"></label>
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
</section>
