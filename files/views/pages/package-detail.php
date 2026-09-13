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

  <?php if ($flights !== []): ?>
    <h2 class="section-title mt-16">Vol</h2>
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
  <?php endif; ?>

  <?php foreach ($extraBlocks as $blockKey => $block): if ($block['rows'] === []) { continue; } ?>
    <h2 class="section-title mt-16"><?= $e($block['title']) ?></h2>
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
  <?php endforeach; ?>

  <h2 class="section-title mt-16">Hébergement et disponibilités</h2>
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
      var card = document.createElement('div');
      card.className = 'card card-body mt-16';
      var title = document.createElement('h3');
      title.textContent = entry.name;
      card.appendChild(title);

      var dates = document.createElement('p');
      dates.textContent = 'Du ' + entry.checkin + ' au ' + entry.checkout + ' — ' + entry.nights + ' nuit(s)'
        + (isAlternative && entry.nights_lost > 0 ? ' (' + entry.nights_lost + ' nuit(s) de moins que demandé)' : '');
      card.appendChild(dates);

      // One single figure: the offer is sold as a whole (vol + hébergement
      // + activités + restauration), never broken down line by line.
      if (!pricesHidden && entry.total_all_in !== null && entry.total_all_in !== undefined) {
        var total = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = 'Total tout compris : ' + money(entry.total_all_in, entry.currency || currency);
        total.appendChild(strong);
        card.appendChild(total);
      }

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
      card.appendChild(button);
      return card;
    }

    function render(data) {
      results.innerHTML = '';
      alternatives.innerHTML = '';
      alternatives.hidden = true;
      results.hidden = false;
      selected = null;
      if (requestBlock) { requestBlock.hidden = true; }

      if (data.matches.length > 0) {
        var heading = document.createElement('h2');
        heading.className = 'section-title';
        heading.textContent = 'Hébergements disponibles pour vos dates';
        results.appendChild(heading);
        data.matches.forEach(function (entry) {
          results.appendChild(entryCard(entry, data.currency, false));
        });
        return;
      }

      if (data.alternatives.length > 0) {
        alternatives.hidden = false;
        var altHeading = document.createElement('h2');
        altHeading.className = 'section-title';
        altHeading.textContent = 'Aucun hébergement pour vos dates — voici des dates proches';
        alternatives.appendChild(altHeading);
        data.alternatives.forEach(function (entry) {
          alternatives.appendChild(entryCard(entry, data.currency, true));
        });
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
      requestForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!selected) { return; }
        var status = page.querySelector('[data-package-request-status]');
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
            return;
          }
          status.textContent = '';
          requestForm.reset();
          requestForm.hidden = true;
          requestRecap.textContent = 'Votre demande a bien été envoyée. Notre équipe vous répondra rapidement.';
        }).catch(function () {
          status.textContent = 'Envoi impossible pour le moment.';
        });
      });
    }
  })();
</script>
