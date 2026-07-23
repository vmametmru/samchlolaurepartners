<?php declare(strict_types=1); ?>
<section class="container section-lg">
  <h1>Réservations (tous les partenaires)</h1>
  <form method="get" action="/admin/reservations" class="form-grid cols-2 compact-grid filter-form">
    <label>
      <span>Partenaire</span>
      <select class="input" name="partner_id">
        <option value="0">Tous les partenaires</option>
        <?php foreach ($partners as $partnerRow): ?>
          <option value="<?= (int) $partnerRow['id'] ?>" <?= $partnerId === (int) $partnerRow['id'] ? 'selected' : '' ?>><?= \App\View::e($partnerRow['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Statut</span>
      <select class="input" name="status">
        <?php foreach (['all' => 'Tous', 'pending' => 'En attente', 'confirmed' => 'Confirmées', 'cancelled' => 'Annulées'] as $value => $label): ?>
          <option value="<?= \App\View::e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= \App\View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="button-row"><button class="btn-primary" type="submit">Filtrer</button></div>
  </form>
  <div class="card overflow-hidden">
    <table class="table">
      <thead><tr><th>Partenaire</th><th>Client</th><th>Hébergement</th><th>Dates</th><th>Voyageurs</th><th>Statut</th></tr></thead>
      <tbody>
      <?php if ($reservations === []): ?>
        <tr><td colspan="6" class="empty-row">Aucune demande</td></tr>
      <?php else: foreach ($reservations as $reservation): ?>
        <tr>
          <td><?= \App\View::e($reservation['partner_name'] ?? '—') ?></td>
          <td><?= \App\View::e($reservation['client_name']) ?><br><small><?= \App\View::e($reservation['client_email']) ?></small></td>
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
