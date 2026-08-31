<?php declare(strict_types=1); $primaryColor = $partner['primary_color'] ?? '#E61E4D'; $brandHref = $partner ? '/accueil' : '/';
$userDisplayName = '';
if (is_array($user ?? null)) {
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $userDisplayName = $fullName !== '' ? $fullName : (string) ($user['email'] ?? $user['role'] ?? '');
}
$avatarInitialSource = trim((string) (($user['first_name'] ?? '') ?: ($user['last_name'] ?? '') ?: ($user['email'] ?? 'U')));
$avatarInitial = strtoupper(substr($avatarInitialSource !== '' ? $avatarInitialSource : 'U', 0, 1));
$navLang = \App\I18n::current();
$navOtherLang = \App\I18n::other();
$navLangFlag = $navOtherLang === 'en' ? '🇬🇧' : '🇫🇷';
$navBackPath = (string) ($currentPath ?? '/');
$navLangHref = '/lang/' . $navOtherLang . '?back=' . rawurlencode($navBackPath);
$partnerAnalyticsVisible = false;
if (is_array($user) && ($user['role'] ?? '') === 'partner' && \App\Database::columnExists('partners', 'analytics_visible')) {
    $navPid = (int) ($user['partner_id'] ?? 0);
    if ($navPid > 0) {
        $navAnalyticsStmt = \App\Database::connection()->prepare('SELECT analytics_visible FROM partners WHERE id = ? LIMIT 1');
        $navAnalyticsStmt->execute([$navPid]);
        $navAnalyticsRow = $navAnalyticsStmt->fetch(\PDO::FETCH_ASSOC);
        $partnerAnalyticsVisible = $navAnalyticsRow && (int) ($navAnalyticsRow['analytics_visible'] ?? 0) === 1;
    }
}
// Client-facing links shared directly over WhatsApp/email (e.g. the public
// reservation page, /r/{token} — see PageController::reservationPublic())
// pass 'minimalHeader' => true to View::render() to hide the whole
// navigation menu, keeping only the brand logo/name (not a link) so the
// visitor isn't tempted/able to browse away from their reservation.
$minimalHeader = !empty($minimalHeader);
?>
<nav class="navbar<?= $minimalHeader ? ' navbar-minimal' : '' ?>">
  <div class="container navbar-inner">
    <?php if ($minimalHeader): ?>
      <span class="brand brand-static">
        <?php if (!empty($partner['logo_url'])): ?>
          <img src="<?= \App\View::e($partner['logo_url']) ?>" alt="<?= \App\View::e($partner['name'] ?? 'Partner') ?>" class="brand-logo">
          <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? '') ?></span>
        <?php else: ?>
          <span class="brand-name" style="color: <?= \App\View::e($primaryColor) ?>;"><?= \App\View::e($partner['name'] ?? 'Portail Partenaires') ?></span>
        <?php endif; ?>
      </span>
    <?php else: ?>
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
      <?php if (is_array($user) && ($user['role'] ?? '') === 'partner'): ?><a href="/partner/dashboard"><?= \App\View::e(\App\I18n::t('nav.dashboard')) ?></a><?php endif; ?>
      <?php if ($partnerAnalyticsVisible): ?><a href="/partner/analytics">Analyse</a><?php endif; ?>
      <?php if (is_array($user) && ($user['role'] ?? '') === 'admin'): ?><a href="/admin/partners"><?= \App\View::e(\App\I18n::t('nav.dashboard')) ?></a><?php endif; ?>
      <?php if ($partner): ?>
        <?php if (is_array($user)): ?>
          <details class="navbar-dropdown">
            <summary><?= \App\View::e(\App\I18n::t('nav.public_pages')) ?></summary>
            <div class="navbar-dropdown-menu">
              <a href="/properties"><?= \App\View::e(\App\I18n::t('nav.properties')) ?></a>
              <a href="/calendrier"><?= \App\View::e(\App\I18n::t('nav.calendar')) ?></a>
              <a href="/contact"><?= \App\View::e(\App\I18n::t('nav.contact')) ?></a>
            </div>
          </details>
        <?php else: ?>
          <a href="/properties"><?= \App\View::e(\App\I18n::t('nav.properties')) ?></a>
          <a href="/calendrier"><?= \App\View::e(\App\I18n::t('nav.calendar')) ?></a>
          <a href="/contact"><?= \App\View::e(\App\I18n::t('nav.contact')) ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <a class="navbar-lang-toggle" href="<?= \App\View::e($navLangHref) ?>" title="<?= \App\View::e(\App\I18n::t('nav.switch_to_en')) ?>" aria-label="<?= \App\View::e(\App\I18n::t('nav.switch_to_en')) ?>">
        <span aria-hidden="true"><?= $navLangFlag ?></span>
      </a>
      <?php if (is_array($user)): ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <details class="navbar-dropdown">
            <summary><?= \App\View::e(\App\I18n::t('nav.settings')) ?></summary>
            <div class="navbar-dropdown-menu">
              <a href="/admin/partners">Partenaires</a>
              <a href="/admin/reservations">Réservations</a>
              <a href="/admin/gallery">Galerie photo</a>
              <a href="/admin/templates">Templates email</a>
              <a href="/admin/lodgify-properties">Biens Lodgify</a>
              <a href="/admin/translations">Traductions</a>
              <a href="/admin/sync">Synchronisation</a>
              <a href="/admin/cron">Tâches planifiées (cron)</a>
              <a href="/admin/fees">Frais &amp; Taxes</a>
              <a href="/admin/politique-reservation">Politique de réservation</a>
              <a href="/admin/email-server-settings">Configuration serveur email</a>
              <a href="/admin/communication">Communication</a>
              <a href="/admin/analytics">Analyse</a>
              <a href="/admin/versions">Versions</a>
              <a href="/admin/diagnostic">Diagnostic</a>
              <a href="/admin/mise-a-jour">Mise à jour</a>
            </div>
          </details>
        <?php endif; ?>
        <?php
        $emailAllowed = is_array($user)
            && (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'partner')
            && \App\ImapManager::isEmailDomainAllowed((string) ($user['email'] ?? ''));
        ?>
        <?php if ($emailAllowed): ?>
          <a href="/email" target="_blank" rel="noopener" class="navbar-email-icon" id="navbar-email-icon" title="Email" aria-label="Email">
            <span class="navbar-email-icon-symbol" aria-hidden="true">✉️</span>
            <span class="navbar-email-icon-count" id="navbar-email-count" hidden></span>
          </a>
        <?php endif; ?>
        <?php
        $linkedPartners = [];
        if (is_array($user) && ($user['role'] ?? '') === 'partner' && !empty($user['partner_id'])) {
            $linkedPartners = \App\PartnerLinks::linkedPartners((int) $user['partner_id']);
        }
        ?>
        <?php if ($linkedPartners !== []): ?>
          <details class="navbar-dropdown navbar-link-menu">
            <summary class="navbar-link-trigger" title="Comptes liés" aria-label="Comptes liés">
              <span aria-hidden="true">🔗</span>
            </summary>
            <div class="navbar-dropdown-menu navbar-link-dropdown">
              <?php foreach ($linkedPartners as $linkedPartner): ?>
                <form method="post" action="/partner/switch/<?= (int) $linkedPartner['id'] ?>">
                  <button type="submit" class="navbar-link-item"><?= \App\View::e((string) $linkedPartner['name']) ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
        <details class="navbar-dropdown navbar-user-menu">
          <summary class="navbar-avatar-trigger" title="<?= \App\View::e(\App\I18n::t('nav.account')) ?>" aria-label="<?= \App\View::e(\App\I18n::t('nav.account')) ?>">
            <?php if (!empty($user['photo_url'])): ?>
              <img class="navbar-avatar-image" src="<?= \App\View::e($user['photo_url']) ?>" alt="<?= \App\View::e($userDisplayName) ?>">
            <?php else: ?>
              <span class="navbar-avatar-fallback"><?= \App\View::e($avatarInitial) ?></span>
            <?php endif; ?>
          </summary>
          <div class="navbar-dropdown-menu navbar-user-dropdown">
            <a href="/account"><?= \App\View::e(\App\I18n::t('nav.view_profile')) ?></a>
            <a href="/logout"><?= \App\View::e(\App\I18n::t('nav.logout')) ?></a>
          </div>
        </details>
        <?php if ($emailAllowed): ?>
          <script>
            (function () {
              function refreshUnreadCount() {
                fetch('/api/email/unread-count', { credentials: 'same-origin' })
                  .then(function (res) { return res.ok ? res.json() : null; })
                  .then(function (data) {
                    var count = data && data.unread_count ? parseInt(data.unread_count, 10) : 0;
                    var countEl = document.getElementById('navbar-email-count');
                    if (!countEl) return;
                    if (count > 0) {
                      countEl.textContent = count > 99 ? '99+' : String(count);
                      countEl.hidden = false;
                    } else {
                      countEl.hidden = true;
                    }
                  })
                  .catch(function () { /* silently ignore — leave badge as-is, icon stays visible */ });
              }
              refreshUnreadCount();
              // Keep the badge in sync in the background, without ever
              // reloading the page or interrupting whatever the user is
              // doing — just a quiet periodic re-fetch.
              setInterval(refreshUnreadCount, 5 * 60 * 1000);
            })();
          </script>
        <?php endif; ?>
      <?php else: ?>
        <?php if (!empty($authDebug['cookie_present']) && empty($authDebug['valid'])): ?>
          <span class="navbar-user-info"><?= \App\View::e(\App\I18n::t('nav.session_invalid')) ?></span>
        <?php endif; ?>
        <a class="btn-icon" style="background-color: <?= \App\View::e($primaryColor) ?>;" href="/login" title="<?= \App\View::e(\App\I18n::t('nav.login')) ?>" aria-label="<?= \App\View::e(\App\I18n::t('nav.login')) ?>">
          <span aria-hidden="true">🔑</span>
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$minimalHeader): ?>
  <button class="navbar-mobile-backdrop" type="button" aria-label="Fermer le menu" data-mobile-nav-backdrop></button>
  <?php endif; ?>
</nav>
