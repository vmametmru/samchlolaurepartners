<?php declare(strict_types=1); $primaryColor = $partner['primary_color'] ?? '#E61E4D'; $brandHref = $partner ? '/accueil' : '/';
$userDisplayName = '';
if (is_array($user ?? null)) {
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $userDisplayName = $fullName !== '' ? $fullName : (string) ($user['email'] ?? $user['role'] ?? '');
}
$avatarInitialSource = trim((string) (($user['first_name'] ?? '') ?: ($user['last_name'] ?? '') ?: ($user['email'] ?? 'U')));
$avatarInitial = strtoupper(substr($avatarInitialSource !== '' ? $avatarInitialSource : 'U', 0, 1));
?>
<nav class="navbar">
  <div class="container navbar-inner">
    <a href="<?= \App\View::e($brandHref) ?>" class="brand">
      <?php if (!empty($partner['logo_url'])): ?>
        <img src="<?= \App\View::e($partner['logo_url']) ?>" alt="<?= \App\View::e($partner['name'] ?? 'Partner') ?>" class="brand-logo">
        <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? '') ?></span>
      <?php else: ?>
        <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? 'Portail Partenaires') ?></span>
      <?php endif; ?>
    </a>
    <button class="navbar-mobile-toggle" type="button" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="navbar-links-panel" data-mobile-nav-toggle>
      <span aria-hidden="true">☰</span>
    </button>
    <div class="navbar-links" id="navbar-links-panel" data-mobile-nav-links>
      <?php if (is_array($user) && in_array($user['role'] ?? '', ['partner', 'admin'], true)): ?><a href="/partner/dashboard">Dashboard</a><?php endif; ?>
      <?php if ($partner): ?>
        <?php if (is_array($user)): ?>
          <details class="navbar-dropdown">
            <summary>Pages Publiques</summary>
            <div class="navbar-dropdown-menu">
              <a href="/properties">Hébergements</a>
              <a href="/calendrier">Calendrier</a>
              <a href="/contact">Contact</a>
            </div>
          </details>
        <?php else: ?>
          <a href="/properties">Hébergements</a>
          <a href="/calendrier">Calendrier</a>
          <a href="/contact">Contact</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (is_array($user)): ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <details class="navbar-dropdown">
            <summary>Paramêtres</summary>
            <div class="navbar-dropdown-menu">
              <a href="/admin/partners">Partenaires</a>
              <a href="/admin/templates">Templates email</a>
              <a href="/admin/lodgify-properties">Biens Lodgify</a>
              <a href="/admin/sync">Synchronisation</a>
              <a href="/admin/fees">Frais &amp; Taxes</a>
              <a href="/admin/smtp-settings">SMTP par défaut</a>
              <a href="/admin/versions">Versions</a>
              <a href="/admin/mise-a-jour">Mise à jour</a>
            </div>
          </details>
        <?php endif; ?>
        <details class="navbar-dropdown navbar-user-menu">
          <summary class="navbar-avatar-trigger" title="Mon compte" aria-label="Mon compte">
            <?php if (!empty($user['photo_url'])): ?>
              <img class="navbar-avatar-image" src="<?= \App\View::e($user['photo_url']) ?>" alt="<?= \App\View::e($userDisplayName) ?>">
            <?php else: ?>
              <span class="navbar-avatar-fallback"><?= \App\View::e($avatarInitial) ?></span>
            <?php endif; ?>
          </summary>
          <div class="navbar-dropdown-menu navbar-user-dropdown">
            <a href="/account">Voir profil</a>
            <a href="/logout">Se déconnecter</a>
          </div>
        </details>
      <?php else: ?>
        <?php if (!empty($authDebug['cookie_present']) && empty($authDebug['valid'])): ?>
          <span class="navbar-user-info">Session invalide ou expirée — reconnectez-vous.</span>
        <?php endif; ?>
        <a class="btn-icon" style="background-color: <?= \App\View::e($primaryColor) ?>;" href="/login" title="Connexion" aria-label="Connexion">
          <span aria-hidden="true">🔑</span>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <button class="navbar-mobile-backdrop" type="button" aria-label="Fermer le menu" data-mobile-nav-backdrop></button>
</nav>
