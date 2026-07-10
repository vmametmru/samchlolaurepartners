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
    <p><?= (int) $property['bedrooms'] ?> chambre(s) · <?= (int) $property['bathrooms'] ?> salle(s) de bain · <?= (int) $property['max_guests'] ?> personnes max</p>
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
        <p class="muted">Cliquez sur une date disponible du calendrier pour renseigner votre date d'arrivée, puis cliquez sur une seconde date pour la date de départ.</p>
        <?php require BASE_PATH . '/files/views/partials/calendar.php'; ?>
      </div>
    </div>
    <aside class="card card-body sticky-card">
      <form class="stack-md" data-api-form data-booking-form data-property-id="<?= (int) $property['id'] ?>" data-currency="<?= \App\View::e($currency) ?>" data-success-message="Demande envoyée ! Vous recevrez un email de confirmation." method="post" action="/api/reservations/request">
        <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
        <input type="hidden" name="property_name" value="<?= \App\View::e($property['name']) ?>">
        <div class="stack-sm" data-booking-dates>
          <span>Dates du séjour *</span>
          <p class="muted" data-booking-dates-summary>Sélectionnez vos dates dans le calendrier (Tarifs &amp; Disponibilités) : 1er clic = arrivée, 2e clic = départ.</p>
          <input type="hidden" name="checkin_date" data-booking-checkin>
          <input type="hidden" name="checkout_date" data-booking-checkout>
        </div>
        <div class="form-grid cols-2">
          <label><span>Adultes</span><input class="input" type="number" name="adults" min="1" max="20" value="2"></label>
          <label><span>Enfants (&lt; 5 ans)</span><input class="input" type="number" name="children_under5" min="0" max="20" value="0"></label>
        </div>
        <div class="form-grid cols-2">
          <label><span>Enfants (5 à 12 ans)</span><input class="input" type="number" name="children_5to12" min="0" max="20" value="0"></label>
          <input type="hidden" name="children" value="0">
        </div>
        <label><span>Nom et prénom complet *</span><input class="input" type="text" name="client_name" required></label>
        <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
        <?php require BASE_PATH . '/files/views/partials/phone-input.php'; ?>
        <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        <div class="quote-box" data-quote-box hidden>
          <div class="quote-loading" data-quote-loading hidden><span class="spinner" aria-hidden="true"></span> Calcul du tarif…</div>
          <div data-quote-result hidden>
            <div class="quote-line"><span>Chambre (<span data-quote-nights></span> nuit(s))</span><span data-quote-room></span></div>
            <div class="quote-line"><span>Ménage</span><span data-quote-cleaning></span></div>
            <div class="quote-line" data-quote-tax-line hidden><span>Taxe de séjour</span><span data-quote-tax></span></div>
            <div class="quote-line quote-total"><span>Total</span><span data-quote-total></span></div>
          </div>
        </div>
        <button class="btn-primary" type="submit">Envoyer ma demande</button>
        <p class="form-feedback" data-form-feedback></p>
      </form>
    </aside>
  </div>
</section>
