<?php declare(strict_types=1);
/** @var array<int, array{property: array, availability: array, single_night: array, rates: array, capacity_ok: bool}> $rows */
/** @var array<int, DateTimeImmutable> $dates */
/** @var int $visibleDays */
/** @var array<int, array{value: string, label: string}> $monthOptions */
/** @var array<int, string> $selectedMonths */
/** @var int $adults */
/** @var int $childrenUnder5 */
/** @var int $children5to12 */
/** @var int $totalGuests */
$visibleDays = $visibleDays ?? 31;
$monthOptions = $monthOptions ?? [];
$selectedMonths = $selectedMonths ?? [];
$adults = $adults ?? 0;
$childrenUnder5 = $childrenUnder5 ?? 0;
$children5to12 = $children5to12 ?? 0;
$totalGuests = $totalGuests ?? 0;
$today = isset($today) && $today !== '' ? (string) $today : date('Y-m-d');
$frenchDays = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$frenchMonthsShort = [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
?>
<section class="container section-lg">
  <h1>Calendrier</h1>
  <p class="muted">Vue d'ensemble des disponibilités et tarifs de tous les biens. Approchez la souris du bord gauche ou droit du tableau pour faire défiler les dates.</p>
  <p class="muted">Réservez plusieurs biens en quelques clics : cliquez une date d'arrivée puis une date de départ sur un bien, puis recommencez sur un autre bien (mêmes dates ou dates différentes) pour l'ajouter à votre sélection.</p>

  <form class="calendar-filter" method="get" action="/calendrier">
    <span class="calendar-filter-label">Mois à afficher&nbsp;:</span>
    <div class="calendar-filter-months">
      <?php foreach ($monthOptions as $option): ?>
        <label class="calendar-filter-month">
          <input type="checkbox" name="months[]" value="<?= \App\View::e($option['value']) ?>"<?= in_array($option['value'], $selectedMonths, true) ? ' checked' : '' ?>>
          <?= \App\View::e($option['label']) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="calendar-guest-form" data-calendar-guest-form>
      <span class="calendar-filter-label">Nombre de personnes&nbsp;:</span>
      <label class="calendar-guest-field"><span>Adulte(s)</span><input class="input" type="number" name="adults" min="1" max="20" value="<?= $adults > 0 ? (int) $adults : 2 ?>"></label>
      <label class="calendar-guest-field"><span>Enfant(s) -5 ans</span><input class="input" type="number" name="children_under5" min="0" max="20" value="<?= (int) $childrenUnder5 ?>"></label>
      <label class="calendar-guest-field"><span>Enfant(s) 5-12 ans</span><input class="input" type="number" name="children_5to12" min="0" max="20" value="<?= (int) $children5to12 ?>"></label>
    </div>

    <div class="calendar-filter-actions">
      <button type="submit" class="btn-primary calendar-filter-submit">Afficher les disponibilités</button>
      <?php if ($selectedMonths !== []): ?>
        <a class="text-link" href="/calendrier">30 prochains jours</a>
      <?php endif; ?>
    </div>
    <p class="muted calendar-filter-hint">Renseignez le nombre de personnes pour afficher les biens disponibles. Sans sélection de mois, seuls les 30 prochains jours sont chargés.</p>
  </form>

  <?php if ($totalGuests < 1): ?>
    <p class="muted calendar-guest-required-hint">Veuillez renseigner le nombre de personnes ci-dessus puis cliquer sur « Afficher les disponibilités » pour voir les biens et leurs dates disponibles.</p>
  <?php elseif ($rows === []): ?>
    <p class="muted">Aucun hébergement à afficher.</p>
  <?php else: ?>
    <?php $insufficientCount = count(array_filter($rows, static fn (array $row): bool => !($row['capacity_ok'] ?? true))); ?>
    <?php if ($insufficientCount > 0): ?>
      <p class="muted calendar-capacity-warning"><?= $insufficientCount ?> bien(s) ci-dessous ont une capacité individuelle insuffisante pour <?= (int) $totalGuests ?> personne(s), mais restent sélectionnables : combinez-les avec d'autres biens pour atteindre le nombre de personnes voulu.</p>
    <?php endif; ?>
    <div class="calendar-board" data-calendar-board data-multi-calendar-board data-total-guests="<?= (int) $totalGuests ?>" style="--cal-visible-days: <?= (int) $visibleDays ?>;">
      <table class="calendar-board-table">
        <thead>
          <tr>
            <th class="cal-fixed cal-col-photo">Photo</th>
            <th class="cal-fixed cal-col-name">Bien</th>
            <th class="cal-fixed cal-col-num" title="Pers. max">
              <span class="cal-icon" aria-hidden="true">👤</span>
              <span class="sr-only">Pers. max</span>
            </th>
            <th class="cal-fixed cal-col-num" title="Chambres">
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
                <?php if (!$capacityOk): ?>
                  <p class="muted cal-capacity-note">Capacité max <?= $maxGuests ?> pers. — combinez avec un autre bien pour atteindre <?= (int) $totalGuests ?> personne(s).</p>
                <?php endif; ?>
              </td>
              <td class="cal-fixed cal-col-num"><?= (int) ($property['max_guests'] ?? 0) ?></td>
              <td class="cal-fixed cal-col-num"><?= (int) ($property['bedrooms'] ?? 0) ?></td>
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
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="multi-booking-cart" data-multi-cart hidden>
      <h2 class="section-title">Votre sélection</h2>
      <ul class="multi-cart-list" data-multi-cart-list></ul>
      <div class="multi-cart-summary" data-multi-cart-summary>
        <p><span data-multi-cart-summary-count>0</span> bien(s) sélectionné(s)</p>
        <p><span data-multi-cart-summary-nights>0</span> nuit(s) sélectionnée(s)</p>
        <p data-multi-cart-summary-capacity-row>Capacité cumulée des biens sélectionnés : <span data-multi-cart-summary-capacity>0</span> / <?= (int) $totalGuests ?> personne(s)</p>
        <p class="form-feedback" data-multi-cart-capacity-hint></p>
        <p>Montant Total : <span data-multi-cart-summary-total>0</span> Euros</p>
      </div>
      <p class="form-feedback" data-multi-cart-feedback></p>
      <form class="stack-md multi-cart-checkout" data-multi-cart-form data-api-form data-success-message="Vos demandes de réservation ont été envoyées ! Vous recevrez un email de confirmation." method="post" action="/api/reservations/request-multiple" hidden>
        <input type="hidden" name="adults" value="<?= (int) $adults ?>">
        <input type="hidden" name="children_under5" value="<?= (int) $childrenUnder5 ?>">
        <input type="hidden" name="children_5to12" value="<?= (int) $children5to12 ?>">
        <input type="hidden" name="items" data-multi-cart-items>
        <div class="form-grid cols-2">
          <label><span>Nom et prénom complet *</span><input class="input" type="text" name="client_name" required></label>
          <label><span>Email *</span><input class="input" type="email" name="client_email" required></label>
          <label><span>Téléphone</span><input class="input" type="tel" name="client_phone"></label>
        </div>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"></textarea></label>
        <button class="btn-primary" type="submit">Envoyer mes demandes de réservation</button>
        <p class="form-feedback" data-form-feedback></p>
      </form>
    </div>

    <div class="calendar-legend">
      <span class="dot dot-green"></span> Disponible
      <span class="dot dot-red"></span> Indisponible
      <span class="dot dot-yellow"></span> Réservation d'1 nuit (arrivée ou départ uniquement)
      <span class="dot dot-gray"></span> Non réservable / Non renseigné
    </div>
  <?php endif; ?>
</section>
