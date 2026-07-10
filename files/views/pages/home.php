<?php declare(strict_types=1); $primaryColor = $partner['primary_color'] ?? '#E61E4D'; ?>
<section class="hero" style="background: linear-gradient(135deg, <?= \App\View::e($primaryColor) ?>, #14b8a6);">
  <div class="container hero-inner">
    <h1><?= \App\View::e($partner ? 'Bienvenue chez ' . ($partner['name'] ?? '') : 'Trouvez votre hébergement idéal') ?></h1>
    <p>Séjours exceptionnels à l'île Maurice</p>
    <form class="search-card" method="get" action="/">
      <div class="form-grid search-grid" data-date-range>
        <label><span>Arrivée</span><input class="input" type="date" name="checkin" value="<?= \App\View::e($search['checkin']) ?>" required></label>
        <label><span>Départ</span><input class="input" type="date" name="checkout" value="<?= \App\View::e($search['checkout']) ?>" required></label>
        <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= \App\View::e((string) $search['adults']) ?>"></label>
        <label><span>Enfants (&lt;12)</span><input class="input" type="number" min="0" max="20" name="children" value="<?= \App\View::e((string) $search['children']) ?>"></label>
        <button class="btn-primary search-button" type="submit">Rechercher</button>
      </div>
    </form>
  </div>
</section>
<section class="container section-lg">
  <?php if (!$searched): ?>
    <p class="empty-state">Utilisez la recherche ci-dessus pour trouver des hébergements disponibles.</p>
  <?php else: ?>
    <div class="content-with-sidebar">
      <div>
        <h2 class="section-title"><?= count($properties) ?> hébergement<?= count($properties) !== 1 ? 's' : '' ?> disponible<?= count($properties) !== 1 ? 's' : '' ?></h2>
        <?php if ($properties === []): ?>
          <p class="empty-state">Aucun hébergement disponible pour ces dates.</p>
        <?php else: ?>
          <div class="property-grid">
            <?php foreach ($properties as $property): require BASE_PATH . '/files/views/partials/property-card.php'; endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($properties !== []): ?>
        <?php require BASE_PATH . '/files/views/partials/map-board.php'; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
