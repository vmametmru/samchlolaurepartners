<?php declare(strict_types=1); $primaryColor = $partner['primary_color'] ?? '#E61E4D'; ?>
<nav class="navbar">
  <div class="container navbar-inner">
    <a href="/" class="brand">
      <?php if (!empty($partner['logo_url'])): ?>
        <img src="<?= \App\View::e($partner['logo_url']) ?>" alt="<?= \App\View::e($partner['name'] ?? 'Partner') ?>" class="brand-logo">
      <?php else: ?>
        <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? 'Partners Portal') ?></span>
      <?php endif; ?>
    </a>
    <div class="navbar-links">
      <a href="/properties">Hébergements</a>
      <a href="/calendrier">Calendrier</a>
      <a href="/contact">Contact</a>
      <?php if (is_array($user)): ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a href="/admin/partners">Admin</a><a href="/admin/sync">Synchronisation</a><a href="/admin/fees">Frais</a><a href="/admin/versions">Versions</a><a href="/admin/diagnostic">Diagnostic</a><?php endif; ?>
        <?php if (in_array($user['role'] ?? '', ['partner', 'admin'], true)): ?><a href="/partner/dashboard">Dashboard</a><?php endif; ?>
        <span class="navbar-user-info">Connecté : <?= \App\View::e($user['email'] ?? '') ?> (<?= \App\View::e($user['role'] ?? '') ?>)</span>
        <a class="btn-secondary" href="/logout">Déconnexion</a>
      <?php else: ?>
        <?php if (!empty($authDebug['cookie_present']) && empty($authDebug['valid'])): ?>
          <span class="navbar-user-info">Session invalide ou expirée — reconnectez-vous.</span>
        <?php endif; ?>
        <a class="btn-primary" style="background-color: <?= \App\View::e($primaryColor) ?>;" href="/login">Connexion</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
