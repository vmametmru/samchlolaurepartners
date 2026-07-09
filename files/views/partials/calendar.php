<?php declare(strict_types=1);
$month = new DateTimeImmutable();
$year = (int) $month->format('Y');
$monthIndex = (int) $month->format('n');
$daysInMonth = (int) $month->format('t');
$firstDay = (int) $month->format('w');
$availabilityMap = [];
foreach ($availability as $day) {
    $availabilityMap[$day['date']] = $day['available'];
}
?>
<div class="card card-body calendar-card">
  <h3><?= \App\View::e(strftime('%B %Y')) ?></h3>
  <div class="calendar-grid header"><?php foreach (['D','L','M','M','J','V','S'] as $label): ?><div><?= $label ?></div><?php endforeach; ?></div>
  <div class="calendar-grid">
    <?php for ($i = 0; $i < $firstDay; $i++): ?><div></div><?php endfor; ?>
    <?php for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++):
      $date = sprintf('%04d-%02d-%02d', $year, $monthIndex, $dayNumber);
      $state = $availabilityMap[$date] ?? null;
      $class = $state === true ? 'available' : ($state === false ? 'unavailable' : 'unknown');
    ?>
      <div class="calendar-cell <?= $class ?>"><?= $dayNumber ?></div>
    <?php endfor; ?>
  </div>
  <div class="calendar-legend"><span class="dot dot-green"></span> Disponible <span class="dot dot-red"></span> Indisponible</div>
</div>
