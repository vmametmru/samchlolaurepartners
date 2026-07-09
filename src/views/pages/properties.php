<?php declare(strict_types=1); ?>
<section class="container section-lg">
  <div class="section-header">
    <h1>Tous les hébergements</h1>
    <form method="get" action="/properties">
      <input class="input" type="text" name="q" value="<?= \App\View::e($query) ?>" placeholder="Rechercher...">
    </form>
  </div>
  <div class="content-with-sidebar">
    <div>
      <?php if ($properties === []): ?>
        <p class="empty-state">Aucun résultat.</p>
      <?php else: ?>
        <div class="property-grid">
          <?php foreach ($properties as $property): require BASE_PATH . '/src/views/partials/property-card.php'; endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php require BASE_PATH . '/src/views/partials/map-board.php'; ?>
  </div>
</section>
