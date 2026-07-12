<?php declare(strict_types=1);
// Lists every active reservation (real Lodgify booking / closed period) that
// overlaps the calendar window, so it can be checked against the calendar. Each
// row shows the arrival date, departure date and number of nights; 1-night
// reservations (shown as pale yellow in the calendar) are flagged.
$reservations = $reservations ?? [];
$frenchDate = static function (string $isoDate): string {
    try {
        return (new DateTimeImmutable($isoDate))->format('d/m/Y');
    } catch (\Throwable) {
        return $isoDate;
    }
};
?>
<div class="reservations-table-wrap">
  <h3>Réservations actives (<?= count($reservations) ?>)</h3>
  <?php if ($reservations === []): ?>
    <p class="muted">Aucune réservation active sur les 12 prochains mois.</p>
  <?php else: ?>
    <table class="reservations-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Arrivée</th>
          <th>Départ</th>
          <th>Nuits</th>
          <?php $hasRoomNames = false; foreach ($reservations as $r) { if (($r['room_type_name'] ?? '') !== '') { $hasRoomNames = true; break; } } ?>
          <?php if ($hasRoomNames): ?><th>Chambre</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reservations as $index => $reservation): ?>
          <tr<?= !empty($reservation['single_night']) ? ' class="single-night"' : '' ?>>
            <td><?= $index + 1 ?></td>
            <td><?= \App\View::e($frenchDate((string) $reservation['start'])) ?></td>
            <td><?= \App\View::e($frenchDate((string) $reservation['end'])) ?></td>
            <td>
              <?= (int) $reservation['nights'] ?>
              <?php if (!empty($reservation['single_night'])): ?><span class="badge-single-night" title="Réservation d'1 nuit">1 nuit</span><?php endif; ?>
            </td>
            <?php if ($hasRoomNames): ?><td><?= \App\View::e((string) ($reservation['room_type_name'] ?? '')) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
