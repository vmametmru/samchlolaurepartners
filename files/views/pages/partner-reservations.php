<?php declare(strict_types=1); ?>
<section class="container section-lg">
  <h1>Demandes de réservation</h1>
  <div class="filter-tabs">
    <?php foreach (['all' => 'Toutes', 'pending' => 'En attente', 'confirmed' => 'Confirmées', 'cancelled' => 'Annulées'] as $value => $label): ?>
      <a class="<?= $filter === $value ? 'active' : '' ?>" href="/partner/reservations?filter=<?= \App\View::e($value) ?>"><?= \App\View::e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card overflow-hidden">
    <table class="table">
      <thead><tr><th>Client</th><th>Hébergement</th><th>Dates</th><th>Voyageurs</th><th>Statut</th></tr></thead>
      <tbody>
      <?php if ($reservations === []): ?>
        <tr><td colspan="5" class="empty-row">Aucune demande</td></tr>
      <?php else: foreach ($reservations as $reservation): ?>
        <tr>
          <td><a class="text-link" href="/partner/reservations/<?= (int) $reservation['id'] ?>"><?= \App\View::e($reservation['client_name']) ?></a><br><small><?= \App\View::e($reservation['client_email']) ?></small></td>
          <td><?= \App\View::e($reservation['property_name'] ?: '—') ?></td>
          <td><?= \App\View::e($reservation['checkin_date']) ?> → <?= \App\View::e($reservation['checkout_date']) ?></td>
          <td><?= (int) $reservation['adults'] ?>A · <?= (int) $reservation['children'] ?>E</td>
          <td><span class="badge badge-<?= \App\View::e($reservation['status']) ?>"><?= \App\View::e(\App\View::badgeLabel((string) $reservation['status'])) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
