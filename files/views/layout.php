<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= \App\View::e($pageTitle) ?> · samchlolaurepartners</title>
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body data-partner-code="<?= \App\View::e($partner['subdomain'] ?? '') ?>">
  <?php require BASE_PATH . '/files/views/partials/navbar.php'; ?>
  <main class="page-shell">
    <?php if (is_array($flash)): ?>
      <div class="container pt-16">
        <div class="alert alert-<?= \App\View::e($flash['type'] ?? 'success') ?>"><?= \App\View::e($flash['message'] ?? '') ?></div>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>
  <script src="/assets/js/app.js" defer></script>
</body>
</html>
