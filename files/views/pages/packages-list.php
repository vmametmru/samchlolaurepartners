<?php declare(strict_types=1);
/**
 * Offer management table, shared by the partner (/partner/offres) and the
 * admin (/admin/offres, every partner's offers) screens.
 *
 * Note: the per-row "Supprimer" forms are deliberately siblings of the
 * filter form, never nested inside it — a nested <form> would silently
 * detach everything after it from the outer form.
 */
$isAdmin = !empty($isAdmin);
$basePath = (string) ($basePath ?? '/partner/offres');
$statusLabels = ['draft' => 'Brouillon', 'active' => 'Active'];
$stockLabels = [
    'none' => 'Illimité',
    'bookings' => 'réservation(s)',
    'persons' => 'personne(s)',
];
?>
<section class="container section-lg">
  <div class="property-detail-header">
    <div>
      <h1>Offres Complètes</h1>
      <p class="muted">Vol, hébergement et activités regroupés en une seule offre présentée à vos clients.</p>
    </div>
    <a class="btn-primary" href="<?= \App\View::e($basePath) ?>/nouvelle<?= $isAdmin && (int) $selectedPartnerId > 0 ? '?partner_id=' . (int) $selectedPartnerId : '' ?>">Nouvelle offre</a>
  </div>

  <?php if ($isAdmin): ?>
    <form method="get" action="/admin/offres" class="form-grid cols-2 mt-16">
      <label>
        <span>Partenaire</span>
        <select class="input" name="partner_id" onchange="this.form.submit()">
          <option value="0">Tous les partenaires</option>
          <?php foreach ($partners as $partnerOption): ?>
            <option value="<?= (int) $partnerOption['id'] ?>" <?= (int) $partnerOption['id'] === (int) $selectedPartnerId ? 'selected' : '' ?>>
              <?= \App\View::e((string) $partnerOption['name']) ?><?= (int) ($partnerOption['packages_visible'] ?? 0) === 1 ? '' : ' (option désactivée)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button class="btn-secondary" type="submit">Filtrer</button></noscript>
    </form>
  <?php endif; ?>

  <div class="card overflow-hidden mt-16">
    <table class="table">
      <thead>
        <tr>
          <th>Offre</th>
          <?php if ($isAdmin): ?><th>Partenaire</th><?php endif; ?>
          <th>Statut</th>
          <th>Expiration</th>
          <th>Stock</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($packages === []): ?>
          <tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" class="empty-row">Aucune offre pour le moment</td></tr>
        <?php else: foreach ($packages as $package): $pid = (int) $package['id'];
          // The public page resolves its partner from the "Code Partenaire"
          // cookie, never from the offer id: on the admin list (offers of
          // every partner) the preview link must therefore carry the offer's
          // own partner code, otherwise it 404s or previews another tenant.
          $previewCode = trim((string) ($package['partner_code'] ?? ''));
          $previewUrl = '/offres/' . $pid . ($previewCode !== '' ? '?partner=' . rawurlencode($previewCode) : '');
        ?>
          <tr>
            <td>
              <?php if (!empty($package['photo_url'])): ?>
                <img src="<?= \App\View::e((string) $package['photo_url']) ?>" alt="" class="brand-logo" loading="lazy">
              <?php endif; ?>
              <a class="text-link" href="<?= \App\View::e($basePath) ?>/<?= $pid ?>"><?= \App\View::e((string) $package['title']) ?></a>
            </td>
            <?php if ($isAdmin): ?><td><?= \App\View::e((string) ($package['partner_name'] ?? '')) ?></td><?php endif; ?>
            <td>
              <span class="badge badge-<?= (string) $package['status'] === 'active' ? 'confirmed' : 'pending' ?>">
                <?= \App\View::e($statusLabels[(string) $package['status']] ?? (string) $package['status']) ?>
              </span>
              <?php if (!empty($package['is_expired'])): ?><br><small class="muted">Expirée</small><?php endif; ?>
            </td>
            <td><?= \App\View::e((string) ($package['expires_label'] ?? '—')) ?></td>
            <td>
              <?php if ((string) $package['stock_mode'] === 'none' || $package['stock_limit'] === null): ?>
                Illimité
              <?php else: ?>
                <?= (int) $package['remaining_stock'] ?> / <?= (int) $package['stock_limit'] ?>
                <?= \App\View::e($stockLabels[(string) $package['stock_mode']] ?? '') ?>
              <?php endif; ?>
            </td>
            <td class="reservation-actions">
              <a class="icon-btn" title="Modifier" href="<?= \App\View::e($basePath) ?>/<?= $pid ?>">✏️</a>
              <a class="icon-btn" title="Voir la page publique" href="<?= \App\View::e($previewUrl) ?>" target="_blank" rel="noopener">👁️</a>
              <button type="submit" class="icon-btn icon-btn-danger" title="Supprimer" form="package-delete-<?= $pid ?>" onclick="return confirm('Supprimer définitivement cette offre ?');">🗑️</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($packages as $package): ?>
    <form id="package-delete-<?= (int) $package['id'] ?>" method="post" action="<?= \App\View::e($basePath) ?>/<?= (int) $package['id'] ?>/delete" hidden></form>
  <?php endforeach; ?>
</section>
