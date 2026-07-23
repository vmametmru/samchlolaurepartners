<?php declare(strict_types=1); ?>
<section class="container section-lg">
  <div class="section-header">
    <h1><?= \App\View::e(\App\I18n::t('properties.all_title')) ?></h1>
    <form method="get" action="/properties">
      <input class="input" type="text" name="q" value="<?= \App\View::e($query) ?>" placeholder="<?= \App\View::e(\App\I18n::t('properties.search_placeholder')) ?>">
    </form>
  </div>
  <div class="content-with-sidebar">
    <div>
      <?php if ($properties === []): ?>
        <p class="empty-state"><?= \App\View::e(\App\I18n::t('properties.no_result')) ?></p>
      <?php else: ?>
        <div class="property-grid">
          <?php foreach ($properties as $property): require BASE_PATH . '/files/views/partials/property-card.php'; endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php require BASE_PATH . '/files/views/partials/map-board.php'; ?>
  </div>
</section>
