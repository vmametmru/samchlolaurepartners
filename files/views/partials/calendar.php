<?php declare(strict_types=1);
$calendarMonths = in_array($calendarMonths ?? 2, [2, 6, 12], true) ? $calendarMonths : 2;
$calendarStart = isset($calendarStart) && $calendarStart !== ''
    ? (string) $calendarStart
    : (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$propertyId = (int) ($property['id'] ?? 0);
$calendarTabs = [
    2 => 'Mois en cours + suivant',
    6 => 'Afficher les 6 prochains mois',
    12 => 'Afficher les 12 prochains mois',
];
?>
<div class="calendar-widget" data-calendar-widget data-property-id="<?= $propertyId ?>">
  <div class="calendar-filters" data-calendar-tabs role="tablist">
    <?php foreach ($calendarTabs as $months => $label): ?>
      <button type="button" class="btn-secondary<?= $calendarMonths === $months ? ' active' : '' ?>" data-calendar-months="<?= $months ?>" role="tab" aria-selected="<?= $calendarMonths === $months ? 'true' : 'false' ?>"><?= \App\View::e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="calendar-loading" data-calendar-loading hidden>
    <span class="spinner" aria-hidden="true"></span> Chargement…
  </div>
  <div data-calendar-body>
    <?php require BASE_PATH . '/files/views/partials/calendar-body.php'; ?>
  </div>
</div>
