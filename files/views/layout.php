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
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= \App\View::e($pageTitle) ?></title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=<?= \App\View::e($cssVersion) ?>">
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
  <script src="/assets/js/app.js?v=<?= \App\View::e($jsVersion) ?>" defer></script>
</body>
</html>
