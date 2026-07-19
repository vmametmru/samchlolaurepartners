<?php declare(strict_types=1);
$mainImage = $property['images'][0]['url'] ?? 'https://via.placeholder.com/800x450?text=No+Photo';
$minRate = $rates ? min(array_column($rates, 'price_per_night')) : null;
$currency = $rates[0]['currency'] ?? 'EUR';
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
    <div>
      <h1><?= \App\View::e($property['name']) ?></h1>
      <p><?= (int) $property['bedrooms'] ?> chambre(s) · <?= (int) $property['max_guests'] ?> personnes max</p>
    </div>
    <button type="button" class="btn-primary" data-reserve-btn data-reserve-tab="rates-availability">Réserver</button>
  </div>
  <div class="gallery-main">
    <img src="<?= \App\View::e($mainImage) ?>" alt="<?= \App\View::e($property['name']) ?>" data-gallery-main>
    <div class="gallery-share">
      <span class="gallery-share-toast" data-share-toast>Lien copié</span>
      <button type="button" class="gallery-share-btn" data-share-btn aria-label="Partager" title="Partager">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="18" cy="5" r="3"></circle>
          <circle cx="6" cy="12" r="3"></circle>
          <circle cx="18" cy="19" r="3"></circle>
          <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
          <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
        </svg>
      </button>
    </div>
  </div>

  <?php if (!empty($property['images'])): ?>
    <div class="gallery-carousel" data-gallery-carousel>
      <div class="gallery-carousel-track" data-gallery-track>
        <?php foreach ($property['images'] as $index => $image): ?>
          <button type="button" class="gallery-thumb<?= $index === 0 ? ' active' : '' ?>" data-gallery-thumb data-src="<?= \App\View::e($image['url']) ?>"><img src="<?= \App\View::e($image['url']) ?>" alt="Photo <?= $index + 1 ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <nav class="detail-tabs" data-tabs>
    <button type="button" class="tab-btn active" data-tab-btn="description">Description</button>
    <button type="button" class="tab-btn" data-tab-btn="amenities">Équipements</button>
    <button type="button" class="tab-btn" data-tab-btn="location">Emplacement</button>
    <button type="button" class="tab-btn" data-tab-btn="rates-availability">Tarifs &amp; Disponibilités</button>
  </nav>

  <div>
    <div class="stack-lg" data-tab-panels>
      <div data-tab-panel="description">
        <h2 class="section-title">Description</h2>
        <div class="prose"><?= \App\View::safeHtml($property['description']) ?></div>
        <?php if ($checkinLabel !== null || $checkoutLabel !== null): ?>
          <div class="form-grid cols-2">
            <?php if ($checkinLabel !== null): ?><div><strong>Arrivée</strong><br><?= \App\View::e($checkinLabel) ?></div><?php endif; ?>
            <?php if ($checkoutLabel !== null): ?><div><strong>Départ</strong><br><?= \App\View::e($checkoutLabel) ?></div><?php endif; ?>
          </div>
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
        <div class="rates-tab-header">
          <h2 class="section-title">Tarifs &amp; Disponibilités</h2>
          <button type="button" class="rates-clear-dates-btn" data-clear-dates-btn hidden>Effacer les dates sélectionnées</button>
        </div>
        <?php if (!empty($ratesRestricted)): ?>
          <p class="muted">Merci de contacter votre agence pour ce bien.</p>
        <?php else: ?>
          <?php if ($minRate === null): ?>
            <p class="muted">Tarifs non disponibles pour le moment.</p>
          <?php else: ?>
            <p class="muted calendar-price-note">Prix de la nuité en Euros. Le prix inclus les frais de nettoyage 2 fois par semaine.</p>
          <?php endif; ?>
          <p class="muted">Cliquez sur une date disponible du calendrier pour renseigner votre date d'arrivée, puis cliquez sur une seconde date pour la date de départ.</p>
          <?php require BASE_PATH . '/files/views/partials/calendar.php'; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="booking-modal-overlay" data-booking-modal-overlay style="display:none">
  <div class="booking-modal-panel" data-booking-modal-panel>
    <button type="button" class="booking-modal-hide-btn" data-booking-modal-hide>Masquer</button>
    <form class="booking-modal-form" data-api-form data-booking-form data-property-id="<?= (int) $property['id'] ?>" data-currency="<?= \App\View::e($currency) ?>" data-max-guests="<?= (int) $property['max_guests'] ?>" data-success-message="Demande envoyée ! Vous recevrez un email de confirmation." data-feedback-popup-id="booking-status-popup-<?= (int) $property['id'] ?>" method="post" action="/api/reservations/request">
      <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
      <input type="hidden" name="property_name" value="<?= \App\View::e($property['name']) ?>">

      <div class="booking-modal-top-row">
        <div class="booking-section" data-booking-dates>
          <span class="booking-section-title">Dates du séjour *</span>
          <div class="booking-block-body">
            <div class="booking-dates-summary" data-booking-dates-summary>
              <p class="muted">Sélectionnez vos dates dans le calendrier (Tarifs &amp; Disponibilités) : 1er clic = arrivée, 2e clic = départ.</p>
            </div>
            <input type="hidden" name="checkin_date" data-booking-checkin>
            <input type="hidden" name="checkout_date" data-booking-checkout>
          </div>
        </div>

        <div class="booking-section" data-booking-block="guests">
          <span class="booking-section-title">Nombre de Voyageur(s)</span>
          <div class="booking-block-body" data-block-body>
            <?php if ((int) $property['max_guests'] > 0): ?>
              <p class="muted">Capacité maximum : <?= (int) $property['max_guests'] ?> personne(s).</p>
            <?php endif; ?>
            <div class="guest-count-list">
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label">Adulte(s)</span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="Diminuer le nombre d'adultes">−</button>
                  <input class="input guest-stepper-input" type="number" name="adults" min="1" max="20" value="2" aria-label="Adultes" title="Adultes">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="Augmenter le nombre d'adultes">+</button>
                </div>
              </div>
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label">Enfant(s) -3 ans</span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="Diminuer le nombre d'enfants de moins de 3 ans">−</button>
                  <input class="input guest-stepper-input" type="number" name="children_under3" min="0" max="2" value="0" aria-label="Enfants (moins de 3 ans)" title="Enfants (moins de 3 ans)">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="Augmenter le nombre d'enfants de moins de 3 ans">+</button>
                </div>
              </div>
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label">Enfant(s) 3-12 ans</span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="Diminuer le nombre d'enfants de 3 à 12 ans">−</button>
                  <input class="input guest-stepper-input" type="number" name="children_3to12" min="0" max="20" value="0" aria-label="Enfants (3 à 12 ans)" title="Enfants (3 à 12 ans)">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="Augmenter le nombre d'enfants de 3 à 12 ans">+</button>
                </div>
              </div>
            </div>
            <input type="hidden" name="children" value="0">
            <p class="muted guest-capacity-note" data-guest-capacity-note hidden></p>
          </div>
        </div>
      </div>

      <div class="booking-section" data-booking-block="traveler">
        <span class="booking-section-title">Détails des Voyageurs</span>
        <div class="booking-block-body stack-md" data-block-body>
          <label><span>Nom et prénom complet *</span><input class="input" type="text" name="client_name" required></label>
          <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
          <?php require BASE_PATH . '/files/views/partials/phone-input.php'; ?>
          <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
          <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        </div>
      </div>

      <div class="booking-block" data-booking-block="summary" hidden>
        <div class="quote-box" data-quote-box hidden>
          <div data-quote-result hidden>
          <div class="quote-line"><span>Tarif (<span data-quote-nights></span> nuit(s))</span><span data-quote-room></span></div>
          <div class="quote-line" data-quote-extra-line hidden><span>Personne(s) supplémentaire(s)</span><span data-quote-extra></span></div>
          <div class="quote-line"><span>Nettoyage</span><span data-quote-cleaning></span></div>
          <p class="quote-recap muted" data-quote-recap></p>
          <div class="quote-line quote-total"><span>Total</span><span data-quote-total></span></div>
          <p class="quote-tax-note muted" data-quote-tax-line hidden>Taxe touristique de <span data-quote-tax-amount></span> Euros à régler à l'arrivée (Non comprise dans le total)</p>
        </div>
      </div>
        <input type="hidden" name="quote_currency" value="">
        <input type="hidden" name="quote_nights" value="">
        <input type="hidden" name="quote_room_total" value="">
        <input type="hidden" name="quote_extra_person_total" value="">
        <input type="hidden" name="quote_cleaning_total" value="">
        <input type="hidden" name="quote_total_without_tax" value="">
        <input type="hidden" name="quote_tourist_tax_total" value="">
        <button class="btn-primary" type="submit">Envoyer ma demande</button>
        <p class="form-feedback" data-form-feedback></p>
      </div>
    </form>
  </div>
</div>
<div class="booking-status-popup" id="booking-status-popup-<?= (int) $property['id'] ?>" data-form-status-popup hidden aria-live="polite" aria-atomic="true">
  <div class="booking-status-popup-box" data-form-status-popup-box>
    <p class="booking-status-popup-message" data-form-status-popup-message></p>
  </div>
</div>
</section>
