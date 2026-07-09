<?php declare(strict_types=1);
$mainImage = $property['images'][0]['url'] ?? 'https://via.placeholder.com/800x450?text=No+Photo';
$minRate = $rates ? min(array_column($rates, 'price_per_night')) : null;
$currency = $rates[0]['currency'] ?? 'EUR';
?>
<section class="container section-lg">
  <div class="property-detail-header">
    <h1><?= \App\View::e($property['name']) ?></h1>
    <p><?= (int) $property['bedrooms'] ?> chambre(s) · <?= (int) $property['bathrooms'] ?> salle(s) de bain · <?= (int) $property['max_guests'] ?> personnes max<?php if ($minRate !== null): ?> · À partir de <?= number_format((float) $minRate, 2, ',', ' ') . ' ' . \App\View::e($currency) ?>/nuit<?php endif; ?></p>
  </div>
  <div class="gallery" data-gallery>
    <div class="gallery-main"><img src="<?= \App\View::e($mainImage) ?>" alt="<?= \App\View::e($property['name']) ?>" data-gallery-main></div>
    <?php if (!empty($property['images'])): ?>
      <div class="gallery-thumbs">
        <?php foreach ($property['images'] as $index => $image): ?>
          <button type="button" class="gallery-thumb<?= $index === 0 ? ' active' : '' ?>" data-gallery-thumb data-src="<?= \App\View::e($image['url']) ?>"><img src="<?= \App\View::e($image['url']) ?>" alt=""></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="detail-grid">
    <div class="stack-lg">
      <div>
        <h2 class="section-title">Description</h2>
        <p class="prose"><?= nl2br(\App\View::e($property['description'])) ?></p>
      </div>
      <?php if (!empty($property['amenities'])): ?>
        <div>
          <h2 class="section-title">Équipements</h2>
          <div class="amenities-grid">
            <?php foreach ($property['amenities'] as $amenity): ?><div>✓ <?= \App\View::e($amenity['name']) ?></div><?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <div>
        <h2 class="section-title">Disponibilités</h2>
        <?php require BASE_PATH . '/src/views/partials/calendar.php'; ?>
      </div>
      <?php if ($property['latitude'] !== null && $property['longitude'] !== null): ?>
        <div class="card card-body">
          <h2 class="section-title">Localisation</h2>
          <p>Latitude <?= \App\View::e((string) $property['latitude']) ?> · Longitude <?= \App\View::e((string) $property['longitude']) ?></p>
          <p><a class="text-link" target="_blank" rel="noreferrer" href="https://www.openstreetmap.org/?mlat=<?= \App\View::e((string) $property['latitude']) ?>&mlon=<?= \App\View::e((string) $property['longitude']) ?>#map=14/<?= \App\View::e((string) $property['latitude']) ?>/<?= \App\View::e((string) $property['longitude']) ?>">Voir sur OpenStreetMap</a></p>
        </div>
      <?php endif; ?>
    </div>
    <aside class="card card-body sticky-card">
      <?php if ($minRate !== null): ?><p class="price-big"><?= number_format((float) $minRate, 2, ',', ' ') . ' ' . \App\View::e($currency) ?><span>/nuit</span></p><?php endif; ?>
      <p class="muted">Aucun paiement requis à ce stade.</p>
      <form class="stack-md" data-api-form data-success-message="Demande envoyée ! Vous recevrez un email de confirmation." method="post" action="/api/reservations/request">
        <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
        <input type="hidden" name="property_name" value="<?= \App\View::e($property['name']) ?>">
        <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" min="<?= \App\View::e($today) ?>" required></label>
        <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" min="<?= \App\View::e($today) ?>" required></label>
        <div class="form-grid cols-2">
          <label><span>Adultes</span><input class="input" type="number" name="adults" min="1" max="20" value="2"></label>
          <label><span>Enfants (&lt;12)</span><input class="input" type="number" name="children" min="0" max="20" value="0"></label>
        </div>
        <label><span>Nom complet *</span><input class="input" type="text" name="client_name" required></label>
        <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
        <label><span>Téléphone</span><input class="input" type="tel" name="client_phone"></label>
        <?php require BASE_PATH . '/src/views/partials/nationalities.php'; ?>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        <button class="btn-primary" type="submit">Envoyer ma demande</button>
        <p class="form-feedback" data-form-feedback></p>
      </form>
    </aside>
  </div>
</section>
