<?php declare(strict_types=1); $primaryColor = $partner['primary_color'] ?? '#E61E4D'; $brandHref = $partner ? '/accueil' : '/'; ?>
<nav class="navbar">
  <div class="container navbar-inner">
    <a href="<?= \App\View::e($brandHref) ?>" class="brand">
      <?php if (!empty($partner['logo_url'])): ?>
        <img src="<?= \App\View::e($partner['logo_url']) ?>" alt="<?= \App\View::e($partner['name'] ?? 'Partner') ?>" class="brand-logo">
      <?php else: ?>
        <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? 'Partners Portal') ?></span>
      <?php endif; ?>
    </a>
    <div class="navbar-links">
      <?php if (is_array($user) && in_array($user['role'] ?? '', ['partner', 'admin'], true)): ?><a href="/partner/dashboard">Dashboard</a><?php endif; ?>
      <?php if ($partner): ?>
        <details class="navbar-dropdown">
          <summary>Pages Publiques</summary>
          <div class="navbar-dropdown-menu">
            <a href="/properties">Hébergements</a>
            <a href="/calendrier">Calendrier</a>
            <a href="/contact">Contact</a>
          </div>
        </details>
      <?php endif; ?>
      <?php if (is_array($user)): ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <details class="navbar-dropdown">
            <summary>Paramêtres</summary>
            <div class="navbar-dropdown-menu">
              <a href="/admin/partners">Partenaires</a>
              <a href="/admin/sync">Synchronisation</a>
              <a href="/admin/fees">Frais &amp; Taxes</a>
              <a href="/admin/versions">Versions</a>
            </div>
          </details>
        <?php endif; ?>
        <span class="navbar-user-info" title="Connecté : <?= \App\View::e($user['email'] ?? '') ?>">🔑 <?= \App\View::e($user['role'] ?? '') ?></span>
        <a class="btn-icon" href="/logout" title="Déconnexion" aria-label="Déconnexion">
          <span aria-hidden="true">🚪</span>
        </a>
      <?php else: ?>
        <?php if (!empty($authDebug['cookie_present']) && empty($authDebug['valid'])): ?>
          <span class="navbar-user-info">Session invalide ou expirée — reconnectez-vous.</span>
        <?php endif; ?>
        <a class="btn-icon" style="background-color: <?= \App\View::e($primaryColor) ?>;" href="/login" title="Connexion" aria-label="Connexion">
          <span aria-hidden="true">👤</span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</nav>
