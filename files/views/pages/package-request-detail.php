<?php declare(strict_types=1);
/**
 * Detail of a single "Demande d'Offre Complète" log entry: the offer, the
 * client info, its status (Ouverte/Validée/Annulée) and every accommodation
 * request (reservation_requests) it produced, each opening in its own
 * partner/admin reservation screen.
 */
$reqId = (int) $request['id'];
$status = (string) $request['status'];
$isAdmin = !empty($isAdmin);
$basePath = (string) ($basePath ?? '/partner/offres/demandes');
$reservationBasePath = (string) ($reservationBasePath ?? '/partner/reservations');
$statusOptions = [
    \App\PackageRequests::STATUS_OPEN => 'Ouverte',
    \App\PackageRequests::STATUS_VALIDATED => 'Validée',
    \App\PackageRequests::STATUS_CANCELLED => 'Annulée',
];
$badgeClasses = [
    \App\PackageRequests::STATUS_OPEN => 'pending',
    \App\PackageRequests::STATUS_VALIDATED => 'confirmed',
    \App\PackageRequests::STATUS_CANCELLED => 'cancelled',
];
?>
<section class="container section-lg narrow-wide">
  <a class="text-link" href="<?= \App\View::e($basePath) ?>">← Retour</a>
  <div class="section-header">
    <h1>Demande d'Offre Complète #<?= $reqId ?></h1>
    <span class="badge badge-<?= \App\View::e($badgeClasses[$status] ?? 'pending') ?>"><?= \App\View::e($statusOptions[$status] ?? $status) ?></span>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title">Informations</h2>
    <div class="form-grid cols-2 compact-grid">
      <div><span class="muted">Offre :</span> <strong><?= \App\View::e((string) $request['package_title']) ?></strong></div>
      <?php if ($isAdmin): ?><div><span class="muted">Partenaire :</span> <?= \App\View::e((string) ($request['partner_name'] ?? '')) ?></div><?php endif; ?>
      <div><span class="muted">Client :</span> <?= \App\View::e((string) ($request['client_name'] ?? '—')) ?></div>
      <div><span class="muted">Email :</span> <?= \App\View::e((string) ($request['client_email'] ?? '—')) ?></div>
      <div><span class="muted">Reçue le :</span> <?= \App\View::e((string) ($request['created_at'] ?? '—')) ?></div>
    </div>
    <div class="button-row">
      <?php foreach ($statusOptions as $value => $label): if ($value === $status) continue; ?>
        <form method="post" action="<?= \App\View::e($basePath) ?>/<?= $reqId ?>/status" class="inline-form">
          <input type="hidden" name="status" value="<?= \App\View::e($value) ?>">
          <button type="submit" class="btn-secondary">Marquer <?= \App\View::e($label) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title">Demande(s) d'hébergement rattachée(s)</h2>
    <?php if ($reservations === []): ?>
      <p class="muted">Aucune demande d'hébergement rattachée.</p>
    <?php else: ?>
      <div class="overflow-hidden">
        <table class="table">
          <thead><tr><th>Hébergement</th><th>Dates</th><th>Statut</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($reservations as $reservation): $rid = (int) $reservation['id']; $rstatus = (string) $reservation['status']; ?>
            <tr>
              <td><?= \App\View::e((string) ($reservation['property_name'] ?? '—')) ?></td>
              <td><?= \App\View::e((string) $reservation['checkin_date']) ?> → <?= \App\View::e((string) $reservation['checkout_date']) ?></td>
              <td><span class="badge badge-<?= \App\View::e($rstatus) ?>"><?= \App\View::e(\App\View::badgeLabel($rstatus)) ?></span></td>
              <td>
                <?php if (!$isAdmin): ?>
                  <a class="icon-btn" title="Ouvrir le devis" href="<?= \App\View::e($reservationBasePath) ?>/<?= $rid ?>">👁️</a>
                <?php else: ?>
                  <a class="icon-btn" title="Ouvrir dans les réservations" href="<?= \App\View::e($reservationBasePath) ?>?partner_id=<?= (int) $reservation['partner_id'] ?>">👁️</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
