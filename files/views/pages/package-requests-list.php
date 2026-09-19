<?php declare(strict_types=1);
/**
 * "Demandes d'Offres Complètes" log, shared by the partner
 * (/partner/offres/demandes) and admin (/admin/offres/demandes, every
 * partner's requests) screens. Mirrors partner-reservations.php's status
 * filter checkboxes and packages-list.php's sibling per-row delete forms
 * (never nested inside the filter form).
 */
$isAdmin = !empty($isAdmin);
$basePath = (string) ($basePath ?? '/partner/offres/demandes');
$currentUrl = $_SERVER['REQUEST_URI'] ?? $basePath;
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
$showPartnerColumn = !empty($showPartnerColumn);
$colCount = 5 + ($showPartnerColumn ? 1 : 0);
?>
<section class="container section-lg">
  <div class="property-detail-header">
    <div>
      <h1>Demandes d'Offres Complètes</h1>
      <p class="muted">Historique des demandes soumises depuis vos offres, avec les demandes d'hébergement qu'elles ont générées.</p>
    </div>
    <a class="btn-secondary" href="<?= \App\View::e($isAdmin ? '/admin/offres' : '/partner/offres') ?>">← Retour aux offres</a>
  </div>

  <?php if (!$isAdmin && !empty($canForceChildVisibility)): ?>
    <form method="post" action="/partner/offres/force-child-visibility" class="card card-body mt-16">
      <label class="filter-checkbox">
        <input type="checkbox" name="enabled" value="1" <?= !empty($forceChildVisibility) ? 'checked' : '' ?> onchange="this.form.submit()">
        Afficher mes demandes d'offres sur les pages de mes comptes enfants
      </label>
    </form>
  <?php endif; ?>

  <form method="get" action="<?= \App\View::e($basePath) ?>" class="filter-tabs filter-tabs-checkboxes mt-16">
    <?php foreach ($statusOptions as $value => $label): ?>
      <label class="filter-checkbox">
        <input type="checkbox" name="status[]" value="<?= \App\View::e($value) ?>" <?= in_array($value, $selectedStatuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
        <?= \App\View::e($label) ?>
      </label>
    <?php endforeach; ?>
    <?php if ($isAdmin): ?>
      <select class="input" name="partner_id" onchange="this.form.submit()">
        <option value="0">Tous les partenaires</option>
        <?php foreach ($partners as $partnerOption): ?>
          <option value="<?= (int) $partnerOption['id'] ?>" <?= (int) $partnerOption['id'] === (int) $selectedPartnerId ? 'selected' : '' ?>>
            <?= \App\View::e((string) $partnerOption['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <noscript><button type="submit" class="btn btn-sm">Filtrer</button></noscript>
  </form>

  <div class="card overflow-hidden mt-16">
    <table class="table">
      <thead>
        <tr>
          <th>Offre</th>
          <?php if ($showPartnerColumn): ?><th>Partenaire</th><?php endif; ?>
          <th>Client</th>
          <th>Demandes liées</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($requests === []): ?>
          <tr><td colspan="<?= $colCount ?>" class="empty-row">Aucune demande pour le moment</td></tr>
        <?php else: foreach ($requests as $request): $reqId = (int) $request['id']; $status = (string) $request['status']; ?>
          <tr>
            <td><a class="text-link" href="<?= \App\View::e($basePath) ?>/<?= $reqId ?>"><?= \App\View::e((string) $request['package_title']) ?></a></td>
            <?php if ($showPartnerColumn): ?><td><?= \App\View::e((string) ($request['partner_name'] ?? '')) ?></td><?php endif; ?>
            <td><?= \App\View::e((string) ($request['client_name'] ?? '—')) ?><br><small class="muted"><?= \App\View::e((string) ($request['client_email'] ?? '')) ?></small></td>
            <td><?= (int) $request['request_count'] ?></td>
            <td><span class="badge badge-<?= \App\View::e($badgeClasses[$status] ?? 'pending') ?>"><?= \App\View::e($statusOptions[$status] ?? $status) ?></span></td>
            <td class="reservation-actions">
              <a class="icon-btn" title="Voir" href="<?= \App\View::e($basePath) ?>/<?= $reqId ?>">👁️</a>
              <button type="submit" class="icon-btn icon-btn-danger" title="Effacer" form="package-request-delete-<?= $reqId ?>" onclick="return confirm('Effacer définitivement cette demande ?');">🗑️</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($requests as $request): $reqId = (int) $request['id']; ?>
    <form id="package-request-delete-<?= $reqId ?>" method="post" action="<?= \App\View::e($basePath) ?>/<?= $reqId ?>/delete" hidden></form>
  <?php endforeach; ?>
</section>
