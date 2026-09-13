<?php declare(strict_types=1);
/**
 * Public page of a single offer: general description, flight options,
 * available accommodations (searched in the local cache only), activities
 * and meal plans, then the reservation request form.
 *
 * An offer is sold as a whole: no individual price (flight, activity, meal,
 * accommodation) is ever printed here — only the all-inclusive total
 * returned by the search endpoint.
 */
$e = static fn (mixed $value): string => \App\View::e($value);
$t = static fn (array $row, string $field): string => \App\Packages::text($row, $field);
$flights = $package['flights'] ?? [];
$extraBlocks = [
    'activities' => ['title' => 'Activités', 'rows' => $package['activities'] ?? [], 'included' => 'Incluse dans l\'offre', 'optional' => 'Ajouter cette activité'],
    'meals' => ['title' => 'Restauration', 'rows' => $package['meals'] ?? [], 'included' => 'Incluse dans l\'offre', 'optional' => 'Ajouter cette formule'],
];
$today = (new DateTimeImmutable('now', new DateTimeZone('Etc/GMT-4')))->format('Y-m-d');
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

  <?php if (!empty($package['photo_url'])): ?>
    <div class="property-card-image mt-16"><img src="<?= $e($package['photo_url']) ?>" alt="<?= $e($t($package, 'title')) ?>"></div>
  <?php endif; ?>

  <?php $description = $t($package, 'description'); ?>
  <?php if ($description !== ''): ?>
    <div class="card card-body mt-16"><p><?= nl2br($e($description)) ?></p></div>
  <?php endif; ?>

  <?php
  // Vol / Activités / Restauration / Hébergement are shown as tabs, in the
  // order the offer is presented (the accommodation search comes last since
  // its results are listed underneath the tabs). Tabs with nothing to show
  // are simply not rendered, and the first one is open by default.
  $tabs = [];
  if ($flights !== []) {
      $tabs['flight'] = 'Vol';
  }
  foreach ($extraBlocks as $blockKey => $block) {
      if ($block['rows'] !== []) {
          $tabs[$blockKey] = $block['title'];
      }
  }
  $tabs['accommodation'] = 'Hébergement';
  $firstTab = array_key_first($tabs);
  ?>
  <nav class="detail-tabs mt-16" data-package-tabs>
    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
      <button type="button" class="tab-btn<?= $tabKey === $firstTab ? ' active' : '' ?>" data-package-tab-btn="<?= $e($tabKey) ?>"><?= $e($tabLabel) ?></button>
    <?php endforeach; ?>
  </nav>

  <div data-package-tab-panels>
    <?php if ($flights !== []): ?>
      <div data-package-tab-panel="flight"<?= $firstTab === 'flight' ? '' : ' hidden' ?>>
        <div class="card card-body">
          <?php foreach ($flights as $index => $flight): ?>
            <label class="inline-check">
              <input type="radio" name="package_flight" value="<?= (int) $flight['id'] ?>" data-package-flight
                <?= (int) ($flight['is_default'] ?? 0) === 1 || $index === 0 ? 'checked' : '' ?>>
              <span>
                <strong><?= $e((string) $flight['label']) ?></strong>
                <?php if (!empty($flight['airline'])): ?> — <?= $e((string) $flight['airline']) ?><?php endif; ?>
                <?php if (!empty($flight['cabin_class'])): ?> (<?= $e((string) $flight['cabin_class']) ?>)<?php endif; ?>
                <?php if (!empty($flight['description'])): ?><br><small class="muted"><?= nl2br($e((string) $flight['description'])) ?></small><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($extraBlocks as $blockKey => $block): if ($block['rows'] === []) { continue; } ?>
      <div data-package-tab-panel="<?= $e($blockKey) ?>"<?= $firstTab === $blockKey ? '' : ' hidden' ?>>
        <div class="property-grid">
          <?php foreach ($block['rows'] as $extra): $mandatory = (int) ($extra['is_mandatory'] ?? 0) === 1; ?>
            <article class="card">
              <?php if (!empty($extra['photo_url'])): ?>
                <div class="property-card-image"><img src="<?= $e($extra['photo_url']) ?>" alt="<?= $e($t($extra, 'label')) ?>" loading="lazy"></div>
              <?php endif; ?>
              <div class="card-body">
                <h3><?= $e($t($extra, 'label')) ?></h3>
                <?php $extraDescription = $t($extra, 'description'); ?>
                <?php if ($extraDescription !== ''): ?><p><?= nl2br($e($extraDescription)) ?></p><?php endif; ?>
                <label class="inline-check">
                  <input type="checkbox" value="<?= (int) $extra['id'] ?>" data-package-extra="<?= $e($blockKey) ?>"
                    <?= $mandatory ? 'checked disabled' : '' ?>>
                  <?= $e($mandatory ? $block['included'] : $block['optional']) ?>
                </label>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div data-package-tab-panel="accommodation"<?= $firstTab === 'accommodation' ? '' : ' hidden' ?>>
      <div class="card card-body">
        <div class="form-grid cols-2">
          <label><span>Arrivée</span><input class="input" type="date" min="<?= $e($today) ?>" data-package-checkin></label>
          <label><span>Départ</span><input class="input" type="date" min="<?= $e($today) ?>" data-package-checkout></label>
          <label><span>Adultes</span><input class="input" type="number" min="1" step="1" value="2" data-package-adults></label>
          <label><span>Enfants (3-12 ans)</span><input class="input" type="number" min="0" step="1" value="0" data-package-children></label>
          <label><span>Bébés (moins de 3 ans)</span><input class="input" type="number" min="0" step="1" value="0" data-package-babies></label>
        </div>
        <div class="button-row mt-16">
          <button class="btn-primary" type="button" data-package-search>Rechercher</button>
          <span class="muted" data-package-search-status></span>
        </div>
        <?php if ($cacheUpdatedLabel !== null && $cacheUpdatedLabel !== ''): ?>
          <p class="muted mt-16"><?= $e($cacheUpdatedLabel) ?></p>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="mt-16" data-package-results hidden></div>
  <div class="mt-16" data-package-alternatives hidden></div>

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
</section>

<script>
  (function () {
    var page = document.querySelector('[data-package-page]');
    if (!page) { return; }
    var packageId = page.getAttribute('data-package-id');
    var pricesHidden = page.getAttribute('data-prices-hidden') === '1';
    var results = page.querySelector('[data-package-results]');
    var alternatives = page.querySelector('[data-package-alternatives]');
    var requestBlock = page.querySelector('[data-package-request]');
    var requestForm = page.querySelector('[data-package-request-form]');
    var requestRecap = page.querySelector('[data-package-request-recap]');
    var searchStatus = page.querySelector('[data-package-search-status]');
    var selected = null;

    function value(selector) {
      var field = page.querySelector(selector);
      return field ? field.value : '';
    }

    function money(amount, currency) {
      if (amount === null || amount === undefined) { return ''; }
      return Number(amount).toFixed(2).replace('.', ',') + ' ' + (currency || 'EUR');
    }

    // Vol / Hébergement / Activités / Restauration tabs. Hidden panels keep
    // their inputs in the DOM, so the flight/activity/meal selection is
    // still read by searchPayload() whichever tab is open.
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

    initTabs(
      page.querySelector('[data-package-tabs]'),
      page.querySelector('[data-package-tab-panels]'),
      'data-package-tab-btn',
      'data-package-tab-panel'
    );

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

    function searchPayload() {
      var body = new URLSearchParams();
      body.set('checkin_date', value('[data-package-checkin]'));
      body.set('checkout_date', value('[data-package-checkout]'));
      body.set('adults', value('[data-package-adults]') || '0');
      body.set('children_3to12', value('[data-package-children]') || '0');
      body.set('children_under3', value('[data-package-babies]') || '0');
      var flight = page.querySelector('[data-package-flight]:checked');
      if (flight) { body.set('flight_id', flight.value); }
      selectedExtraIds('activities').forEach(function (id) { body.append('activity_ids[]', id); });
      selectedExtraIds('meals').forEach(function (id) { body.append('meal_ids[]', id); });
      return body;
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
      dates.textContent = 'Du ' + entry.checkin + ' au ' + entry.checkout + ' — ' + entry.nights + ' nuit(s)'
        + (isAlternative && entry.nights_lost > 0 ? ' (' + entry.nights_lost + ' nuit(s) de moins que demandé)' : '');
      body.appendChild(dates);

      // One single figure: the offer is sold as a whole (vol + hébergement
      // + activités + restauration), never broken down line by line.
      if (!pricesHidden && entry.total_all_in !== null && entry.total_all_in !== undefined) {
        var total = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = 'Total tout compris : ' + money(entry.total_all_in, entry.currency || currency);
        total.appendChild(strong);
        body.appendChild(total);
      }

      var actions = document.createElement('div');
      actions.className = 'button-row';
      body.appendChild(actions);

      var button = document.createElement('button');
      button.type = 'button';
      button.className = isAlternative ? 'btn-secondary' : 'btn-primary';
      button.textContent = isAlternative ? 'Voir cette période' : 'Faire une demande de réservation';
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
        if (requestForm) {
          requestForm.querySelector('[name="property_id"]').value = entry.property_id;
        }
        if (requestBlock) { requestBlock.hidden = false; }
        if (requestRecap) {
          requestRecap.textContent = entry.name + ' — du ' + entry.checkin + ' au ' + entry.checkout
            + (pricesHidden || entry.total_all_in === null ? '' : ' — total tout compris ' + money(entry.total_all_in, entry.currency || currency));
        }
        if (requestBlock) { requestBlock.scrollIntoView({ behavior: 'smooth' }); }
      });
      actions.appendChild(button);

      var detailButton = document.createElement('button');
      detailButton.type = 'button';
      detailButton.className = 'btn-secondary';
      detailButton.textContent = 'Voir le bien';
      detailButton.addEventListener('click', function () { openPropertyModal(entry); });
      actions.appendChild(detailButton);

      return card;
    }

    function render(data) {
      results.innerHTML = '';
      alternatives.innerHTML = '';
      alternatives.hidden = true;
      results.hidden = false;
      selected = null;
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

      if (data.matches.length > 0) {
        var heading = document.createElement('h2');
        heading.className = 'section-title';
        heading.textContent = 'Hébergements disponibles pour vos dates';
        results.appendChild(heading);
        grid(results, data.matches, false);
        return;
      }

      if (data.alternatives.length > 0) {
        alternatives.hidden = false;
        var altHeading = document.createElement('h2');
        altHeading.className = 'section-title';
        altHeading.textContent = 'Aucun hébergement pour vos dates — voici des dates proches';
        alternatives.appendChild(altHeading);
        grid(alternatives, data.alternatives, true);
        return;
      }

      var empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'Aucun hébergement ne correspond à ces dates. Essayez d\'autres dates ou un autre nombre de personnes.';
      results.appendChild(empty);
    }

    function search() {
      if (!value('[data-package-checkin]') || !value('[data-package-checkout]')) {
        searchStatus.textContent = 'Choisissez vos dates.';
        return;
      }
      searchStatus.textContent = 'Recherche en cours…';
      fetch('/api/packages/' + packageId + '/search', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: searchPayload().toString()
      }).then(function (response) {
        return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; });
      }).then(function (result) {
        if (!result.ok) {
          searchStatus.textContent = (result.payload && result.payload.message) || 'Recherche impossible.';
          return;
        }
        searchStatus.textContent = '';
        render(result.payload.data);
      }).catch(function () {
        searchStatus.textContent = 'Recherche impossible pour le moment.';
      });
    }

    var searchButton = page.querySelector('[data-package-search]');
    if (searchButton) { searchButton.addEventListener('click', search); }

    // The displayed total includes the chosen flight option, activities and
    // meal plans: changing the selection after a search must recompute it
    // instead of leaving a stale price (and a stale selection) on screen.
    page.querySelectorAll('[data-package-flight], [data-package-extra]').forEach(function (input) {
      input.addEventListener('change', function () {
        if (!results.hidden || !alternatives.hidden) { search(); }
      });
    });

    if (requestForm) {
      // A second submit before the first response would create a duplicate
      // request and consume the package stock twice: block submissions while
      // one is in flight and only release the form when it fails.
      var requestPending = false;
      requestForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!selected || requestPending) { return; }
        var status = page.querySelector('[data-package-request-status]');
        var submitButton = requestForm.querySelector('[type="submit"]');
        requestPending = true;
        if (submitButton) { submitButton.disabled = true; }
        var releaseRequest = function () {
          requestPending = false;
          if (submitButton) { submitButton.disabled = false; }
        };
        var body = searchPayload();
        body.set('property_id', String(selected.property_id));
        body.set('checkin_date', selected.checkin);
        body.set('checkout_date', selected.checkout);
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
        }).catch(function () {
          status.textContent = 'Envoi impossible pour le moment.';
          releaseRequest();
        });
      });
    }
  })();
</script>
