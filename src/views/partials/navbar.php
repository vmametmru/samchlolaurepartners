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
      <a href="/contact">Contact</a>
      <?php if (is_array($user)): ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a href="/admin/partners">Admin</a><?php endif; ?>
        <?php if (in_array($user['role'] ?? '', ['partner', 'admin'], true)): ?><a href="/partner/dashboard">Dashboard</a><?php endif; ?>
        <a class="btn-secondary" href="/logout">Déconnexion</a>
      <?php else: ?>
        <a class="btn-primary" style="background-color: <?= \App\View::e($primaryColor) ?>;" href="/login">Connexion</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
