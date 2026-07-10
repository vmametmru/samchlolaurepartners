<?php declare(strict_types=1);
// The calendar always shows the next 12 months so the visitor never has to
// switch tabs; it is rendered server-side (no AJAX refresh needed anymore).
$calendarMonths = 12;
$calendarStart = isset($calendarStart) && $calendarStart !== ''
    ? (string) $calendarStart
    : (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$propertyId = (int) ($property['id'] ?? 0);
?>
<div class="calendar-widget" data-calendar-widget data-property-id="<?= $propertyId ?>">
  <div data-calendar-body>
    <?php require BASE_PATH . '/files/views/partials/calendar-body.php'; ?>
  </div>
</div>
