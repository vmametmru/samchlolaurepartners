<?php declare(strict_types=1);
$currentUrl = $_SERVER['REQUEST_URI'] ?? '/partner/reservations';
$statusOptions = ['pending' => 'Ouverte (En attente)', 'confirmed' => 'Confirmée', 'cancelled' => 'Annulée'];
?>
<section class="container section-lg">
  <h1>Demandes de réservation</h1>
  <form method="get" action="/partner/reservations" class="filter-tabs filter-tabs-checkboxes">
    <?php foreach ($statusOptions as $value => $label): ?>
      <label class="filter-checkbox">
        <input type="checkbox" name="status[]" value="<?= \App\View::e($value) ?>" <?= in_array($value, $selectedStatuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
        <?= \App\View::e($label) ?>
      </label>
    <?php endforeach; ?>
    <noscript><button type="submit" class="btn btn-sm">Filtrer</button></noscript>
  </form>
  <div class="card overflow-hidden">
    <table class="table">
      <thead><tr><th>Client</th><th>Hébergement</th><th>Dates</th><th>Voyageurs</th><th>Statut</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if ($reservations === []): ?>
        <tr><td colspan="6" class="empty-row">Aucune demande</td></tr>
      <?php else: foreach ($reservations as $reservation): $status = (string) $reservation['status']; $rid = (int) $reservation['id']; ?>
        <tr>
          <td><a class="text-link" href="/partner/reservations/<?= $rid ?>"><?= \App\View::e($reservation['client_name']) ?></a><br><small><?= \App\View::e($reservation['client_email']) ?></small></td>
          <td><?= \App\View::e($reservation['property_name'] ?: '—') ?></td>
          <td><?= \App\View::e($reservation['checkin_date']) ?> → <?= \App\View::e($reservation['checkout_date']) ?></td>
          <td><?= (int) $reservation['adults'] ?>A · <?= (int) $reservation['children'] ?>E</td>
          <td><span class="badge badge-<?= \App\View::e($status) ?>"><?= \App\View::e(\App\View::badgeLabel($status)) ?></span></td>
          <td class="reservation-actions">
            <a class="icon-btn" title="Ouvrir le devis" href="/partner/reservations/<?= $rid ?>">👁️</a>
            <?php if (!empty($reservation['public_url'])): ?>
              <a class="icon-btn" title="Partager sur WhatsApp" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=<?= urlencode('Voici le lien de votre demande de réservation : ' . $reservation['public_url']) ?>">🔗</a>
            <?php endif; ?>
            <?php if ($status === 'confirmed'): ?>
              <form method="post" action="/partner/reservations/<?= $rid ?>/reopen" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn" title="En attente">⏸️</button>
              </form>
              <form method="post" action="/partner/reservations/<?= $rid ?>/cancel" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn icon-btn-danger" title="Annuler">❌</button>
              </form>
            <?php elseif ($status === 'pending'): ?>
              <form method="post" action="/partner/reservations/<?= $rid ?>/confirm" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn" title="Confirmer">▶️</button>
              </form>
              <form method="post" action="/partner/reservations/<?= $rid ?>/cancel" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn icon-btn-danger" title="Annuler">❌</button>
              </form>
            <?php else: ?>
              <form method="post" action="/partner/reservations/<?= $rid ?>/confirm" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn" title="Confirmer">▶️</button>
              </form>
              <form method="post" action="/partner/reservations/<?= $rid ?>/reopen" class="inline-form">
                <input type="hidden" name="redirect_to" value="<?= \App\View::e($currentUrl) ?>">
                <button type="submit" class="icon-btn" title="En attente">⏸️</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
