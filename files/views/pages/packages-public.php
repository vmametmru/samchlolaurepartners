<?php declare(strict_types=1);
/** Public list of the partner's bookable offers. */
$e = static fn (mixed $value): string => \App\View::e($value);
?>
<section class="container section-lg">
  <h1>Offres Complètes</h1>
  <p class="muted">Vol, hébergement et activités réunis dans une seule offre.</p>

  <?php if ($packages === []): ?>
    <div class="card card-body mt-16">
      <p class="muted">Aucune offre n'est proposée pour le moment.</p>
    </div>
  <?php else: ?>
    <div class="property-grid mt-16">
      <?php foreach ($packages as $package): ?>
        <a class="card property-card" href="/offres/<?= (int) $package['id'] ?>">
          <?php if (!empty($package['photo_url'])): ?>
            <div class="property-card-image"><img src="<?= $e($package['photo_url']) ?>" alt="<?= $e(\App\Packages::text($package, 'title')) ?>" loading="lazy"></div>
          <?php endif; ?>
          <div class="card-body">
            <h3><?= $e(\App\Packages::text($package, 'title')) ?></h3>
            <p><?= \App\View::plainText(\App\Packages::text($package, 'description'), 140) ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
