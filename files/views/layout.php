<?php declare(strict_types=1);
// Cache-bust the CSS/JS bundles with each file's last-modified timestamp so
// browsers (mobile Safari in particular is very aggressive about caching
// static assets indefinitely) always pick up the latest deployed version
// instead of silently keeping a stale copy after a bugfix is deployed.
$cssPath = BASE_PATH . '/assets/css/styles.css';
$jsPath = BASE_PATH . '/assets/js/app.js';
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : '1';
?>
<!DOCTYPE html>
<html lang="<?= \App\View::e($lang ?? 'fr') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= \App\View::e($pageTitle) ?></title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=<?= \App\View::e($cssVersion) ?>">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <?php if (!empty($preloadHeroVideo)): ?>
    <link rel="preload" href="/medias/home.mp4" as="video" type="video/mp4">
  <?php endif; ?>
</head>
<body>
  <?php require BASE_PATH . '/files/views/partials/navbar.php'; ?>
  <main class="page-shell">
    <?php if (is_array($flash)): ?>
      <div class="container pt-16">
        <div class="alert alert-<?= \App\View::e($flash['type'] ?? 'success') ?>"><?= \App\View::e($flash['message'] ?? '') ?></div>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>
  <?php if (!empty($partner)): ?>
    <footer class="site-footer">
      <div class="container site-footer-inner">
        <?php if (!empty($partner['phone'])): ?><span>☎ <?= \App\View::e($partner['phone']) ?></span><?php endif; ?>
        <?php if (!empty($partner['facebook_url'])): ?><a href="<?= \App\View::e($partner['facebook_url']) ?>" target="_blank" rel="noopener noreferrer">Facebook</a><?php endif; ?>
        <?php if (!empty($partner['tiktok_url'])): ?><a href="<?= \App\View::e($partner['tiktok_url']) ?>" target="_blank" rel="noopener noreferrer">TikTok</a><?php endif; ?>
        <?php if (!empty($partner['instagram_url'])): ?><a href="<?= \App\View::e($partner['instagram_url']) ?>" target="_blank" rel="noopener noreferrer">Instagram</a><?php endif; ?>
      </div>
    </footer>
  <?php endif; ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
  <script src="/assets/js/app.js?v=<?= \App\View::e($jsVersion) ?>" defer></script>
</body>
</html>
