<?php declare(strict_types=1);
$mainImage = $property['images'][0]['url'] ?? 'https://via.placeholder.com/800x450?text=No+Photo';
$minRate = $rates ? min(array_column($rates, 'price_per_night')) : null;
$currency = $rates[0]['currency'] ?? 'EUR';
$amenitiesByCategory = \App\View::localizedAmenities($property);
$propertyName = \App\View::localized($property, 'name');
$propertyDescription = \App\View::localized($property, 'description');
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
$priceMinPeople = $priceMinPeople ?? null;
$priceExtraPersonFee = $priceExtraPersonFee ?? null;
$globalTouristTax = $globalTouristTax ?? 0.0;
$canOverrideBookingPolicy = $canOverrideBookingPolicy ?? false;
$bookingPolicies = $bookingPolicies ?? [];
$policyText = $policyText ?? \App\controllers\PageController::bookingPolicyText(\App\I18n::current());
?>
<section class="container section-lg" data-gallery>
  <div class="property-detail-header">
    <div>
      <h1><?= \App\View::e($propertyName) ?></h1>
      <p><?= \App\View::e(sprintf(\App\I18n::t('property.rooms_max_guests'), (int) $property['bedrooms'], (int) $property['max_guests'])) ?></p>
    </div>
    <button type="button" class="btn-primary" data-reserve-btn data-reserve-tab="rates-availability"><?= \App\View::e(\App\I18n::t('property.check_availability')) ?></button>
  </div>
  <div class="gallery-main">
    <img src="<?= \App\View::e($mainImage) ?>" alt="<?= \App\View::e($propertyName) ?>" data-gallery-main loading="eager" decoding="async" fetchpriority="high">
    <div class="gallery-share">
      <span class="gallery-share-toast" data-share-toast><?= \App\View::e(\App\I18n::t('property.link_copied')) ?></span>
      <button type="button" class="gallery-share-btn" data-share-btn data-partner-code="<?= \App\View::e((string) ($partnerCode ?? '')) ?>" aria-label="<?= \App\View::e(\App\I18n::t('property.share')) ?>" title="<?= \App\View::e(\App\I18n::t('property.share')) ?>">
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
          <button type="button" class="gallery-thumb<?= $index === 0 ? ' active' : '' ?>" data-gallery-thumb data-src="<?= \App\View::e($image['url']) ?>"><img src="<?= \App\View::e($image['url']) ?>" alt="Photo <?= $index + 1 ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" decoding="async"></button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <nav class="detail-tabs" data-tabs>
    <button type="button" class="tab-btn active" data-tab-btn="description"><?= \App\View::e(\App\I18n::t('property.tab_description')) ?></button>
    <button type="button" class="tab-btn" data-tab-btn="amenities"><?= \App\View::e(\App\I18n::t('property.tab_amenities')) ?></button>
    <button type="button" class="tab-btn" data-tab-btn="location"><?= \App\View::e(\App\I18n::t('property.tab_location')) ?></button>
    <button type="button" class="tab-btn" data-tab-btn="rates-availability"><?= \App\View::e(\App\I18n::t('property.tab_rates_availability')) ?></button>
  </nav>

  <div>
    <div class="stack-lg" data-tab-panels>
      <div data-tab-panel="description">
        <h2 class="section-title"><?= \App\View::e(\App\I18n::t('property.tab_description')) ?></h2>
        <div class="prose"><?= \App\View::safeHtml($propertyDescription) ?></div>
        <?php if ($checkinLabel !== null || $checkoutLabel !== null): ?>
          <div class="form-grid cols-2">
            <?php if ($checkinLabel !== null): ?><div><strong><?= \App\View::e(\App\I18n::t('property.checkin')) ?></strong><br><?= \App\View::e($checkinLabel) ?></div><?php endif; ?>
            <?php if ($checkoutLabel !== null): ?><div><strong><?= \App\View::e(\App\I18n::t('property.checkout')) ?></strong><br><?= \App\View::e($checkoutLabel) ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div data-tab-panel="amenities" hidden>
        <h2 class="section-title"><?= \App\View::e(\App\I18n::t('property.tab_amenities')) ?></h2>
        <?php if (!empty($amenitiesByCategory)): ?>
          <div class="amenities-categories">
            <?php foreach ($amenitiesByCategory as $category => $names): ?>
              <div class="amenities-category">
                <h3 class="amenities-category-title"><span class="amenities-category-icon"><?= \App\View::amenityCategoryIcon((string) $category) ?></span><?= \App\View::e($category) ?></h3>
                <div class="amenities-grid">
                  <?php foreach ($names as $name): ?><div class="amenities-item">✓ <?= \App\View::e($name) ?></div><?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif (!empty($property['amenities'])): ?>
          <div class="amenities-grid">
            <?php foreach ($property['amenities'] as $amenity): ?><div class="amenities-item">✓ <?= \App\View::e($amenity['name']) ?></div><?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted"><?= \App\View::e(\App\I18n::t('property.no_amenities')) ?></p>
        <?php endif; ?>
      </div>

      <div data-tab-panel="location" hidden>
        <h2 class="section-title"><?= \App\View::e(\App\I18n::t('property.tab_location')) ?></h2>
        <?php if ($property['latitude'] !== null && $property['longitude'] !== null): ?>
          <p>Latitude <?= \App\View::e((string) $property['latitude']) ?> · Longitude <?= \App\View::e((string) $property['longitude']) ?></p>
          <p><a class="text-link" target="_blank" rel="noreferrer" href="https://www.openstreetmap.org/?mlat=<?= \App\View::e((string) $property['latitude']) ?>&mlon=<?= \App\View::e((string) $property['longitude']) ?>#map=14/<?= \App\View::e((string) $property['latitude']) ?>/<?= \App\View::e((string) $property['longitude']) ?>"><?= \App\View::e(\App\I18n::t('property.view_on_osm')) ?></a></p>
        <?php else: ?>
          <p class="muted"><?= \App\View::e(\App\I18n::t('property.no_location')) ?></p>
        <?php endif; ?>
      </div>

      <div data-tab-panel="rates-availability" hidden>
        <div class="rates-tab-header">
          <h2 class="section-title"><?= \App\View::e(\App\I18n::t('property.tab_rates_availability')) ?></h2>
          <button type="button" class="rates-clear-dates-btn" data-clear-dates-btn hidden><?= \App\View::e(\App\I18n::t('property.clear_dates')) ?></button>
        </div>
        <?php if (!empty($ratesRestricted)): ?>
          <p class="muted"><?= \App\View::e(\App\I18n::t('property.contact_agency')) ?></p>
        <?php else: ?>
          <?php if ($minRate === null): ?>
            <p class="muted"><?= \App\View::e(\App\I18n::t('property.rates_unavailable')) ?></p>
          <?php else: ?>
            <p class="muted calendar-price-note">
              <?= \App\View::e(sprintf(
                \App\I18n::t('calendar.price_note'),
                ($cleaningFeePerPerson ?? 0) > 0 ? sprintf(\App\I18n::t('calendar.price_note_cleaning_fee'), number_format((float) $cleaningFeePerPerson, 2, ',', ' ')) : ''
              )) ?>
              <?php if ($priceMinPeople !== null): ?>
                <?= \App\View::e(sprintf(\App\I18n::t('property.price_min_people'), (int) $priceMinPeople)) ?>
                <?php if ($priceExtraPersonFee !== null && $priceExtraPersonFee > 0): ?>
                  <?= \App\View::e(sprintf(\App\I18n::t('property.price_extra_person_fee'), number_format((float) $priceExtraPersonFee, 2, ',', ' '))) ?>
                <?php endif; ?>
                <?= \App\View::e(\App\I18n::t('property.price_babies_and_tax')) ?>
                <?php if ($globalTouristTax > 0): ?>
                  <?= \App\View::e(sprintf(\App\I18n::t('property.tourist_tax_note'), number_format($globalTouristTax, 2, ',', ' '))) ?>
                <?php endif; ?>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <p class="muted"><?= \App\View::e(\App\I18n::t('property.select_dates_hint')) ?></p>
          <?php require BASE_PATH . '/files/views/partials/calendar.php'; ?>
          <div class="booking-policy-block">
            <h3 class="section-title"><?= \App\View::e(\App\I18n::t('property.booking_policy_title')) ?></h3>
            <div class="prose"><?= \App\controllers\PageController::formatBookingPolicyHtml($policyText ?? \App\controllers\PageController::bookingPolicyText(\App\I18n::current())) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="booking-modal-overlay" data-booking-modal-overlay style="display:none">
  <div class="booking-modal-panel" data-booking-modal-panel>
    <button type="button" class="booking-modal-hide-btn" data-booking-modal-hide><?= \App\View::e(\App\I18n::t('property.hide')) ?></button>
    <form class="booking-modal-form" data-api-form data-booking-form data-property-id="<?= (int) $property['id'] ?>" data-currency="<?= \App\View::e($currency) ?>" data-max-guests="<?= (int) $property['max_guests'] ?>" data-success-message="<?= \App\View::e(\App\I18n::t('property.request_sent')) ?>" data-feedback-popup-id="booking-status-popup-<?= (int) $property['id'] ?>" data-i18n-checkin="<?= \App\View::e(\App\I18n::t('property.checkin')) ?>" data-i18n-checkout="<?= \App\View::e(\App\I18n::t('property.checkout')) ?>" data-i18n-nights="<?= \App\View::e(\App\I18n::t('property.nights_count')) ?>" data-i18n-click-other-date="<?= \App\View::e(\App\I18n::t('property.click_other_date_for_checkout')) ?>" data-i18n-min-stay-hint="<?= \App\View::e(\App\I18n::t('property.min_stay_hint')) ?>" data-i18n-select-dates="<?= \App\View::e(\App\I18n::t('property.select_dates_in_calendar')) ?>" data-i18n-name-required="<?= \App\View::e(\App\I18n::t('property.name_required')) ?>" data-i18n-email-required="<?= \App\View::e(\App\I18n::t('property.email_required')) ?>" data-i18n-nationality-required="<?= \App\View::e(\App\I18n::t('property.nationality_required')) ?>" method="post" action="/api/reservations/request">
      <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
      <input type="hidden" name="property_name" value="<?= \App\View::e($propertyName) ?>">

      <div class="booking-modal-top-row">
        <div class="booking-section" data-booking-dates>
          <span class="booking-section-title"><?= \App\View::e(\App\I18n::t('property.stay_dates')) ?></span>
          <div class="booking-block-body">
            <div class="booking-dates-summary" data-booking-dates-summary>
              <p class="muted"><?= \App\I18n::t('property.select_dates_in_calendar') ?></p>
            </div>
            <input type="hidden" name="checkin_date" data-booking-checkin>
            <input type="hidden" name="checkout_date" data-booking-checkout>
          </div>
        </div>

        <div class="booking-section" data-booking-block="guests">
          <span class="booking-section-title"><?= \App\View::e(\App\I18n::t('property.number_of_travelers')) ?></span>
          <div class="booking-block-body" data-block-body>
            <?php if ((int) $property['max_guests'] > 0): ?>
              <p class="muted"><?= \App\View::e(sprintf(\App\I18n::t('property.max_capacity'), (int) $property['max_guests'])) ?></p>
            <?php endif; ?>
            <div class="guest-count-list">
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label"><?= \App\View::e(\App\I18n::t('property.adults')) ?></span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="<?= \App\View::e(\App\I18n::t('property.decrease_adults')) ?>">−</button>
                  <input class="input guest-stepper-input" type="number" name="adults" min="1" max="20" value="2" aria-label="<?= \App\View::e(\App\I18n::t('calendar.adults')) ?>" title="<?= \App\View::e(\App\I18n::t('calendar.adults')) ?>">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="<?= \App\View::e(\App\I18n::t('property.increase_adults')) ?>">+</button>
                </div>
              </div>
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label"><?= \App\View::e(\App\I18n::t('property.children_under3')) ?></span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="<?= \App\View::e(\App\I18n::t('property.decrease_children_under3')) ?>">−</button>
                  <input class="input guest-stepper-input" type="number" name="children_under3" min="0" max="2" value="0" aria-label="<?= \App\View::e(\App\I18n::t('property.children_under3_label')) ?>" title="<?= \App\View::e(\App\I18n::t('property.children_under3_label')) ?>">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="<?= \App\View::e(\App\I18n::t('property.increase_children_under3')) ?>">+</button>
                </div>
              </div>
              <div class="guest-count-row" data-guest-stepper>
                <span class="guest-count-label"><?= \App\View::e(\App\I18n::t('property.children_3to12')) ?></span>
                <div class="guest-count-controls">
                  <button type="button" class="stepper-btn" data-step="-1" aria-label="<?= \App\View::e(\App\I18n::t('property.decrease_children_3to12')) ?>">−</button>
                  <input class="input guest-stepper-input" type="number" name="children_3to12" min="0" max="20" value="0" aria-label="<?= \App\View::e(\App\I18n::t('property.children_3to12_label')) ?>" title="<?= \App\View::e(\App\I18n::t('property.children_3to12_label')) ?>">
                  <button type="button" class="stepper-btn" data-step="1" aria-label="<?= \App\View::e(\App\I18n::t('property.increase_children_3to12')) ?>">+</button>
                </div>
              </div>
            </div>
            <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
            <input type="hidden" name="children" value="0">
            <p class="muted guest-capacity-note" data-guest-capacity-note hidden></p>
          </div>
        </div>
      </div>

      <div class="booking-block" data-booking-block="summary" hidden>
        <div class="quote-box" data-quote-box hidden>
          <div data-quote-result hidden>
          <div class="quote-line"><span><?= sprintf(\App\View::e(\App\I18n::t('property.rate_nights')), '<span data-quote-nights></span>') ?></span><span class="quote-room-wrap"><span data-quote-room></span><?php if (!empty($canForcePrice)): ?><button type="button" class="quote-edit-price-btn" data-force-price-edit-btn aria-label="<?= \App\View::e(\App\I18n::t('property.force_price_edit')) ?>" title="<?= \App\View::e(\App\I18n::t('property.force_price_edit')) ?>" aria-expanded="false">✎</button>
            <div class="force-price-dropdown" data-force-price-dropdown hidden>
              <div class="force-price-dropdown-header"><?= \App\View::e(\App\I18n::t('property.force_nightly_price')) ?></div>
              <div class="force-price-breakdown" data-force-price-breakdown>
                <div class="quote-line"><span><?= sprintf(\App\View::e(\App\I18n::t('property.force_price_current_label')), '<span data-fp-nights></span>') ?></span><span data-fp-current-total></span></div>
                <div class="quote-line"><span><?= \App\View::e(\App\I18n::t('property.force_price_lodgify_label')) ?></span><span data-fp-lodgify-total></span></div>
                <div class="quote-line" data-fp-vat-row><span data-fp-vat-label><?= sprintf(\App\View::e(\App\I18n::t('property.force_price_vat_label')), '<span data-fp-vat-rate></span>') ?></span><span data-fp-vat-total></span></div>
                <div class="quote-line"><span><?= \App\View::e(\App\I18n::t('property.force_price_commission_label')) ?></span><span data-fp-commission-total></span></div>
              </div>
              <label>
                <span><?= \App\View::e(\App\I18n::t('property.force_nightly_price')) ?></span>
                <input class="input" type="number" min="0" step="0.01" data-forced-total-price-input>
              </label>
              <p class="muted" data-forced-total-price-note hidden><?= \App\View::e(\App\I18n::t('property.force_nightly_price_adjusted')) ?></p>
              <div class="force-price-dropdown-actions">
                <button type="button" class="btn-secondary" data-force-price-dropdown-cancel><?= \App\View::e(\App\I18n::t('property.force_price_cancel')) ?></button>
                <button type="button" class="btn-primary" data-force-price-dropdown-save><?= \App\View::e(\App\I18n::t('property.force_price_save')) ?></button>
              </div>
            </div>
            <?php endif; ?></span></div>
          <div class="quote-line" data-quote-extra-line hidden><span><?= \App\View::e(\App\I18n::t('property.extra_guests')) ?></span><span class="quote-room-wrap"><span data-quote-extra></span><?php if (!empty($canForcePrice)): ?><button type="button" class="quote-edit-price-btn" data-force-extra-price-edit-btn aria-label="<?= \App\View::e(\App\I18n::t('property.force_price_edit')) ?>" title="<?= \App\View::e(\App\I18n::t('property.force_price_edit')) ?>" aria-expanded="false">✎</button>
            <div class="force-price-dropdown" data-force-extra-price-dropdown hidden>
              <div class="force-price-dropdown-header"><?= \App\View::e(\App\I18n::t('property.force_extra_person_price')) ?></div>
              <div class="force-price-breakdown" data-force-extra-price-breakdown>
                <div class="quote-line"><span><?= sprintf(\App\View::e(\App\I18n::t('property.force_extra_person_current_label')), '<span data-fep-count></span>') ?></span><span data-fep-current-total></span></div>
                <div class="quote-line"><span><?= \App\View::e(\App\I18n::t('property.force_price_lodgify_label')) ?></span><span data-fep-lodgify-total></span></div>
                <div class="quote-line" data-fep-vat-row><span data-fep-vat-label><?= sprintf(\App\View::e(\App\I18n::t('property.force_price_vat_label')), '<span data-fep-vat-rate></span>') ?></span><span data-fep-vat-total></span></div>
                <div class="quote-line"><span><?= \App\View::e(\App\I18n::t('property.force_price_commission_label')) ?></span><span data-fep-commission-total></span></div>
              </div>
              <label>
                <span><?= \App\View::e(\App\I18n::t('property.force_extra_person_price')) ?></span>
                <input class="input" type="number" min="0" step="0.01" data-forced-extra-total-price-input>
              </label>
              <p class="muted" data-forced-extra-total-price-note hidden><?= \App\View::e(\App\I18n::t('property.force_extra_person_price_adjusted')) ?></p>
              <div class="force-price-dropdown-actions">
                <button type="button" class="btn-secondary" data-force-price-dropdown-cancel><?= \App\View::e(\App\I18n::t('property.force_price_cancel')) ?></button>
                <button type="button" class="btn-primary" data-force-price-dropdown-save><?= \App\View::e(\App\I18n::t('property.force_price_save')) ?></button>
              </div>
            </div>
            <?php endif; ?></span></div>
          <div class="quote-line"><span><?= \App\View::e(\App\I18n::t('property.cleaning')) ?></span><span data-quote-cleaning></span></div>
          <div class="quote-line" data-quote-tax-line hidden><span><?= \App\View::e(\App\I18n::t('property.tourist_tax')) ?></span><span data-quote-tax-amount></span></div>
          <p class="quote-recap muted" data-quote-recap></p>
          <div class="quote-line quote-total"><span><?= \App\View::e(\App\I18n::t('property.total')) ?></span><span data-quote-total></span></div>
        </div>
      </div>
      </div>

      <?php if (!empty($canForcePrice)): ?>
      <input type="hidden" name="forced_total_price" data-forced-total-price>
      <input type="hidden" name="forced_extra_person_total" data-forced-extra-total-price>
      <?php endif; ?>

      <div class="booking-section" data-booking-block="traveler">
        <span class="booking-section-title"><?= \App\View::e(\App\I18n::t('property.traveler_details')) ?></span>
        <div class="booking-block-body stack-md" data-block-body>
          <label><span><?= \App\View::e(\App\I18n::t('property.full_name')) ?></span><input class="input" type="text" name="client_name" required></label>
          <label data-client-email-field><span><?= \App\View::e(\App\I18n::t('property.email')) ?></span><input class="input" type="email" name="client_email" required></label>
          <?php if (!empty($canForcePrice)): ?>
            <!-- Partner/admin only (see ReservationsController::canForcePrice()
                 for the matching server-side re-check): lets the agency
                 create a request with just a phone number, when the client
                 has no email address. An anonymous client browsing the
                 public site never sees this and can never skip the email. -->
            <label class="inline-check"><input type="checkbox" name="no_client_email" value="1" data-no-client-email-toggle> <?= \App\View::e(\App\I18n::t('property.no_client_email')) ?></label>
          <?php endif; ?>
          <?php require BASE_PATH . '/files/views/partials/phone-input.php'; ?>
          <?php if (!empty($canForcePrice)): ?>
            <!-- Same partner/admin-only escape hatch as "Pas de Email" above,
                 for a client who has no phone number: drops the phone field's
                 mandatory flag (and hides it), re-checked server-side in
                 ReservationsController. At least one of email/phone must
                 always remain. -->
            <label class="inline-check"><input type="checkbox" name="no_client_phone" value="1" data-no-client-phone-toggle> <?= \App\View::e(\App\I18n::t('property.no_client_phone')) ?></label>
          <?php endif; ?>
          <label><span><?= \App\View::e(\App\I18n::t('property.message_optional')) ?></span><textarea class="input" rows="3" name="message"></textarea></label>
          <?php if ($canOverrideBookingPolicy && $bookingPolicies !== []): ?>
          <label>
            <span><?= \App\View::e(\App\I18n::t('property.booking_policy_override')) ?></span>
            <select class="input" name="booking_policy_id">
              <?php foreach ($bookingPolicies as $policy): ?>
                <option value="<?= (int) $policy['id'] ?>"<?= !empty($policy['is_default']) ? ' selected' : '' ?>><?= \App\View::e((string) $policy['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="muted"><?= \App\View::e(\App\I18n::t('property.booking_policy_override_hint')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="booking-submit-block">
        <input type="hidden" name="quote_currency" value="">
        <input type="hidden" name="quote_nights" value="">
        <input type="hidden" name="quote_room_total" value="">
        <input type="hidden" name="quote_extra_person_total" value="">
        <input type="hidden" name="quote_cleaning_total" value="">
        <input type="hidden" name="quote_total_without_tax" value="">
        <input type="hidden" name="quote_tourist_tax_total" value="">
        <input type="hidden" name="quote_vat_rate" value="">
        <button class="btn-primary" type="submit"><?= \App\View::e(\App\I18n::t('property.send_request')) ?></button>
        <p class="form-feedback" data-form-feedback></p>
      </div>
    </form>
  </div>
</div>
<div class="booking-status-popup" id="booking-status-popup-<?= (int) $property['id'] ?>" data-form-status-popup hidden aria-live="polite" aria-atomic="true">
  <div class="booking-status-popup-box" data-form-status-popup-box>
    <p class="booking-status-popup-message" data-form-status-popup-message></p>
    <p class="booking-status-popup-note" data-form-status-popup-spam-note hidden><?= \App\View::e(\App\I18n::t('property.spam_note')) ?></p>
    <button type="button" class="booking-status-popup-close" data-form-status-popup-close><?= \App\View::e(\App\I18n::t('property.close')) ?></button>
  </div>
</div>
</section>
