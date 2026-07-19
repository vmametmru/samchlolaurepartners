<?php declare(strict_types=1);
/** @var array<int, array{property: array, availability: array, single_night: array, rates: array, capacity_ok: bool}> $rows */
/** @var array<int, DateTimeImmutable> $dates */
/** @var int $visibleDays */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var int $adults */
/** @var int $childrenUnder3 */
/** @var int $children3to12 */
/** @var int $totalGuests */
$visibleDays = $visibleDays ?? 31;
$dateFrom = $dateFrom ?? '';
$dateTo = $dateTo ?? '';
$adults = $adults ?? 0;
$childrenUnder3 = $childrenUnder3 ?? 0;
$children3to12 = $children3to12 ?? 0;
$totalGuests = $totalGuests ?? 0;
$today = isset($today) && $today !== '' ? (string) $today : date('Y-m-d');
$frenchDays = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$frenchMonthsShort = [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
?>
<section class="container section-lg">
  <div class="section-header">
    <h1>Calendrier</h1>
    <button type="button" class="btn-secondary calendar-help-btn" data-help-trigger="calendar-help">Aide</button>
  </div>
  <dialog class="help-dialog" data-help-dialog="calendar-help">
    <form method="dialog">
      <button type="submit" class="help-dialog-close" aria-label="Fermer">×</button>
    </form>
    <p class="muted">Vue d'ensemble des disponibilités et tarifs de tous les biens. Approchez la souris du bord gauche ou droit du tableau pour faire défiler les dates.</p>
    <p class="muted">Réservez plusieurs biens en quelques clics : cliquez une date d'arrivée puis une date de départ sur un bien, puis recommencez sur un autre bien (mêmes dates ou dates différentes) pour l'ajouter à votre sélection.</p>
  </dialog>

  <p class="muted">Période de recherche.</p>
  <form class="search-card calendar-filter" method="get" action="/calendrier" data-calendar-filter-form>
    <div class="form-grid search-grid" data-date-range>
      <label><span>Du</span><input class="input" type="date" name="date_from" value="<?= \App\View::e($dateFrom) ?>"></label>
      <label><span>Au</span><input class="input" type="date" name="date_to" value="<?= \App\View::e($dateTo) ?>"></label>
      <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= $adults > 0 ? (int) $adults : 2 ?>" data-calendar-guest-input></label>
      <label><span>Enfants (&lt;3ans)</span><input class="input" type="number" min="0" max="20" name="children_under3" value="<?= (int) $childrenUnder3 ?>" data-calendar-guest-input></label>
      <label><span>Enfants (3-11ans)</span><input class="input" type="number" min="0" max="20" name="children_3to12" value="<?= (int) $children3to12 ?>" data-calendar-guest-input></label>
      <button type="submit" class="btn-primary search-button calendar-filter-submit" data-calendar-filter-submit>Afficher les disponibilités</button>
    </div>
  </form>

  <p class="calendar-loading-message" data-calendar-loading hidden><span class="spinner" aria-hidden="true"></span> Chargement des disponibilités…</p>

  <?php if ($totalGuests < 1): ?>
    <p class="muted calendar-guest-required-hint">Veuillez renseigner le nombre de personnes ci-dessus puis cliquer sur « Afficher les disponibilités » pour voir les biens et leurs dates disponibles.</p>
  <?php elseif ($rows === []): ?>
    <p class="muted">Aucun hébergement à afficher.</p>
  <?php else: ?>
    <p class="muted calendar-price-note">Prix de la nuité en Euros. Le prix inclus les frais de nettoyage 2 fois par semaine.</p>
    <label class="calendar-name-toggle">
      <input type="checkbox" data-calendar-name-toggle>
      Afficher le nom du bien
    </label>

    <div class="calendar-legend-row">
      <div class="calendar-legend">
        <span class="dot dot-green"></span> Disponible
        <span class="dot dot-red"></span> Indisponible
        <span class="dot dot-yellow"></span> Bloquée
        <span class="dot dot-gray"></span> Non réservable / Non renseigné
      </div>
      <button type="button" class="btn-secondary calendar-view-selection-btn" data-multi-cart-view-btn hidden>Voir votre sélection</button>
    </div>

    <div class="calendar-board cal-name-hidden" data-calendar-board data-multi-calendar-board data-total-guests="<?= (int) $totalGuests ?>" style="--cal-visible-days: <?= (int) $visibleDays ?>;">
      <table class="calendar-board-table">
        <thead>
          <tr>
            <th class="cal-fixed cal-col-photo">Photo</th>
            <th class="cal-fixed cal-col-name">Bien</th>
            <th class="cal-fixed cal-col-num cal-col-capacity" title="Pers. max">
              <span class="cal-icon" aria-hidden="true">👤</span>
              <span class="sr-only">Pers. max</span>
            </th>
            <th class="cal-fixed cal-col-num cal-col-rooms" title="Chambres">
              <span class="cal-icon" aria-hidden="true">🛏️</span>
              <span class="sr-only">Chambres</span>
            </th>
            <?php foreach ($dates as $date):
              $dow = (int) $date->format('w');
              $isWeekend = $dow === 0 || $dow === 6;
            ?>
              <th class="cal-day-head<?= $isWeekend ? ' cal-weekend' : '' ?>">
                <span class="cal-day-dow"><?= \App\View::e($frenchDays[$dow]) ?></span>
                <span class="cal-day-num"><?= (int) $date->format('j') ?></span>
                <span class="cal-day-mon"><?= \App\View::e($frenchMonthsShort[(int) $date->format('n')]) ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $property = $row['property'];
            $availabilityMap = $row['availability'];
            $singleNightMap = $row['single_night'];
            $rateMap = $row['rates'];
            $capacityOk = $row['capacity_ok'] ?? true;
            $photo = $property['images'][0]['url'] ?? 'https://via.placeholder.com/56x40?text=%20';
            $propertyId = (int) ($property['id'] ?? 0);
            $propertyName = (string) ($property['name'] ?? '');
            $maxGuests = (int) ($property['max_guests'] ?? 0);
          ?>
            <tr data-property-row data-property-id="<?= $propertyId ?>" data-property-name="<?= \App\View::e($propertyName) ?>" data-property-photo="<?= \App\View::e($photo) ?>" data-max-guests="<?= $maxGuests ?>" data-capacity-ok="<?= $capacityOk ? '1' : '0' ?>">
              <td class="cal-fixed cal-col-photo">
                <a href="/properties/<?= $propertyId ?>"><img class="cal-thumb" src="<?= \App\View::e($photo) ?>" alt="<?= \App\View::e($propertyName) ?>"></a>
              </td>
              <td class="cal-fixed cal-col-name">
                <a class="text-link" href="/properties/<?= $propertyId ?>"><?= \App\View::e($propertyName) ?></a>
                <?php if (!empty($row['load_failed'])): ?>
                  <p class="muted cal-capacity-note"><span class="cal-warning-icon" aria-hidden="true">⚠️</span>Disponibilités temporairement indisponibles — réessayez dans quelques instants.</p>
                <?php endif; ?>
              </td>
              <td class="cal-fixed cal-col-num cal-col-capacity"><?= (int) ($property['max_guests'] ?? 0) ?></td>
              <td class="cal-fixed cal-col-num cal-col-rooms"><?= (int) ($property['bedrooms'] ?? 0) ?></td>
              <?php if (!empty($row['restricted'])): ?>
                <td class="cal-cell cal-restricted" colspan="<?= count($dates) ?>">
                  <p class="muted cal-restricted-note">Merci de contacter votre agence pour ce bien.</p>
                </td>
              <?php else: ?>
              <?php foreach ($dates as $date):
                $key = $date->format('Y-m-d');
                $isPast = $key < $today;
                $state = $availabilityMap[$key] ?? null;
                $isSingleNight = $singleNightMap[$key] ?? false;
                $class = $isPast
                  ? 'past'
                  : ($isSingleNight
                    ? 'single-night'
                    : ($state === true ? 'available' : ($state === false ? 'unavailable' : 'unknown')));
                // A past date is never bookable regardless of what Lodgify
                // reports, so it is never treated as available/clickable here.
                $isAvailable = !$isPast && $state === true;
                $rate = $rateMap[$key] ?? null;
                $minStay = isset($rate['min_stay']) && $rate['min_stay'] !== null ? (int) $rate['min_stay'] : 1;
                // Every row is selectable regardless of its individual
                // capacity: several properties can be combined to reach the
                // requested party size, so every cell carries the date data
                // attributes (even unavailable ones), so the client script
                // can reuse an unavailable/single-night day as a valid
                // departure date, exactly like the property detail calendar.
              ?>
                <td class="cal-cell cal-<?= $class ?><?= $isAvailable ? ' cal-clickable' : '' ?>" title="<?= \App\View::e($key) ?>" data-calendar-date="<?= $key ?>" data-calendar-available="<?= $isAvailable ? '1' : '0' ?>" data-calendar-minstay="<?= $minStay > 0 ? $minStay : 1 ?>" data-calendar-price="<?= $isAvailable && $rate !== null ? (float) $rate['price_per_night'] : '0' ?>">
                  <?php if ($isAvailable && $rate !== null): ?>
                    <span class="cal-price"><?= number_format((float) $rate['price_per_night'], 0, ',', ' ') ?></span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="multi-booking-cart" id="multi-cart-selection" data-multi-cart hidden>
      <div class="multi-cart-header">
        <h2 class="section-title">Votre sélection</h2>
        <button type="button" class="btn-secondary" data-multi-cart-clear>Effacer les sélections</button>
      </div>
      <ul class="multi-cart-list" data-multi-cart-list></ul>
      <p class="form-feedback" data-multi-cart-gap-hint></p>
      <div class="multi-cart-summary" data-multi-cart-summary>
        <div class="multi-cart-summary-body">
          <div class="multi-cart-summary-dates">
            <p data-multi-cart-summary-line>0 bien(s) sélectionné(s) x 0 nuit(s) sélectionnée(s) = 0 nuit(s) sélectionnée(s)</p>
            <p data-multi-cart-summary-capacity-row>Capacité cumulée du/des bien(s) sélectionné(s) :</p>
            <ul class="multi-cart-capacity-table" data-multi-cart-capacity-table></ul>
            <p class="form-feedback" data-multi-cart-capacity-hint></p>
          </div>
          <div class="multi-cart-summary-total">
            <p>Montant Total : <span data-multi-cart-summary-total>0</span> Euros</p>
          </div>
        </div>
      </div>
      <p class="form-feedback" data-multi-cart-feedback></p>
      <form class="stack-md multi-cart-checkout" data-multi-cart-form data-api-form data-success-message="Vos demandes de réservation ont été envoyées ! Vous recevrez un email de confirmation." method="post" action="/api/reservations/request-multiple" hidden>
        <input type="hidden" name="adults" value="<?= (int) $adults ?>">
        <input type="hidden" name="children" value="<?= (int) ($childrenUnder3 + $children3to12) ?>">
        <input type="hidden" name="children_under3" value="<?= (int) $childrenUnder3 ?>">
        <input type="hidden" name="children_3to12" value="<?= (int) $children3to12 ?>">
        <input type="hidden" name="items" data-multi-cart-items>
        <div class="form-grid cols-2">
          <label><span>Nom et prénom complet *</span><input class="input" type="text" name="client_name" required></label>
          <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
        </div>
        <?php require BASE_PATH . '/files/views/partials/phone-input.php'; ?>
        <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        <button class="btn-primary" type="submit">Envoyer mes demandes de réservation</button>
        <p class="form-feedback" data-form-feedback></p>
      </form>
    </div>
  <?php endif; ?>
</section>
