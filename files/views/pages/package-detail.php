<?php declare(strict_types=1);
/**
 * Public page of a single offer: general description, flight options,
 * available accommodations (searched in the local cache only), activities
 * and meal plans, then the reservation request form.
 *
 * An offer is sold as a whole: no absolute individual price (flight,
 * activity, meal, accommodation) is ever printed here — only the
 * all-inclusive total returned by the search endpoint. The one exception is
 * the "variance" shown on each Vol/Transport/Activités/Restauration option
 * (see $formatVariance below): the price difference against that step's
 * default/included option, so the client sees the cost impact of switching
 * without the offer's absolute prices being exposed.
 */
$e = static fn (mixed $value): string => \App\View::e($value);
$t = static fn (array $row, string $field): string => \App\Packages::text($row, $field);
$flights = $package['flights'] ?? [];
// Optional blocks, in the order of the public steps that follow the
// accommodation and the flight.
$extraBlocks = [
    'transports' => ['title' => 'Transport', 'rows' => $package['transports'] ?? [], 'included' => 'Inclus dans l\'offre', 'optional' => 'Ajouter ce transport', 'none' => 'Sans transport'],
    'activities' => ['title' => 'Activités', 'rows' => $package['activities'] ?? [], 'included' => 'Incluse dans l\'offre', 'optional' => 'Ajouter cette activité', 'none' => 'Sans activité'],
    'meals' => ['title' => 'Restauration', 'rows' => $package['meals'] ?? [], 'included' => 'Incluse dans l\'offre', 'optional' => 'Ajouter cette formule', 'none' => 'Sans formule repas'],
];
$today = (new DateTimeImmutable('now', new DateTimeZone('Etc/GMT-4')))->format('Y-m-d');
// Renders the price difference of an option against its step's baseline
// (the default flight, or 0 for an optional transport/activity/meal since
// mandatory ones are already counted in the base price): "Inclus" when it
// matches the baseline, "+123,00 €" / "-123,00 €" otherwise. Hidden
// altogether when this partner's prices are hidden.
$formatVariance = static function (float $variance) use ($pricesHidden): ?string {
    if ($pricesHidden) {
        return null;
    }
    if (abs($variance) < 0.005) {
        return 'Inclus';
    }
    return ($variance > 0 ? '+' : '−') . number_format(abs($variance), 2, ',', ' ') . ' €';
};
?>
<section class="container section-lg" data-package-page data-package-id="<?= (int) $package['id'] ?>" data-prices-hidden="<?= $pricesHidden ? '1' : '0' ?>">
  <?php if (!$bookable): ?>
    <div class="alert alert-warning">Aperçu : cette offre n'est pas visible par vos clients (brouillon, expirée ou complète).</div>
  <?php endif; ?>

  <div class="property-detail-header">
    <div>
      <h1><?= $e($t($package, 'title')) ?></h1>
      <?php if ($expiresLabel !== null && $expiresLabel !== ''): ?>
        <p class="muted">Offre valable jusqu'au <?= $e($expiresLabel) ?></p>
      <?php endif; ?>
      <?php if ($remainingStock !== null): ?>
        <p class="muted">Encore <?= (int) $remainingStock ?> <?= (string) $package['stock_mode'] === 'persons' ? 'place(s)' : 'réservation(s)' ?> sur cette offre.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php $description = $t($package, 'description'); ?>
  <?php if (!empty($package['photo_url']) || $description !== ''): ?>
    <!-- Photo and description side by side: the main photo is capped to half
         its previous height (see .package-intro-photo) so the offer's steps
         stay visible without scrolling. On a narrow screen both blocks fall
         back to one column. -->
    <div class="package-intro mt-16">
      <?php if (!empty($package['photo_url'])): ?>
        <div class="property-card-image package-intro-photo"><img src="<?= $e($package['photo_url']) ?>" alt="<?= $e($t($package, 'title')) ?>"></div>
      <?php endif; ?>
      <?php if ($description !== ''): ?>
        <div class="card card-body package-intro-description"><p><?= nl2br($e($description)) ?></p></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php
  // The offer is filled in step by step, in the order it is sold:
  // Hébergement first (it drives the total shown in the page's total bar),
  // then Vol, Transport, Activités and finally Restauration. Each step
  // unlocks the next one — its "Étape suivante" button stays disabled until
  // the step's choice is made — and the reservation form only appears once
  // the last step is done. Steps with nothing to show are simply not
  // rendered.
  $steps = ['accommodation' => 'Hébergement'];
  if ($flights !== []) {
      $steps['flight'] = 'Vol';
  }
  foreach ($extraBlocks as $blockKey => $block) {
      if ($block['rows'] !== []) {
          $steps[$blockKey] = $block['title'];
      }
  }
  $stepKeys = array_keys($steps);
  $stepCount = count($stepKeys);
  ?>
  <ol class="package-steps mt-16" data-package-steps>
    <?php foreach ($stepKeys as $index => $stepKey): ?>
      <li class="package-step<?= $index === 0 ? ' active' : '' ?>" data-package-step-item="<?= $e($stepKey) ?>">
        <span class="package-step-index"><?= $index + 1 ?></span>
        <span><?= $e($steps[$stepKey]) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>

  <div data-package-step-panels>
    <?php foreach ($stepKeys as $index => $stepKey): ?>
      <div data-package-step-panel="<?= $e($stepKey) ?>"<?= $index === 0 ? '' : ' hidden' ?>>
        <?php if ($stepKey === 'flight'): ?>
          <!-- One block per flight option, 3 per line (see
               .package-options-grid). The flight marked "Option conseillée"
               (is_default) is pre-selected so the block reflects the choice
               already used to compute the running total; the client can
               still switch to another option. Each card also shows its price
               variance against that default option (see $formatVariance),
               mirroring Packages::extrasSelection()'s own fallback to the
               first flight when none is marked default. -->
          <?php
            $flightDefault = null;
            foreach ($flights as $candidate) {
                if ((int) ($candidate['is_default'] ?? 0) === 1) {
                    $flightDefault = $candidate;
                    break;
                }
            }
            if ($flightDefault === null && $flights !== []) {
                $flightDefault = $flights[0];
            }
            $flightDefaultPrice = $flightDefault !== null ? (float) $flightDefault['price'] : 0.0;
          ?>
          <div class="package-options-grid">
            <?php foreach ($flights as $flight): ?>
              <label class="card package-option-card">
                <?php if (!empty($flight['photo_url'])): ?>
                  <div class="property-card-image"><img src="<?= $e($flight['photo_url']) ?>" alt="<?= $e((string) $flight['label']) ?>" loading="lazy"></div>
                <?php endif; ?>
                <div class="card-body">
                  <span class="inline-check">
                    <input type="radio" name="package_flight" value="<?= (int) $flight['id'] ?>" data-package-flight
                      <?= (int) ($flight['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <strong><?= $e((string) $flight['label']) ?></strong>
                  </span>
                  <?php if (!empty($flight['airline']) || !empty($flight['cabin_class'])): ?>
                    <span class="muted">
                      <?= $e((string) ($flight['airline'] ?? '')) ?>
                      <?php if (!empty($flight['cabin_class'])): ?> (<?= $e((string) $flight['cabin_class']) ?>)<?php endif; ?>
                    </span>
                  <?php endif; ?>
                  <?php if ((int) ($flight['is_default'] ?? 0) === 1): ?>
                    <span class="muted">Option conseillée</span>
                  <?php endif; ?>
                  <?php $flightVariance = $formatVariance((float) $flight['price'] - $flightDefaultPrice); ?>
                  <?php if ($flightVariance !== null): ?>
                    <span class="badge package-variance-badge">Variance : <?= $e($flightVariance) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($flight['description'])): ?><small class="muted"><?= nl2br($e((string) $flight['description'])) ?></small><?php endif; ?>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php elseif ($stepKey === 'accommodation'): ?>
          <div class="card card-body">
            <div class="form-grid cols-2">
              <label><span>Arrivée</span><input class="input" type="date" min="<?= $e($today) ?>" data-package-checkin></label>
              <label><span>Départ</span><input class="input" type="date" min="<?= $e($today) ?>" data-package-checkout></label>
              <label><span>Adultes</span><input class="input" type="number" min="1" step="1" value="2" data-package-adults></label>
              <label><span>Enfants (3-12 ans)</span><input class="input" type="number" min="0" step="1" value="0" data-package-children></label>
              <label><span>Bébés (moins de 3 ans)</span><input class="input" type="number" min="0" step="1" value="0" data-package-babies></label>
              <!-- Used to compute the tourist tax (see Packages::guestsForNationality()):
                   a Mauricien party is exempt, everyone else is taxable. -->
              <div class="stack-sm" data-package-nationalities>
                <div class="inline-check">
                  <input type="checkbox" id="packageSameNat" data-package-same-nationality checked>
                  <label for="packageSameNat">Même nationalité pour tous</label>
                </div>
                <div data-package-uniform-nationality-wrap>
                  <label><span>Nationalité (tous)</span>
                    <select class="input" data-package-uniform-nationality>
                      <option value="">Sélectionner...</option>
                      <?php foreach (['Mauricienne', 'Française', 'Britannique', 'Allemande', 'Italienne', 'Espagnole', 'Belge', 'Suisse', 'Américaine', 'Australienne', 'Autre'] as $nationalityOption): ?>
                        <option value="<?= $e($nationalityOption) ?>"><?= $e($nationalityOption) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div class="stack-sm" data-package-nationality-list hidden></div>
                <template data-package-nationality-template>
                  <label>
                    <span></span>
                    <select class="input" data-package-nationality-select>
                      <option value="">Sélectionner...</option>
                      <?php foreach (['Mauricienne', 'Française', 'Britannique', 'Allemande', 'Italienne', 'Espagnole', 'Belge', 'Suisse', 'Américaine', 'Australienne', 'Autre'] as $nationalityOption): ?>
                        <option value="<?= $e($nationalityOption) ?>"><?= $e($nationalityOption) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </template>
              </div>
            </div>
            <div class="button-row mt-16">
              <button class="btn-primary" type="button" data-package-search>Rechercher</button>
              <span class="muted" data-package-search-status></span>
            </div>
            <?php if ($cacheUpdatedLabel !== null && $cacheUpdatedLabel !== ''): ?>
              <p class="muted mt-16"><?= $e($cacheUpdatedLabel) ?></p>
            <?php endif; ?>
          </div>
          <div class="mt-16" data-package-results hidden></div>
          <!-- Party too big for a single accommodation: the properties of the
               offer sharing one same address ("Emplacement" in Biens Lodgify)
               can be combined, so several of them are picked here. -->
          <div class="mt-16" data-package-groups hidden></div>
          <div class="mt-16" data-package-alternatives hidden></div>
        <?php else: $block = $extraBlocks[$stepKey];
          // A step whose options are all mandatory leaves nothing to choose:
          // its button is unlocked straight away. Otherwise the client must
          // either tick at least one option or explicitly decline, so the
          // "Étape suivante" button is never enabled before a choice.
          $optionalCount = 0;
          foreach ($block['rows'] as $extraRow) {
              if ((int) ($extraRow['is_mandatory'] ?? 0) !== 1) {
                  $optionalCount++;
              }
          }
        ?>
          <div class="package-options-grid">
            <?php foreach ($block['rows'] as $extra): $mandatory = (int) ($extra['is_mandatory'] ?? 0) === 1; ?>
              <article class="card package-option-card">
                <?php if (!empty($extra['photo_url'])): ?>
                  <div class="property-card-image"><img src="<?= $e($extra['photo_url']) ?>" alt="<?= $e($t($extra, 'label')) ?>" loading="lazy"></div>
                <?php endif; ?>
                <div class="card-body">
                  <h3><?= $e($t($extra, 'label')) ?></h3>
                  <?php $extraDescription = $t($extra, 'description'); ?>
                  <?php if ($extraDescription !== ''): ?><p><?= nl2br($e($extraDescription)) ?></p><?php endif; ?>
                  <?php
                    // Mandatory extras are always included in the base price
                    // (variance 0, same as the default flight); an optional
                    // one's variance is simply the extra cost of ticking it,
                    // since nothing prevents leaving it unselected (see
                    // $formatVariance at the top of this file).
                    $extraVariance = $formatVariance($mandatory ? 0.0 : (float) $extra['price']);
                  ?>
                  <?php if ($extraVariance !== null): ?>
                    <span class="badge package-variance-badge">Variance : <?= $e($extraVariance) ?></span>
                  <?php endif; ?>
                  <label class="inline-check">
                    <input type="checkbox" value="<?= (int) $extra['id'] ?>" data-package-extra="<?= $e($stepKey) ?>"
                      <?= $mandatory ? 'checked disabled' : '' ?>>
                    <?= $e($mandatory ? $block['included'] : $block['optional']) ?>
                  </label>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($optionalCount > 0): ?>
            <label class="inline-check mt-16">
              <input type="checkbox" data-package-extra-none="<?= $e($stepKey) ?>">
              <?= $e($block['none']) ?>
            </label>
          <?php endif; ?>
        <?php endif; ?>

        <div class="button-row mt-16">
          <?php if ($index > 0): ?>
            <button type="button" class="btn-secondary" data-package-step-prev>Retour</button>
          <?php endif; ?>
          <!-- Disabled until this step's choice is made (see stepError()
               in the script below). -->
          <button type="button" class="btn-primary" data-package-step-next disabled>
            <?= $index + 1 < $stepCount
              ? 'Étape suivante : ' . $e($steps[$stepKeys[$index + 1]])
              : 'Faire une demande de réservation' ?>
          </button>
          <span class="muted" data-package-step-error></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card card-body mt-16" data-package-request hidden>
    <h2 class="section-title">Faire une demande de réservation</h2>
    <p data-package-request-recap class="muted"></p>
    <?php if ($requestsBlocked): ?>
      <p class="muted">Les demandes de réservation en ligne sont désactivées. Contactez votre agence.</p>
    <?php else: ?>
      <form data-package-request-form>
        <input type="hidden" name="property_id" value="">
        <div class="form-grid cols-2">
          <label><span>Nom complet *</span><input class="input" type="text" name="client_name" required></label>
          <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
          <label><span>Téléphone *</span><input class="input" type="tel" name="client_phone" required></label>
        </div>
        <label><span>Message</span><textarea class="input" name="message" rows="3"></textarea></label>
        <div class="button-row mt-16">
          <button class="btn-primary" type="submit">Envoyer ma demande</button>
          <span class="muted" data-package-request-status></span>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Confirmation shown once the offer request email has actually been
       sent: closing it (the only action available) sends the client back
       to the offers listing rather than leaving them on a now-submitted
       form. -->
  <div class="simple-modal-overlay" data-package-request-sent-modal hidden>
    <div class="simple-modal-dialog" role="dialog" aria-modal="true" aria-label="Email envoyé">
      <p>Email envoyé</p>
      <div class="button-row mt-16">
        <button type="button" class="btn-primary" data-package-request-sent-ok>Ok</button>
      </div>
    </div>
  </div>

  <!-- "Voir le bien" modal: presentation of one accommodation of the offer
       (photos, description, équipements) plus a plain availability calendar.
       An offer is sold as a whole, so nothing here ever shows a price: the
       calendar fragment is rendered server-side without any rate (see
       PackagesController::availabilityCalendarHtml()). -->
  <div class="simple-modal-overlay" data-package-property-modal hidden>
    <div class="simple-modal-dialog simple-modal-dialog-wide" role="dialog" aria-modal="true" aria-label="Présentation de l'hébergement">
      <div class="simple-modal-header">
        <h3 data-package-property-title>Hébergement</h3>
        <button type="button" class="btn-icon-plain" data-package-property-close aria-label="Fermer">✕</button>
      </div>
      <p class="muted" data-package-property-status>Chargement…</p>
      <div data-package-property-content hidden>
        <div class="property-card-image" data-package-property-photo hidden><img src="" alt="" loading="lazy"></div>
        <nav class="detail-tabs mt-16" data-package-property-tabs>
          <button type="button" class="tab-btn active" data-package-property-tab-btn="description">Description</button>
          <button type="button" class="tab-btn" data-package-property-tab-btn="amenities">Équipements</button>
          <button type="button" class="tab-btn" data-package-property-tab-btn="availability">Disponibilités</button>
        </nav>
        <div data-package-property-panels>
          <div data-package-property-tab-panel="description">
            <p data-package-property-meta class="muted"></p>
            <div class="prose" data-package-property-description></div>
            <div class="property-grid mt-16" data-package-property-gallery></div>
          </div>
          <div data-package-property-tab-panel="amenities" hidden>
            <div data-package-property-amenities></div>
          </div>
          <div data-package-property-tab-panel="availability" hidden>
            <div data-package-property-calendar></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Floating recap of the offer, shown over the page as soon as an
       accommodation is chosen: the chosen bien, the options already retained
       (default ones included) and the running total, all recomputed
       server-side every time an option of the next steps is ticked. -->
  <aside class="package-total-bar" data-package-total-bar hidden>
    <p class="package-total-bar-title">Votre offre</p>
    <ul class="package-total-bar-lines" data-package-total-bar-lines></ul>
    <p class="package-total-bar-total">
      <span>Total</span>
      <strong data-package-total-bar-amount></strong>
    </p>
  </aside>
</section>

<script>
  (function () {
    var page = document.querySelector('[data-package-page]');
    if (!page) { return; }
    var packageId = page.getAttribute('data-package-id');
    var pricesHidden = page.getAttribute('data-prices-hidden') === '1';
    var results = page.querySelector('[data-package-results]');
    var groupsBlock = page.querySelector('[data-package-groups]');
    var alternatives = page.querySelector('[data-package-alternatives]');
    var requestBlock = page.querySelector('[data-package-request]');
    var requestForm = page.querySelector('[data-package-request-form]');
    var requestRecap = page.querySelector('[data-package-request-recap]');
    var requestSentModal = page.querySelector('[data-package-request-sent-modal]');
    var searchStatus = page.querySelector('[data-package-search-status]');
    var totalBar = page.querySelector('[data-package-total-bar]');
    var totalBarLines = page.querySelector('[data-package-total-bar-lines]');
    var totalBarAmount = page.querySelector('[data-package-total-bar-amount]');
    // One single accommodation chosen, or several accommodations sharing the
    // same address when the party is too big for one of them.
    var selected = null;
    var selectedGroupIds = [];
    var selectedGroupIndex = null;
    var groupSelection = null;
    var groupStay = null;
    var resultCards = [];

    function value(selector) {
      var field = page.querySelector(selector);
      return field ? field.value : '';
    }

    function money(amount, currency) {
      if (amount === null || amount === undefined) { return ''; }
      return Number(amount).toFixed(2).replace('.', ',') + ' ' + (currency || 'EUR');
    }

    function displayDate(isoDate) {
      var text = String(isoDate || '');
      var parts = text.split('-');
      if (parts.length !== 3) { return text; }
      return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    // DD-MM-YYYY variant of displayDate(), used in the "Faire une demande
    // de réservation" recap (recapText()) to match the offer's own recap
    // date format (see PackagesController::summaryText()/recapDateFr()).
    function displayDateDash(isoDate) {
      var text = String(isoDate || '');
      var parts = text.split('-');
      if (parts.length !== 3) { return text; }
      return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    // Tabs of the "Voir le bien" modal (description / équipements /
    // disponibilités). The page itself is a step wizard, not tabs.
    function initTabs(nav, panelsContainer, buttonAttribute, panelAttribute) {
      if (!nav || !panelsContainer) { return; }
      var buttons = Array.prototype.slice.call(nav.querySelectorAll('[' + buttonAttribute + ']'));
      buttons.forEach(function (button) {
        button.addEventListener('click', function () {
          var target = button.getAttribute(buttonAttribute);
          buttons.forEach(function (other) {
            other.classList.toggle('active', other === button);
          });
          Array.prototype.slice.call(panelsContainer.querySelectorAll('[' + panelAttribute + ']')).forEach(function (panel) {
            panel.hidden = panel.getAttribute(panelAttribute) !== target;
          });
        });
      });
    }

    var propertyModal = page.querySelector('[data-package-property-modal]');
    if (propertyModal) {
      initTabs(
        propertyModal.querySelector('[data-package-property-tabs]'),
        propertyModal.querySelector('[data-package-property-panels]'),
        'data-package-property-tab-btn',
        'data-package-property-tab-panel'
      );
      propertyModal.querySelector('[data-package-property-close]').addEventListener('click', closePropertyModal);
      propertyModal.addEventListener('click', function (event) {
        if (event.target === propertyModal) { closePropertyModal(); }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !propertyModal.hidden) { closePropertyModal(); }
      });
    }

    function closePropertyModal() {
      if (propertyModal) { propertyModal.hidden = true; }
    }

    function resetPropertyModalTabs() {
      var buttons = propertyModal.querySelectorAll('[data-package-property-tab-btn]');
      Array.prototype.slice.call(buttons).forEach(function (button, index) {
        button.classList.toggle('active', index === 0);
      });
      Array.prototype.slice.call(propertyModal.querySelectorAll('[data-package-property-tab-panel]')).forEach(function (panel, index) {
        panel.hidden = index !== 0;
      });
    }

    /**
     * "Voir le bien": presentation of the accommodation, availability only.
     * No price is ever requested nor displayed here — the offer is sold as
     * a whole.
     */
    function openPropertyModal(entry) {
      if (!propertyModal) { return; }
      var status = propertyModal.querySelector('[data-package-property-status]');
      var content = propertyModal.querySelector('[data-package-property-content]');
      propertyModal.hidden = false;
      resetPropertyModalTabs();
      propertyModal.querySelector('[data-package-property-title]').textContent = entry.name;
      status.hidden = false;
      status.textContent = 'Chargement…';
      content.hidden = true;

      fetch('/api/packages/' + packageId + '/properties/' + entry.property_id
        + '?anchor=' + encodeURIComponent(entry.checkin || ''), { headers: { 'Accept': 'application/json' } })
        .then(function (response) {
          return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; });
        })
        .then(function (result) {
          if (!result.ok || !result.payload || !result.payload.data) {
            status.textContent = 'Impossible d\'afficher ce bien pour le moment.';
            return;
          }
          renderPropertyModal(result.payload.data, entry);
          status.hidden = true;
          content.hidden = false;
        })
        .catch(function () {
          status.textContent = 'Impossible d\'afficher ce bien pour le moment.';
        });
    }

    function renderPropertyModal(data, entry) {
      propertyModal.querySelector('[data-package-property-title]').textContent = data.name || entry.name;

      var photo = propertyModal.querySelector('[data-package-property-photo]');
      var mainImage = (data.images && data.images.length > 0) ? data.images[0].url : entry.image_url;
      if (mainImage) {
        photo.hidden = false;
        photo.querySelector('img').src = mainImage;
        photo.querySelector('img').alt = data.name || entry.name;
      } else {
        photo.hidden = true;
      }

      var meta = [];
      if (data.bedrooms > 0) { meta.push(data.bedrooms + ' chambre(s)'); }
      if (data.bathrooms > 0) { meta.push(data.bathrooms + ' salle(s) de bain'); }
      if (data.max_guests > 0) { meta.push('jusqu\'à ' + data.max_guests + ' personne(s)'); }
      propertyModal.querySelector('[data-package-property-meta]').textContent = meta.join(' · ');

      var description = propertyModal.querySelector('[data-package-property-description]');
      description.textContent = '';
      (String(data.description || '').split(/\n+/)).forEach(function (paragraph) {
        if (paragraph.trim() === '') { return; }
        var node = document.createElement('p');
        node.textContent = paragraph;
        description.appendChild(node);
      });

      var gallery = propertyModal.querySelector('[data-package-property-gallery]');
      gallery.textContent = '';
      (data.images || []).slice(1, 9).forEach(function (image) {
        var figure = document.createElement('div');
        figure.className = 'property-card-image';
        var node = document.createElement('img');
        node.src = image.url;
        node.alt = data.name || '';
        node.loading = 'lazy';
        figure.appendChild(node);
        gallery.appendChild(figure);
      });

      var amenities = propertyModal.querySelector('[data-package-property-amenities]');
      amenities.textContent = '';
      var categories = data.amenities || {};
      var categoryNames = Object.keys(categories);
      if (categoryNames.length === 0) {
        var none = document.createElement('p');
        none.className = 'muted';
        none.textContent = 'Aucun équipement renseigné pour ce bien.';
        amenities.appendChild(none);
      } else {
        categoryNames.forEach(function (category) {
          if (category !== '') {
            var heading = document.createElement('h4');
            heading.textContent = category;
            amenities.appendChild(heading);
          }
          var list = document.createElement('div');
          list.className = 'amenities-grid';
          categories[category].forEach(function (name) {
            var item = document.createElement('div');
            item.className = 'amenities-item';
            item.textContent = '✓ ' + name;
            list.appendChild(item);
          });
          amenities.appendChild(list);
        });
      }

      // Server-rendered calendar fragment (same partial as the property
      // pages) built without any rate: availability only.
      propertyModal.querySelector('[data-package-property-calendar]').innerHTML = data.calendar_html || '';
    }

    function selectedExtraIds(block) {
      return Array.prototype.slice
        .call(page.querySelectorAll('[data-package-extra="' + block + '"]'))
        .filter(function (input) { return input.checked; })
        .map(function (input) { return input.value; });
    }

    function partyCounts() {
      return {
        adults: Math.max(0, Number(value('[data-package-adults]') || 0)),
        children3to12: Math.max(0, Number(value('[data-package-children]') || 0)),
        babies: Math.max(0, Number(value('[data-package-babies]') || 0))
      };
    }

    function renderPackageNationalities() {
      var wrap = page.querySelector('[data-package-nationalities]');
      if (!wrap) { return; }
      var sameCheckbox = wrap.querySelector('[data-package-same-nationality]');
      var uniformWrap = wrap.querySelector('[data-package-uniform-nationality-wrap]');
      var uniformSelect = wrap.querySelector('[data-package-uniform-nationality]');
      var list = wrap.querySelector('[data-package-nationality-list]');
      var template = wrap.querySelector('[data-package-nationality-template]');
      if (!sameCheckbox || !uniformWrap || !uniformSelect || !list || !template) { return; }
      var counts = partyCounts();
      var existing = Array.prototype.slice.call(list.querySelectorAll('[data-package-nationality-select]')).map(function (select) {
        return { type: select.getAttribute('data-type') || '', nationality: select.value || '' };
      });
      var same = sameCheckbox.checked;
      uniformWrap.hidden = !same;
      list.hidden = same;
      list.innerHTML = '';
      if (same) { return; }
      var entries = [];
      for (var i = 0; i < counts.adults; i += 1) {
        entries.push({ type: 'adult', label: 'Adulte ' + (i + 1) + ' — Nationalité' });
      }
      for (var b = 0; b < counts.babies; b += 1) {
        entries.push({ type: 'child_under3', label: 'Enfant (< 3 ans) ' + (b + 1) + ' — Nationalité' });
      }
      for (var c = 0; c < counts.children3to12; c += 1) {
        entries.push({ type: 'child', label: 'Enfant (3-12 ans) ' + (c + 1) + ' — Nationalité' });
      }
      entries.forEach(function (entry, index) {
        var node = template.content.firstElementChild.cloneNode(true);
        node.querySelector('span').textContent = entry.label;
        var select = node.querySelector('[data-package-nationality-select]');
        select.setAttribute('data-type', entry.type);
        if (existing[index] && existing[index].type === entry.type) {
          select.value = existing[index].nationality;
        } else if (uniformSelect.value) {
          select.value = uniformSelect.value;
        }
        list.appendChild(node);
      });
    }

    function collectPackageGuests() {
      var wrap = page.querySelector('[data-package-nationalities]');
      if (!wrap) { return []; }
      var counts = partyCounts();
      var sameCheckbox = wrap.querySelector('[data-package-same-nationality]');
      if (sameCheckbox && sameCheckbox.checked) {
        var uniform = wrap.querySelector('[data-package-uniform-nationality]');
        var nationality = uniform ? (uniform.value || '') : '';
        return []
          .concat(Array.from({ length: counts.adults }, function () { return { type: 'adult', nationality: nationality }; }))
          .concat(Array.from({ length: counts.children3to12 }, function () { return { type: 'child', nationality: nationality }; }))
          .concat(Array.from({ length: counts.babies }, function () { return { type: 'child_under3', nationality: nationality }; }));
      }
      return Array.prototype.slice.call(wrap.querySelectorAll('[data-package-nationality-select]')).map(function (select) {
        return {
          type: select.getAttribute('data-type') || 'adult',
          nationality: select.value || ''
        };
      });
    }

    function selectedPackageNationality(guests) {
      var filled = guests.filter(function (guest) { return (guest.nationality || '').trim() !== ''; });
      if (filled.length === 0 || filled.length !== guests.length) { return ''; }
      var first = filled[0].nationality;
      var same = filled.every(function (guest) { return guest.nationality === first; });
      return same ? first : '';
    }

    /**
     * Whether every guest currently has a nationality selected (uniform
     * value applied to all, or every individual selector filled in): the
     * tourist tax (see Packages::guestsForNationality()) cannot be computed
     * without it, so the accommodation step must not let the client move on
     * until it is set (see accommodationStepError()).
     */
    function nationalitiesComplete() {
      var guests = collectPackageGuests();
      if (guests.length === 0) { return false; }
      return guests.every(function (guest) { return (guest.nationality || '').trim() !== ''; });
    }

    function searchPayload() {
      var body = new URLSearchParams();
      var guests = collectPackageGuests();
      body.set('checkin_date', value('[data-package-checkin]'));
      body.set('checkout_date', value('[data-package-checkout]'));
      body.set('adults', value('[data-package-adults]') || '0');
      body.set('children_3to12', value('[data-package-children]') || '0');
      body.set('children_under3', value('[data-package-babies]') || '0');
      body.set('nationality', selectedPackageNationality(guests));
      body.set('guests_json', JSON.stringify(guests));
      var flight = page.querySelector('[data-package-flight]:checked');
      if (flight) { body.set('flight_id', flight.value); }
      selectedExtraIds('transports').forEach(function (id) { body.append('transport_ids[]', id); });
      selectedExtraIds('activities').forEach(function (id) { body.append('activity_ids[]', id); });
      selectedExtraIds('meals').forEach(function (id) { body.append('meal_ids[]', id); });
      // Same-address selection: the server prices the whole selection as one
      // single all-inclusive total.
      selectedGroupIds.forEach(function (id) { body.append('property_ids[]', String(id)); });
      return body;
    }

    /**
     * "Inclus dans ce prix" : labels only (chambre, ménage, options par
     * défaut) — an offer is sold as a whole, so no line ever carries its own
     * amount. The tourist tax is never listed here: it is never part of any
     * total (see touristTaxNote() below).
     */
    function includesList(labels, preselectedLabels) {
      var wrapper = document.createElement('div');
      var hasIncludes = labels && labels.length > 0;
      var hasPreselected = preselectedLabels && preselectedLabels.length > 0;
      if (!hasIncludes && !hasPreselected) { return wrapper; }
      wrapper.className = 'package-includes';
      if (hasIncludes) {
        var title = document.createElement('p');
        title.className = 'muted';
        var titleUnderline = document.createElement('u');
        titleUnderline.textContent = 'Inclus dans ce prix :';
        title.appendChild(titleUnderline);
        wrapper.appendChild(title);
        var list = document.createElement('ul');
        labels.forEach(function (label) {
          var item = document.createElement('li');
          item.textContent = label;
          list.appendChild(item);
        });
        wrapper.appendChild(list);
      }
      if (hasPreselected) {
        var preselectedTitle = document.createElement('p');
        preselectedTitle.className = 'muted';
        var preselectedUnderline = document.createElement('u');
        preselectedUnderline.textContent = 'Options Présélectionnée(s)';
        preselectedTitle.appendChild(preselectedUnderline);
        wrapper.appendChild(preselectedTitle);
        var preselectedList = document.createElement('ul');
        preselectedLabels.forEach(function (label) {
          var item = document.createElement('li');
          item.textContent = label;
          preselectedList.appendChild(item);
        });
        wrapper.appendChild(preselectedList);
      }
      return wrapper;
    }

    /**
     * "NB: Taxe Touristique (non comprise dans le total à régler à votre
     * arrivée)" with the amount computed from the party's declared
     * nationality — shown just above the "Choisir ce bien" button, never
     * folded into any total (see Packages::guestsForNationality()).
     */
    function touristTaxNote(entry, currency) {
      var wrapper = document.createElement('div');
      if (!entry.tourist_tax_total) { return wrapper; }
      var spacer = document.createElement('p');
      spacer.innerHTML = '&nbsp;';
      wrapper.appendChild(spacer);
      var note = document.createElement('p');
      note.className = 'muted';
      note.textContent = 'NB: Taxe Touristique (non comprise dans le total à régler à votre arrivée) : '
        + money(entry.tourist_tax_total, entry.currency || currency);
      wrapper.appendChild(note);
      return wrapper;
    }

    function entryCard(entry, currency, isAlternative) {
      var card = document.createElement('article');
      card.className = 'card package-result-card';

      if (entry.image_url) {
        var figure = document.createElement('div');
        figure.className = 'property-card-image';
        var image = document.createElement('img');
        image.src = entry.image_url;
        image.alt = entry.name;
        image.loading = 'lazy';
        figure.appendChild(image);
        card.appendChild(figure);
      }

      var body = document.createElement('div');
      body.className = 'card-body';
      card.appendChild(body);

      var title = document.createElement('h3');
      title.textContent = entry.name;
      body.appendChild(title);

      var meta = [];
      if (entry.bedrooms > 0) { meta.push(entry.bedrooms + ' chambre(s)'); }
      if (entry.max_guests > 0) { meta.push('jusqu\'à ' + entry.max_guests + ' personne(s)'); }
      if (meta.length > 0) {
        var metaLine = document.createElement('p');
        metaLine.className = 'muted';
        metaLine.textContent = meta.join(' · ');
        body.appendChild(metaLine);
      }

      var dates = document.createElement('p');
      dates.textContent = 'Du ' + displayDate(entry.checkin) + ' au ' + displayDate(entry.checkout) + ' — ' + entry.nights + ' nuit(s)'
        + (isAlternative && entry.nights_lost > 0 ? ' (' + entry.nights_lost + ' nuit(s) de moins que demandé)' : '');
      body.appendChild(dates);

      // Price of everything the client already gets at this first step: the
      // stay (nightly rate for the party, ménage) plus the offer's default
      // options — tourist tax excluded (see touristTaxNote()). The floating
      // recap then follows the full all-inclusive total as further options
      // get picked.
      if (!pricesHidden && entry.total_base !== null && entry.total_base !== undefined) {
        var total = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = 'Total Offre : ' + money(entry.total_base, entry.currency || currency);
        total.appendChild(strong);
        body.appendChild(total);
        body.appendChild(includesList(entry.includes, entry.preselected));
        body.appendChild(touristTaxNote(entry, currency));
      }

      var actions = document.createElement('div');
      actions.className = 'button-row';
      body.appendChild(actions);

      var button = document.createElement('button');
      button.type = 'button';
      button.className = isAlternative ? 'btn-secondary' : 'btn-primary';
      button.textContent = isAlternative ? 'Voir cette période' : 'Choisir ce bien';
      button.addEventListener('click', function () {
        if (isAlternative) {
          // The client keeps the hand: we only prefill the new dates and
          // relaunch the search, nothing is ever booked automatically.
          page.querySelector('[data-package-checkin]').value = entry.checkin;
          page.querySelector('[data-package-checkout]').value = entry.checkout;
          search();
          return;
        }
        selected = entry;
        // A single accommodation and a same-address selection are exclusive.
        selectedGroupIds = [];
        selectedGroupIndex = null;
        groupSelection = null;
        groupStay = null;
        markSelection();
        clearStepError();
      });
      actions.appendChild(button);

      if (!isAlternative) { resultCards.push({ entry: entry, card: card, button: button }); }

      var detailButton = document.createElement('button');
      detailButton.type = 'button';
      detailButton.className = 'btn-secondary';
      detailButton.textContent = 'Voir le bien';
      detailButton.addEventListener('click', function () { openPropertyModal(entry); });
      actions.appendChild(detailButton);

      return card;
    }

    /**
     * Drops the accommodation the client had chosen and everything computed
     * from it (same-address selection, floating recap, unlocked steps), then
     * brings them back to the accommodation step.
     */
    function clearSelection() {
      if (selected === null && selectedGroupIds.length === 0) { return; }
      selected = null;
      selectedGroupIds = [];
      selectedGroupIndex = null;
      groupSelection = null;
      groupStay = null;
      results.innerHTML = '';
      results.hidden = true;
      alternatives.innerHTML = '';
      alternatives.hidden = true;
      groupsBlock.innerHTML = '';
      groupsBlock.hidden = true;
      resultCards = [];
      if (requestBlock) { requestBlock.hidden = true; }
      searchStatus.textContent = 'Relancez la recherche pour voir les hébergements disponibles.';
      showStep(0);
    }

    function markSelection() {
      resultCards.forEach(function (item) {
        var isSelected = selected !== null && String(item.entry.property_id) === String(selected.property_id)
          && item.entry.checkin === selected.checkin;
        item.card.classList.toggle('is-selected', isSelected);
        item.button.textContent = isSelected ? 'Bien sélectionné ✓' : 'Choisir ce bien';
      });
      refreshSteps();
    }

    /**
     * Party too big for one single accommodation: the properties of the offer
     * sharing one same address can be combined. No individual price is shown,
     * only the combined all-inclusive total of the ticked properties.
     */
    function renderGroups(data) {
      groupsBlock.innerHTML = '';
      groupsBlock.hidden = true;
      var groups = data.groups || [];
      if (groups.length === 0) {
        // The party now fits a single accommodation (or no address can host
        // it): a stale multi-bien selection must not survive.
        selectedGroupIds = [];
        selectedGroupIndex = null;
        groupSelection = null;
        return;
      }
      groupsBlock.hidden = false;

      var heading = document.createElement('h2');
      heading.className = 'section-title';
      heading.textContent = 'Biens à la même adresse';
      groupsBlock.appendChild(heading);

      var intro = document.createElement('p');
      intro.className = 'muted';
      intro.textContent = 'Vous pouvez sélectionner plusieurs biens ci-dessous car ils sont à la même adresse.';
      groupsBlock.appendChild(intro);

      var required = Number(value('[data-package-adults]') || 0) + Number(value('[data-package-children]') || 0);

      groups.forEach(function (group, groupIndex) {
        var section = document.createElement('div');
        section.className = 'card card-body mt-16';
        groupsBlock.appendChild(section);

        var address = document.createElement('p');
        var addressLabel = document.createElement('strong');
        addressLabel.textContent = group.location;
        address.appendChild(addressLabel);
        section.appendChild(address);

        var grid = document.createElement('div');
        grid.className = 'package-results-grid';
        section.appendChild(grid);

        var capacityLine = document.createElement('p');
        capacityLine.className = 'muted mt-16';
        section.appendChild(capacityLine);

        var totalLine = document.createElement('p');
        section.appendChild(totalLine);

        function refreshGroupStatus() {
          var capacity = 0;
          var unlimited = false;
          group.properties.forEach(function (property) {
            if (selectedGroupIndex !== groupIndex) { return; }
            if (selectedGroupIds.indexOf(String(property.property_id)) === -1) { return; }
            if (Number(property.max_guests) > 0) { capacity += Number(property.max_guests); } else { unlimited = true; }
          });
          var count = selectedGroupIndex === groupIndex ? selectedGroupIds.length : 0;
          capacityLine.textContent = count === 0
            ? 'Sélectionnez les biens à réserver (' + required + ' personne(s) à loger).'
            : count + ' bien(s) sélectionné(s) — capacité ' + (unlimited ? 'suffisante' : capacity + ' / ' + required + ' personne(s)');
          totalLine.textContent = '';
          if (selectedGroupIndex === groupIndex && groupSelection && !pricesHidden
            && groupSelection.total_base !== null && groupSelection.total_base !== undefined) {
            var strong = document.createElement('strong');
            strong.textContent = 'Total Offre : ' + money(groupSelection.total_base, groupSelection.currency || data.currency);
            totalLine.appendChild(strong);
            totalLine.appendChild(includesList(groupSelection.includes, groupSelection.preselected));
            totalLine.appendChild(touristTaxNote(groupSelection, data.currency));
          }
        }

        group.properties.forEach(function (property) {
          var entry = {
            property_id: property.property_id,
            name: property.name,
            image_url: property.image_url,
            checkin: property.checkin,
            checkout: property.checkout
          };
          var card = document.createElement('article');
          card.className = 'card package-result-card';
          if (property.image_url) {
            var figure = document.createElement('div');
            figure.className = 'property-card-image';
            var image = document.createElement('img');
            image.src = property.image_url;
            image.alt = property.name;
            image.loading = 'lazy';
            figure.appendChild(image);
            card.appendChild(figure);
          }
          var body = document.createElement('div');
          body.className = 'card-body';
          card.appendChild(body);

          var title = document.createElement('h3');
          title.textContent = property.name;
          body.appendChild(title);

          var meta = [];
          if (property.bedrooms > 0) { meta.push(property.bedrooms + ' chambre(s)'); }
          if (property.max_guests > 0) { meta.push('jusqu\'à ' + property.max_guests + ' personne(s)'); }
          if (meta.length > 0) {
            var metaLine = document.createElement('p');
            metaLine.className = 'muted';
            metaLine.textContent = meta.join(' · ');
            body.appendChild(metaLine);
          }

          var choice = document.createElement('label');
          choice.className = 'inline-check';
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.value = String(property.property_id);
          checkbox.checked = selectedGroupIndex === groupIndex
            && selectedGroupIds.indexOf(String(property.property_id)) !== -1;
          checkbox.addEventListener('change', function () {
            if (selectedGroupIndex !== groupIndex) {
              // Only one address can be combined at a time.
              selectedGroupIndex = groupIndex;
              selectedGroupIds = [];
            }
            var position = selectedGroupIds.indexOf(checkbox.value);
            if (checkbox.checked && position === -1) { selectedGroupIds.push(checkbox.value); }
            if (!checkbox.checked && position !== -1) { selectedGroupIds.splice(position, 1); }
            if (selectedGroupIds.length === 0) { selectedGroupIndex = null; }
            selected = null;
            groupSelection = null;
            groupStay = null;
            clearStepError();
            // The combined total is computed server-side for the ticked biens.
            search();
          });
          choice.appendChild(checkbox);
          var choiceLabel = document.createElement('span');
          choiceLabel.textContent = 'Réserver ce bien';
          choice.appendChild(choiceLabel);
          body.appendChild(choice);

          var actions = document.createElement('div');
          actions.className = 'button-row';
          var detailButton = document.createElement('button');
          detailButton.type = 'button';
          detailButton.className = 'btn-secondary';
          detailButton.textContent = 'Voir le bien';
          detailButton.addEventListener('click', function () { openPropertyModal(entry); });
          actions.appendChild(detailButton);
          body.appendChild(actions);

          grid.appendChild(card);
        });

        refreshGroupStatus();
        if (selectedGroupIndex === groupIndex && group.properties.length > 0) {
          groupStay = {
            checkin: group.properties[0].checkin,
            checkout: group.properties[0].checkout,
            location: group.location
          };
        }
      });
    }

    function render(data) {
      results.innerHTML = '';
      alternatives.innerHTML = '';
      alternatives.hidden = true;
      results.hidden = false;
      resultCards = [];
      groupSelection = data.selection || null;
      groupStay = null;
      if (requestBlock) { requestBlock.hidden = true; }

      function grid(container, entries, isAlternative) {
        var wrapper = document.createElement('div');
        // 3 accommodations per row (see .package-results-grid).
        wrapper.className = 'package-results-grid';
        entries.forEach(function (entry) {
          wrapper.appendChild(entryCard(entry, data.currency, isAlternative));
        });
        container.appendChild(wrapper);
      }

      renderGroups(data);

      if (data.matches.length > 0) {
        var heading = document.createElement('h2');
        heading.className = 'section-title';
        heading.textContent = 'Hébergements disponibles pour vos dates';
        results.appendChild(heading);
        grid(results, data.matches, false);
        // A previously chosen bien is kept selected when it is still offered.
        var previous = selected;
        selected = previous === null ? null : (data.matches.filter(function (entry) {
          return String(entry.property_id) === String(previous.property_id) && entry.checkin === previous.checkin;
        })[0] || null);
        markSelection();
        return;
      }

      selected = null;

      if (data.alternatives.length > 0) {
        alternatives.hidden = false;
        var altHeading = document.createElement('h2');
        altHeading.className = 'section-title';
        altHeading.textContent = 'Aucun hébergement pour vos dates — voici des dates proches';
        alternatives.appendChild(altHeading);
        grid(alternatives, data.alternatives, true);
        return;
      }

      if (!groupsBlock.hidden) {
        results.hidden = true;
        return;
      }

      var empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'Aucun hébergement ne correspond à ces dates. Essayez d\'autres dates ou un autre nombre de personnes.';
      results.appendChild(empty);
    }

    // Toggling options quickly starts several searches: only the newest one may
    // update the screen, otherwise a slower older response would restore a
    // stale total and selection.
    var searchGeneration = 0;

    function search() {
      if (!value('[data-package-checkin]') || !value('[data-package-checkout]')) {
        searchStatus.textContent = 'Choisissez vos dates.';
        return;
      }
      searchGeneration += 1;
      var generation = searchGeneration;
      searchStatus.textContent = 'Recherche en cours…';
      fetch('/api/packages/' + packageId + '/search', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: searchPayload().toString()
      }).then(function (response) {
        return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; });
      }).then(function (result) {
        if (generation !== searchGeneration) { return; }
        if (!result.ok) {
          searchStatus.textContent = (result.payload && result.payload.message) || 'Recherche impossible.';
          return;
        }
        searchStatus.textContent = '';
        render(result.payload.data);
        refreshSteps();
      }).catch(function () {
        if (generation !== searchGeneration) { return; }
        searchStatus.textContent = 'Recherche impossible pour le moment.';
      });
    }

    var searchButton = page.querySelector('[data-package-search]');
    if (searchButton) { searchButton.addEventListener('click', search); }

    // Changing a search criterion (dates, nombre de personnes…) invalidates
    // the bien already chosen: its price and even its availability depend on
    // those criteria, so the selection is dropped instead of being carried
    // over to a stay the client never validated.
    page.querySelectorAll('[data-package-checkin], [data-package-checkout], [data-package-adults],'
      + ' [data-package-children], [data-package-babies]').forEach(function (field) {
      field.addEventListener('change', clearSelection);
      field.addEventListener('input', clearSelection);
    });

    // The nationality only changes the tourist tax (Packages::guestsForNationality()),
    // never the availability: the bien already chosen (and the already
    // displayed cards) stay valid, so a fresh search is re-run in place —
    // mirroring how flight/extras options refresh the total — instead of
    // wiping the results like the fields above.
    function refreshOnNationalityChange() {
      refreshSteps();
      if (!results.hidden || !alternatives.hidden || !groupsBlock.hidden) { search(); }
    }
    page.querySelectorAll('[data-package-same-nationality], [data-package-uniform-nationality]').forEach(function (field) {
      field.addEventListener('change', refreshOnNationalityChange);
      field.addEventListener('input', refreshOnNationalityChange);
    });
    page.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || !target.matches || !target.matches('[data-package-nationality-select]')) { return; }
      refreshOnNationalityChange();
    });
    page.addEventListener('input', function (event) {
      var target = event.target;
      if (!target || !target.matches || !target.matches('[data-package-nationality-select]')) { return; }
      refreshOnNationalityChange();
    });
    page.querySelectorAll('[data-package-adults], [data-package-children], [data-package-babies], [data-package-same-nationality], [data-package-uniform-nationality]')
      .forEach(function (field) {
        field.addEventListener('change', renderPackageNationalities);
        field.addEventListener('input', renderPackageNationalities);
      });
    renderPackageNationalities();

    // Steps: Hébergement, Vol (when the offer has flight options), Transport,
    // Activités then Restauration. Hidden panels keep their inputs in the DOM,
    // so the whole selection is always read by searchPayload().
    var stepPanels = Array.prototype.slice.call(page.querySelectorAll('[data-package-step-panel]'));
    var stepItems = Array.prototype.slice.call(page.querySelectorAll('[data-package-step-item]'));
    var currentStep = 0;

    function clearStepError() {
      stepPanels.forEach(function (panel) {
        var error = panel.querySelector('[data-package-step-error]');
        if (error) { error.textContent = ''; }
      });
    }

    function showStepError(message) {
      var error = stepPanels[currentStep].querySelector('[data-package-step-error]');
      if (error) { error.textContent = message; }
    }

    function showStep(index) {
      currentStep = index;
      stepPanels.forEach(function (panel, position) { panel.hidden = position !== index; });
      stepItems.forEach(function (item, position) {
        item.classList.toggle('active', position === index);
        item.classList.toggle('done', position < index);
      });
      clearStepError();
      refreshSteps();
      if (requestBlock) { requestBlock.hidden = true; }
    }

    /**
     * The accommodation step is the only blocking one: either one single bien
     * is chosen, or enough biens of one same address are ticked for everybody
     * to be housed (the server only returns a selection total when the whole
     * party fits in the ticked biens). The nationality is checked first since
     * it also drives the tourist tax shown on every accommodation card.
     */
    function accommodationStepError() {
      if (!nationalitiesComplete()) {
        return 'Renseignez la nationalité pour continuer.';
      }
      if (selectedGroupIds.length > 0) {
        if (selectedGroupIds.length < 2) {
          return 'Sélectionnez au moins deux biens à la même adresse.';
        }
        if (!groupSelection) {
          return 'Les biens sélectionnés ne suffisent pas à loger tout le monde : sélectionnez d\'autres biens.';
        }
        return '';
      }
      if (selected === null) { return 'Choisissez votre hébergement pour continuer.'; }
      return '';
    }

    /**
     * Error blocking a given step, i.e. the choice its client still has to
     * make: an hébergement, a vol, and for every optional block either at
     * least one ticked option or an explicit "sans …". A block with only
     * mandatory options has no checkbox to tick and never blocks.
     */
    function stepError(index) {
      var panel = stepPanels[index];
      var key = panel.getAttribute('data-package-step-panel');
      if (key === 'accommodation') { return accommodationStepError(); }
      if (key === 'flight') {
        return panel.querySelector('[data-package-flight]:checked') === null
          ? 'Choisissez votre vol pour continuer.' : '';
      }
      var none = panel.querySelector('[data-package-extra-none]');
      if (none === null) { return ''; }
      if (none.checked) { return ''; }
      var ticked = Array.prototype.slice.call(panel.querySelectorAll('[data-package-extra]'))
        .some(function (input) { return input.checked && !input.disabled; });
      return ticked ? '' : 'Choisissez une option pour continuer.';
    }

    function currentStepError() {
      return stepError(currentStep);
    }

    /**
     * Keeps every "Étape suivante" button locked until its own step's choice
     * is made, and refreshes the running total pinned at the bottom.
     */
    function refreshSteps() {
      stepPanels.forEach(function (panel, index) {
        var nextButton = panel.querySelector('[data-package-step-next]');
        var error = stepError(index);
        if (nextButton) { nextButton.disabled = error !== ''; }
        // Shown live next to the button (not only after a blocked click),
        // so the client immediately sees why "Étape suivante" is disabled
        // (e.g. "Choisissez votre hébergement pour continuer.").
        var errorSpan = panel.querySelector('[data-package-step-error]');
        if (errorSpan) { errorSpan.textContent = error; }
      });
      refreshTotalBar();
    }

    /**
     * Running total of the offer: it appears as soon as an hébergement is
     * chosen (from the accommodation and the flight) and follows every option
     * ticked afterwards, since each change re-runs the server-side search.
     */
    function refreshTotalBar() {
      if (!totalBar) { return; }
      var chosen = selectedGroupIds.length > 0 ? groupSelection : selected;
      if (!chosen) {
        totalBar.hidden = true;
        return;
      }
      totalBar.hidden = false;
      if (totalBarLines) {
        totalBarLines.innerHTML = '';
        // The chosen bien first, then everything already retained: the
        // offer's default options and the ones ticked since.
        var lines = selectedGroupIds.length > 0
          ? [selectedGroupIds.length + ' biens à la même adresse'
              + (groupStay && groupStay.location ? ' (' + groupStay.location + ')' : '')]
          : [selected.name];
        (chosen.includes || []).forEach(function (label) { lines.push(label); });
        (chosen.preselected || []).forEach(function (label) { lines.push(label); });
        selectedOptionLabels().forEach(function (label) { lines.push(label); });
        lines.forEach(function (label) {
          var item = document.createElement('li');
          item.textContent = label;
          totalBarLines.appendChild(item);
        });
      }
      if (totalBarAmount) {
        totalBarAmount.textContent = pricesHidden ? 'Sur demande' : money(chosen.total_all_in, chosen.currency);
      }
    }

    /**
     * Labels of the *optional* options the traveller has ticked on the
     * following steps. The default ones — including the flight, whichever is
     * chosen — are already listed by the server in "includes", so the
     * floating recap mirrors the current choice without repeating a line.
     */
    function selectedOptionLabels() {
      var labels = [];
      [['transports', 'Transport'], ['activities', 'Activité'], ['meals', 'Restauration']].forEach(function (block) {
        page.querySelectorAll('[data-package-extra="' + block[0] + '"]').forEach(function (input) {
          if (!input.checked || input.disabled) { return; }
          var card = input.closest('.package-option-card');
          var name = card ? card.querySelector('h3') : null;
          if (name) { labels.push(block[1] + ' : ' + name.textContent); }
        });
      });
      return labels;
    }

    function stayDates() {
      if (selected !== null) { return { checkin: selected.checkin, checkout: selected.checkout }; }
      if (groupStay !== null) { return { checkin: groupStay.checkin, checkout: groupStay.checkout }; }
      return { checkin: value('[data-package-checkin]'), checkout: value('[data-package-checkout]') };
    }

    function recapText() {
      var dates = stayDates();
      if (selected !== null) {
        return selected.name + ' — du ' + displayDateDash(dates.checkin) + ' au ' + displayDateDash(dates.checkout)
          + (pricesHidden || selected.total_all_in === null || selected.total_all_in === undefined
            ? '' : ' — total tout compris ' + money(selected.total_all_in, selected.currency));
      }
      var label = selectedGroupIds.length + ' biens à la même adresse'
        + (groupStay && groupStay.location ? ' (' + groupStay.location + ')' : '')
        + ' — du ' + displayDateDash(dates.checkin) + ' au ' + displayDateDash(dates.checkout);
      if (!pricesHidden && groupSelection && groupSelection.total_all_in !== null && groupSelection.total_all_in !== undefined) {
        label += ' — total tout compris ' + money(groupSelection.total_all_in, groupSelection.currency);
      }
      return label;
    }

    function revealRequest() {
      if (!requestBlock) { return; }
      requestBlock.hidden = false;
      if (requestForm) {
        var field = requestForm.querySelector('[name="property_id"]');
        if (field) { field.value = selected !== null ? String(selected.property_id) : ''; }
      }
      if (requestRecap) { requestRecap.textContent = recapText(); }
      requestBlock.scrollIntoView({ behavior: 'smooth' });
    }

    stepPanels.forEach(function (panel, index) {
      var previousButton = panel.querySelector('[data-package-step-prev]');
      if (previousButton) {
        previousButton.addEventListener('click', function () { showStep(index - 1); });
      }
      var nextButton = panel.querySelector('[data-package-step-next]');
      if (nextButton) {
        nextButton.addEventListener('click', function () {
          var error = currentStepError();
          if (error !== '') { showStepError(error); return; }
          if (index + 1 < stepPanels.length) { showStep(index + 1); return; }
          revealRequest();
        });
      }
    });
    if (stepPanels.length > 0) { showStep(0); }

    // The displayed total includes the chosen flight option, activities and
    // meal plans: changing the selection after a search must recompute it
    // instead of leaving a stale price (and a stale selection) on screen.
    page.querySelectorAll('[data-package-flight], [data-package-extra]').forEach(function (input) {
      input.addEventListener('change', function () {
        // Ticking an option means the client does not decline that block.
        var panel = input.closest('[data-package-step-panel]');
        var none = panel ? panel.querySelector('[data-package-extra-none]') : null;
        if (none && input.checked) { none.checked = false; }
        refreshSteps();
        if (!results.hidden || !alternatives.hidden || !groupsBlock.hidden) { search(); }
      });
    });

    // "Sans transport/activité/formule": an explicit refusal, exclusive with
    // the block's optional options.
    page.querySelectorAll('[data-package-extra-none]').forEach(function (none) {
      none.addEventListener('change', function () {
        if (none.checked) {
          var panel = none.closest('[data-package-step-panel]');
          var changed = false;
          if (panel) {
            panel.querySelectorAll('[data-package-extra]').forEach(function (input) {
              if (input.checked && !input.disabled) { input.checked = false; changed = true; }
            });
          }
          if (changed && (!results.hidden || !alternatives.hidden || !groupsBlock.hidden)) { search(); }
        }
        refreshSteps();
      });
    });

    if (requestForm) {
      // A second submit before the first response would create a duplicate
      // request and consume the package stock twice: block submissions while
      // one is in flight and only release the form when it fails.
      var requestPending = false;
      requestForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var hasSelection = selected !== null || (selectedGroupIds.length > 1 && groupSelection !== null);
        if (!hasSelection || requestPending) { return; }
        var status = page.querySelector('[data-package-request-status]');
        var submitButton = requestForm.querySelector('[type="submit"]');
        requestPending = true;
        if (submitButton) { submitButton.disabled = true; }
        var releaseRequest = function () {
          requestPending = false;
          if (submitButton) { submitButton.disabled = false; }
        };
        var dates = stayDates();
        var body = searchPayload();
        // searchPayload() already carries property_ids[] for a same-address
        // selection; a single bien is sent as property_id.
        if (selected !== null) { body.set('property_id', String(selected.property_id)); }
        body.set('checkin_date', dates.checkin);
        body.set('checkout_date', dates.checkout);
        new FormData(requestForm).forEach(function (fieldValue, name) {
          if (name !== 'property_id') { body.set(name, fieldValue); }
        });
        status.textContent = 'Envoi en cours…';
        fetch('/api/packages/' + packageId + '/request', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(function (response) {
          return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; });
        }).then(function (result) {
          if (!result.ok) {
            status.textContent = (result.payload && result.payload.message) || 'Envoi impossible.';
            releaseRequest();
            return;
          }
          status.textContent = '';
          requestForm.reset();
          requestForm.hidden = true;
          requestRecap.textContent = 'Votre demande a bien été envoyée. Notre équipe vous répondra rapidement.';
          if (requestSentModal) { requestSentModal.hidden = false; }
        }).catch(function () {
          status.textContent = 'Envoi impossible pour le moment.';
          releaseRequest();
        });
      });
    }

    if (requestSentModal) {
      var requestSentOkButton = requestSentModal.querySelector('[data-package-request-sent-ok]');
      if (requestSentOkButton) {
        requestSentOkButton.addEventListener('click', function () {
          window.location.href = '/offres';
        });
      }
    }
  })();
</script>
