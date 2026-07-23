<?php declare(strict_types=1);
$primaryColor = $partner['primary_color'] ?? '#E61E4D';
$babiesExceeded = $babiesExceeded ?? false;
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
<section class="hero hero-video"<?= $searched ? ' data-searched="1"' : '' ?>>
  <video class="hero-video-bg" src="/medias/home.mp4" muted loop playsinline preload="auto" data-hero-video></video>
  <div class="hero-video-overlay"></div>
  <div class="hero-video-loading" data-hero-video-loading>
    <div class="hero-video-loading-track"><span class="hero-video-loading-bar"></span></div>
  </div>
  <div class="container hero-inner">
    <h1><?= \App\View::e($partner ? \App\I18n::t('home.hero_partner_title') . ($partner['name'] ?? '') : \App\I18n::t('home.hero_default_title')) ?></h1>
    <p><?= \App\View::e(\App\I18n::t('home.hero_subtitle')) ?></p>
    <button class="btn-primary hero-search-mobile-toggle" type="button" aria-expanded="false" aria-controls="hero-search-form" data-hero-search-toggle><?= \App\View::e(\App\I18n::t('home.search_button')) ?></button>
    <form class="search-card" method="get" action="/accueil" id="hero-search-form" data-hero-search-form>
      <div class="form-grid search-grid" data-date-range>
        <label><span><?= \App\View::e(\App\I18n::t('home.checkin')) ?></span><input class="input" type="date" name="checkin" value="<?= \App\View::e($search['checkin']) ?>" required></label>
        <label><span><?= \App\View::e(\App\I18n::t('home.checkout')) ?></span><input class="input" type="date" name="checkout" value="<?= \App\View::e($search['checkout']) ?>" required></label>
        <label><span><?= \App\View::e(\App\I18n::t('home.adults')) ?></span><input class="input" type="number" min="1" max="20" name="adults" value="<?= \App\View::e((string) $search['adults']) ?>"></label>
        <label><span><?= \App\View::e(\App\I18n::t('home.children_under3')) ?></span><input class="input" type="number" min="0" max="20" name="children_under3" value="<?= \App\View::e((string) $search['children_under3']) ?>"></label>
        <label><span><?= \App\View::e(\App\I18n::t('home.children_3to12')) ?></span><input class="input" type="number" min="0" max="20" name="children_3to12" value="<?= \App\View::e((string) $search['children_3to12']) ?>"></label>
        <button class="btn-primary search-button" type="submit"><?= \App\View::e(\App\I18n::t('home.search_button')) ?></button>
      </div>
    </form>
  </div>
</section>
<section class="home-results">
  <div class="container section-lg">
  <?php if (!$searched): ?>
    <p class="empty-state"><?= \App\View::e(\App\I18n::t('home.use_search_above')) ?></p>
  <?php else: ?>
    <div class="content-with-sidebar">
      <div>
        <h2 class="section-title"><?= count($properties) ?> <?= \App\View::e(\App\I18n::t(count($properties) === 1 ? 'home.property' : 'home.properties')) ?> <?= \App\View::e(\App\I18n::t('home.available')) ?><?= count($properties) !== 1 ? 's' : '' ?></h2>
        <?php if ($properties === []): ?>
          <?php if ($capacityExceeded): ?>
            <?php if ($babiesExceeded): ?>
              <p class="empty-state">Vous avez indiqué <?= (int) $search['children_under3'] ?> bébé(s) (moins de 3 ans). Le maximum est de 2 bébés par bien — il vous faudra au minimum <?= (int) ceil((int) $search['children_under3'] / 2) ?> bien(s) différent(s). Utilisez la recherche multi-biens ci-dessous pour composer votre séjour.</p>
            <?php else: ?>
              <p class="empty-state">La capacité individuelle de nos biens ne permettent pas d'acceuillir <?= (int) $search['totalGuests'] ?> personnes. Vous pouvez cependant combiner plusieurs logements. Beaucoup de nos logements sont à la même adresse. Nous vous confirmerons par email si tous les biens choisis sont à la même adresse.</p>
            <?php endif; ?>
            <div style="text-align:center;margin-top:1.5rem;">
              <a class="btn-primary" href="<?= \App\View::e($calendarSearchUrl) ?>"><?= \App\View::e(\App\I18n::t('home.search_multi')) ?></a>
            </div>
          <?php else: ?>
            <p class="empty-state"><?= \App\View::e(\App\I18n::t('home.no_results')) ?></p>
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
  </div>
</section>
