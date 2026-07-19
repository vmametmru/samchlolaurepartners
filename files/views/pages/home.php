<?php declare(strict_types=1);
$primaryColor = $partner['primary_color'] ?? '#E61E4D';
$calendarSearchUrl = '/calendrier';
$checkinRaw = (string) ($search['checkin'] ?? '');
$checkoutRaw = (string) ($search['checkout'] ?? '');
try {
    if ($checkinRaw !== '' && $checkoutRaw !== '') {
        $checkinDate = new DateTimeImmutable($checkinRaw);
        $checkoutDate = new DateTimeImmutable($checkoutRaw);
        $fromDate = $checkinDate->modify('-7 days')->format('Y-m-d');
        $toDate = $checkoutDate->modify('+7 days')->format('Y-m-d');
        $calendarSearchUrl .= '?' . http_build_query([
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'adults' => (int) ($search['adults'] ?? 0),
            'children_under3' => (int) ($search['children_under3'] ?? 0),
            'children_3to12' => (int) ($search['children_3to12'] ?? 0),
            'autosearch' => '1',
        ]);
    }
} catch (Throwable $e) {
    // Keep the plain /calendrier fallback if dates are invalid.
}
?>
<section class="hero hero-video">
  <video class="hero-video-bg" src="/medias/home.mp4" autoplay muted loop playsinline preload="auto"></video>
  <div class="hero-video-overlay"></div>
  <div class="container hero-inner">
    <h1><?= \App\View::e($partner ? 'Bienvenue chez ' . ($partner['name'] ?? '') : 'Trouvez votre hébergement idéal') ?></h1>
    <p>Séjours exceptionnels à l'île Maurice</p>
    <form class="search-card" method="get" action="/accueil">
      <div class="form-grid search-grid" data-date-range>
        <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin" value="<?= \App\View::e($search['checkin']) ?>" required></label>
        <label><span>Date de départ</span><input class="input" type="date" name="checkout" value="<?= \App\View::e($search['checkout']) ?>" required></label>
        <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= \App\View::e((string) $search['adults']) ?>"></label>
        <label><span>Enfants (&lt;3ans)</span><input class="input" type="number" min="0" max="20" name="children_under3" value="<?= \App\View::e((string) $search['children_under3']) ?>"></label>
        <label><span>Enfants (3-11ans)</span><input class="input" type="number" min="0" max="20" name="children_3to12" value="<?= \App\View::e((string) $search['children_3to12']) ?>"></label>
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
          <?php if ($capacityExceeded): ?>
            <p class="empty-state">La capacité individuelle de nos biens ne permettent pas d'acceuillir <?= (int) $search['totalGuests'] ?> personnes. Vous pouvez cependant combiner plusieurs logements. Beaucoup de nos logements sont à la même adresse. Nous vous confirmerons par email si tous les biens choisis sont à la même adresse.</p>
            <div style="text-align:center;margin-top:1.5rem;">
              <a class="btn-primary" href="<?= \App\View::e($calendarSearchUrl) ?>">Rechercher avec plusieurs biens</a>
            </div>
          <?php else: ?>
            <p class="empty-state">Aucun hébergement disponible pour ces dates.</p>
          <?php endif; ?>
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
