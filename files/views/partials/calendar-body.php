<?php declare(strict_types=1);
// Renders the calendar grid (months + legend) for the property detail page.
// Always shows 12 months; each available day is clickable to select the
// booking form's arrival/departure dates (see initBookingCalendarSelection
// in assets/js/app.js).
$calendarMonths = 12;
$calendarStartDate = isset($calendarStart) && $calendarStart !== ''
    ? new DateTimeImmutable((string) $calendarStart)
    : new DateTimeImmutable('first day of this month');

$availabilityMap = [];
foreach ($availability as $day) {
    $availabilityMap[$day['date']] = $day['available'];
}
$rateMap = [];
foreach (($rates ?? []) as $rate) {
    $rateMap[$rate['date_from']] = $rate;
}

$frenchMonths = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
?>
<div class="calendar-months">
  <?php for ($offset = 0; $offset < $calendarMonths; $offset++):
    $month = $calendarStartDate->modify('+' . $offset . ' months');
    $year = (int) $month->format('Y');
    $monthIndex = (int) $month->format('n');
    $daysInMonth = (int) $month->format('t');
    $firstDay = (int) $month->format('w');
  ?>
    <div class="card card-body calendar-card">
      <h3><?= \App\View::e($frenchMonths[$monthIndex]) ?> <?= $year ?></h3>
      <div class="calendar-grid header"><?php foreach (['D', 'L', 'M', 'M', 'J', 'V', 'S'] as $label): ?><div><?= $label ?></div><?php endforeach; ?></div>
      <div class="calendar-grid">
        <?php for ($i = 0; $i < $firstDay; $i++): ?><div></div><?php endfor; ?>
        <?php for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++):
          $date = sprintf('%04d-%02d-%02d', $year, $monthIndex, $dayNumber);
          $state = $availabilityMap[$date] ?? null;
          $class = $state === true ? 'available' : ($state === false ? 'unavailable' : 'unknown');
          $rate = $rateMap[$date] ?? null;
        ?>
          <div class="calendar-cell <?= $class ?>"<?php if ($state === true): ?> data-calendar-date="<?= $date ?>" data-calendar-selectable<?php endif; ?><?php if ($rate !== null): ?> data-calendar-rate="<?= (float) $rate['price_per_night'] ?>"<?php endif; ?>>
            <span class="calendar-day"><?= $dayNumber ?></span>
            <?php if ($state === true && $rate !== null): ?>
              <span class="calendar-price"><?= number_format((float) $rate['price_per_night'], 0, ',', ' ') ?> <?= \App\View::e($rate['currency']) ?></span>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  <?php endfor; ?>
</div>
<div class="calendar-legend">
  <span class="dot dot-green"></span> Disponible
  <span class="dot dot-red"></span> Indisponible
  <span class="dot dot-gray"></span> Non renseigné
</div>
