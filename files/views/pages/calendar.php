<?php declare(strict_types=1);
/** @var array<int, array{property: array, availability: array, single_night: array, rates: array}> $rows */
/** @var array<int, DateTimeImmutable> $dates */
/** @var int $visibleDays */
/** @var array<int, array{value: string, label: string}> $monthOptions */
/** @var array<int, string> $selectedMonths */
$visibleDays = $visibleDays ?? 31;
$monthOptions = $monthOptions ?? [];
$selectedMonths = $selectedMonths ?? [];
$frenchDays = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$frenchMonthsShort = [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
?>
<section class="container section-lg">
  <h1>Calendrier</h1>
  <p class="muted">Vue d'ensemble des disponibilités et tarifs de tous les biens. Approchez la souris du bord gauche ou droit du tableau pour faire défiler les dates.</p>

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
    <div class="calendar-filter-actions">
      <button type="submit" class="btn-primary calendar-filter-submit">Afficher</button>
      <?php if ($selectedMonths !== []): ?>
        <a class="text-link" href="/calendrier">30 prochains jours</a>
      <?php endif; ?>
    </div>
    <p class="muted calendar-filter-hint">Sans sélection, seuls les 30 prochains jours sont chargés.</p>
  </form>

  <?php if ($rows === []): ?>
    <p class="muted">Aucun hébergement à afficher.</p>
  <?php else: ?>
    <div class="calendar-board" data-calendar-board style="--cal-visible-days: <?= (int) $visibleDays ?>;">
      <table class="calendar-board-table">
        <thead>
          <tr>
            <th class="cal-fixed cal-col-photo">Photo</th>
            <th class="cal-fixed cal-col-name">Bien</th>
            <th class="cal-fixed cal-col-num">Pers. max</th>
            <th class="cal-fixed cal-col-num">Chambres</th>
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
            $photo = $property['images'][0]['url'] ?? 'https://via.placeholder.com/56x40?text=%20';
            $propertyId = (int) ($property['id'] ?? 0);
          ?>
            <tr>
              <td class="cal-fixed cal-col-photo">
                <a href="/properties/<?= $propertyId ?>"><img class="cal-thumb" src="<?= \App\View::e($photo) ?>" alt="<?= \App\View::e($property['name'] ?? '') ?>"></a>
              </td>
              <td class="cal-fixed cal-col-name">
                <a class="text-link" href="/properties/<?= $propertyId ?>"><?= \App\View::e($property['name'] ?? '') ?></a>
              </td>
              <td class="cal-fixed cal-col-num"><?= (int) ($property['max_guests'] ?? 0) ?></td>
              <td class="cal-fixed cal-col-num"><?= (int) ($property['bedrooms'] ?? 0) ?></td>
              <?php foreach ($dates as $date):
                $key = $date->format('Y-m-d');
                $state = $availabilityMap[$key] ?? null;
                $isSingleNight = $singleNightMap[$key] ?? false;
                $class = $isSingleNight
                  ? 'single-night'
                  : ($state === true ? 'available' : ($state === false ? 'unavailable' : 'unknown'));
                $rate = $rateMap[$key] ?? null;
              ?>
                <td class="cal-cell cal-<?= $class ?>" title="<?= \App\View::e($key) ?>">
                  <?php if ($state === true && $rate !== null): ?>
                    <span class="cal-price"><?= number_format((float) $rate['price_per_night'], 0, ',', ' ') ?></span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="calendar-legend">
      <span class="dot dot-green"></span> Disponible
      <span class="dot dot-red"></span> Indisponible
      <span class="dot dot-yellow"></span> Réservation d'1 nuit (arrivée ou départ uniquement)
      <span class="dot dot-gray"></span> Non réservable / Non renseigné
    </div>
  <?php endif; ?>
</section>
