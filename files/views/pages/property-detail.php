<?php declare(strict_types=1);
$mainImage = $property['images'][0]['url'] ?? 'https://via.placeholder.com/800x450?text=No+Photo';
$minRate = $rates ? min(array_column($rates, 'price_per_night')) : null;
$currency = $rates[0]['currency'] ?? 'EUR';
$photoRooms = $property['photo_rooms'] ?? [];
$amenitiesByCategory = $property['amenities_by_category'] ?? [];
$extraGuestFee = null;
foreach (($property['fees'] ?? []) as $fee) {
    if ($fee['charge_type'] === 'PerPerson' && $fee['amount'] !== null) {
        $extraGuestFee = $fee;
        break;
    }
}
$formatHour = static function (?int $hour): ?string {
    if ($hour === null || $hour < 0) {
        return null;
    }
    return sprintf('%02d:00', $hour);
};
$checkinLabel = $formatHour($property['checkin_hour'] ?? null);
$checkoutLabel = $formatHour($property['checkout_hour'] ?? null);
?>
<section class="container section-lg" data-gallery>
  <div class="property-detail-header">
    <h1><?= \App\View::e($property['name']) ?></h1>
    <p><?= (int) $property['bedrooms'] ?> chambre(s) · <?= (int) $property['bathrooms'] ?> salle(s) de bain · <?= (int) $property['max_guests'] ?> personnes max<?php if ($minRate !== null): ?> · À partir de <?= number_format((float) $minRate, 2, ',', ' ') . ' ' . \App\View::e($currency) ?>/nuit<?php endif; ?></p>
  </div>
  <div class="gallery-main"><img src="<?= \App\View::e($mainImage) ?>" alt="<?= \App\View::e($property['name']) ?>" data-gallery-main></div>

  <nav class="detail-tabs" data-tabs>
    <button type="button" class="tab-btn active" data-tab-btn="description">Description</button>
    <button type="button" class="tab-btn" data-tab-btn="photos">Photos</button>
    <button type="button" class="tab-btn" data-tab-btn="amenities">Équipements</button>
    <button type="button" class="tab-btn" data-tab-btn="location">Emplacement</button>
    <button type="button" class="tab-btn" data-tab-btn="rates-availability">Tarifs &amp; Disponibilités</button>
  </nav>

  <div class="detail-grid">
    <div class="stack-lg" data-tab-panels>
      <div data-tab-panel="description">
        <h2 class="section-title">Description</h2>
        <p class="prose"><?= nl2br(\App\View::e($property['description'])) ?></p>
        <?php if ($checkinLabel !== null || $checkoutLabel !== null): ?>
          <div class="form-grid cols-2">
            <?php if ($checkinLabel !== null): ?><div><strong>Arrivée</strong><br><?= \App\View::e($checkinLabel) ?></div><?php endif; ?>
            <?php if ($checkoutLabel !== null): ?><div><strong>Départ</strong><br><?= \App\View::e($checkoutLabel) ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div data-tab-panel="photos" hidden>
        <h2 class="section-title">Photos</h2>
        <?php if (!empty($photoRooms)): ?>
          <?php foreach ($photoRooms as $room): ?>
            <div class="photo-room">
              <?php if ($room['name'] !== ''): ?><h3 class="section-title"><?= \App\View::e($room['name']) ?></h3><?php endif; ?>
              <div class="gallery-thumbs">
                <?php foreach ($room['images'] as $image): ?>
                  <button type="button" class="gallery-thumb" data-gallery-thumb data-src="<?= \App\View::e($image['url']) ?>"><img src="<?= \App\View::e($image['url']) ?>" alt=""></button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php elseif (!empty($property['images'])): ?>
          <div class="gallery-thumbs">
            <?php foreach ($property['images'] as $image): ?>
              <button type="button" class="gallery-thumb" data-gallery-thumb data-src="<?= \App\View::e($image['url']) ?>"><img src="<?= \App\View::e($image['url']) ?>" alt=""></button>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted">Aucune photo disponible pour le moment.</p>
        <?php endif; ?>
      </div>

      <div data-tab-panel="amenities" hidden>
        <h2 class="section-title">Équipements</h2>
        <?php if (!empty($amenitiesByCategory)): ?>
          <?php foreach ($amenitiesByCategory as $category => $names): ?>
            <div class="amenities-category">
              <h3 class="section-title"><?= \App\View::e($category) ?></h3>
              <div class="amenities-grid">
                <?php foreach ($names as $name): ?><div>✓ <?= \App\View::e($name) ?></div><?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php elseif (!empty($property['amenities'])): ?>
          <div class="amenities-grid">
            <?php foreach ($property['amenities'] as $amenity): ?><div>✓ <?= \App\View::e($amenity['name']) ?></div><?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted">Aucun équipement listé.</p>
        <?php endif; ?>
      </div>

      <div data-tab-panel="location" hidden>
        <h2 class="section-title">Emplacement</h2>
        <?php if ($property['latitude'] !== null && $property['longitude'] !== null): ?>
          <p>Latitude <?= \App\View::e((string) $property['latitude']) ?> · Longitude <?= \App\View::e((string) $property['longitude']) ?></p>
          <p><a class="text-link" target="_blank" rel="noreferrer" href="https://www.openstreetmap.org/?mlat=<?= \App\View::e((string) $property['latitude']) ?>&mlon=<?= \App\View::e((string) $property['longitude']) ?>#map=14/<?= \App\View::e((string) $property['latitude']) ?>/<?= \App\View::e((string) $property['longitude']) ?>">Voir sur OpenStreetMap</a></p>
        <?php else: ?>
          <p class="muted">Emplacement non disponible.</p>
        <?php endif; ?>
      </div>

      <div data-tab-panel="rates-availability" hidden>
        <h2 class="section-title">Tarifs &amp; Disponibilités</h2>
        <?php if ($extraGuestFee !== null): ?>
          <p class="muted">+ <?= number_format((float) $extraGuestFee['amount'], 2, ',', ' ') . ' ' . \App\View::e($currency) ?> par invité / nuit pour le ménage</p>
        <?php endif; ?>
        <?php if ($minRate === null): ?>
          <p class="muted">Tarifs non disponibles pour le moment.</p>
        <?php endif; ?>
        <?php require BASE_PATH . '/files/views/partials/calendar.php'; ?>
      </div>
    </div>
    <aside class="card card-body sticky-card">
      <?php if ($minRate !== null): ?><p class="price-big"><?= number_format((float) $minRate, 2, ',', ' ') . ' ' . \App\View::e($currency) ?><span>/nuit</span></p><?php endif; ?>
      <p class="muted">Aucun paiement requis à ce stade.</p>
      <form class="stack-md" data-api-form data-success-message="Demande envoyée ! Vous recevrez un email de confirmation." method="post" action="/api/reservations/request">
        <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
        <input type="hidden" name="property_name" value="<?= \App\View::e($property['name']) ?>">
        <div data-date-range>
          <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" min="<?= \App\View::e($today) ?>" required></label>
          <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" min="<?= \App\View::e($today) ?>" required></label>
        </div>
        <div class="form-grid cols-2">
          <label><span>Adultes</span><input class="input" type="number" name="adults" min="1" max="20" value="2"></label>
          <label><span>Enfants (&lt;12)</span><input class="input" type="number" name="children" min="0" max="20" value="0"></label>
        </div>
        <label><span>Nom complet *</span><input class="input" type="text" name="client_name" required></label>
        <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
        <label><span>Téléphone</span><input class="input" type="tel" name="client_phone"></label>
        <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        <button class="btn-primary" type="submit">Envoyer ma demande</button>
        <p class="form-feedback" data-form-feedback></p>
      </form>
    </aside>
  </div>
</section>
