<?php declare(strict_types=1);
$currentUrl = $_SERVER['REQUEST_URI'] ?? '/admin/reservations';
?>
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
  <?php foreach ($reservations as $reservation): $rid = (int) $reservation['id']; ?>
    <form method="post" action="/admin/reservations/<?= $rid ?>/reopen" id="admin-reservation-reopen-form-<?= $rid ?>" class="inline-form">
      <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
    </form>
    <form method="post" action="/admin/reservations/<?= $rid ?>/cancel" id="admin-reservation-cancel-form-<?= $rid ?>" class="inline-form">
      <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
    </form>
    <form method="post" action="/admin/reservations/<?= $rid ?>/confirm" id="admin-reservation-confirm-form-<?= $rid ?>" class="inline-form">
      <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
    </form>
    <form method="post" action="/admin/reservations/<?= $rid ?>/delete" id="admin-reservation-delete-form-<?= $rid ?>" class="inline-form" onsubmit="return confirm('Effacer définitivement cette réservation ?');">
      <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
    </form>
  <?php endforeach; ?>
  <form method="post" action="/admin/reservations/delete-batch" id="admin-reservations-batch-form" onsubmit="return confirm('Effacer définitivement les réservations sélectionnées ?');">
    <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
    <div class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th><input type="checkbox" id="admin-reservations-select-all"></th>
            <th>Partenaire</th><th>Client</th><th>Hébergement</th><th>Dates</th><th>Voyageurs</th><th>Statut</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($reservations === []): ?>
          <tr><td colspan="8" class="empty-row">Aucune demande</td></tr>
        <?php else: foreach ($reservations as $reservation): $status2 = (string) $reservation['status']; $rid = (int) $reservation['id']; ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= $rid ?>" class="admin-reservations-row-checkbox"></td>
            <td><?= \App\View::e($reservation['partner_name'] ?? '—') ?></td>
            <td><?= \App\View::e($reservation['client_name']) ?><br><small><?= \App\View::e($reservation['client_email']) ?></small></td>
            <td><?= \App\View::e($reservation['property_name'] ?: '—') ?></td>
            <td><?= \App\View::e($reservation['checkin_date']) ?> → <?= \App\View::e($reservation['checkout_date']) ?></td>
            <td><?= (int) $reservation['adults'] ?>A · <?= (int) $reservation['children'] ?>E</td>
            <td><span class="badge badge-<?= \App\View::e($status2) ?>"><?= \App\View::e(\App\View::badgeLabel($status2)) ?></span></td>
            <td class="reservation-actions">
              <?php if ($status2 === 'confirmed'): ?>
                <button type="submit" form="admin-reservation-reopen-form-<?= $rid ?>" class="icon-btn" title="En attente">⏸️</button>
                <button type="submit" form="admin-reservation-cancel-form-<?= $rid ?>" class="icon-btn icon-btn-danger" title="Annuler">❌</button>
              <?php elseif ($status2 === 'pending'): ?>
                <button type="submit" form="admin-reservation-confirm-form-<?= $rid ?>" class="icon-btn" title="Confirmer">▶️</button>
                <button type="submit" form="admin-reservation-cancel-form-<?= $rid ?>" class="icon-btn icon-btn-danger" title="Annuler">❌</button>
              <?php else: ?>
                <button type="submit" form="admin-reservation-confirm-form-<?= $rid ?>" class="icon-btn" title="Confirmer">▶️</button>
                <button type="submit" form="admin-reservation-reopen-form-<?= $rid ?>" class="icon-btn" title="En attente">⏸️</button>
              <?php endif; ?>
              <button type="submit" form="admin-reservation-delete-form-<?= $rid ?>" class="icon-btn icon-btn-danger" title="Effacer">🗑️</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($reservations !== []): ?>
      <div class="button-row">
        <button type="submit" class="btn-secondary danger">🗑️ Effacer la sélection</button>
      </div>
    <?php endif; ?>
  </form>
</section>
<script>
  (function () {
    var selectAll = document.getElementById('admin-reservations-select-all');
    if (!selectAll) { return; }
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.admin-reservations-row-checkbox').forEach(function (checkbox) {
        checkbox.checked = selectAll.checked;
      });
    });
  })();
</script>
