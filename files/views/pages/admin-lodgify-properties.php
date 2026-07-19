<?php declare(strict_types=1);
/** @var array<int, array> $rows */
$rows = $rows ?? [];

/**
 * Formats a cache freshness timestamp + badge for display: "fresh" (green)
 * means the fiche/price has already been synced at least once, "stale"
 * (red) means it has never been synced yet. Unlike price (auto-refreshed
 * every 30 min), the fiche is only ever refreshed by an explicit manual
 * "Resynchroniser" click — it never expires/refreshes on its own.
 */
$statusBadge = static function (?\DateTimeImmutable $updatedAt, bool $fresh): string {
    if ($updatedAt === null) {
        return '<span class="badge badge-stale">Jamais synchronisé</span>';
    }
    $label = $updatedAt->format('d/m/Y H:i');
    $badgeClass = $fresh ? 'badge-fresh' : 'badge-stale';
    $badgeLabel = $fresh ? 'À jour' : 'À rafraîchir';
    return '<span class="badge ' . $badgeClass . '">' . $badgeLabel . '</span><br><span class="muted" style="font-size:.78rem">' . \App\View::e($label) . '</span>';
};
?>
<section class="container section-lg">
  <div class="section-header">
    <h1>Biens Lodgify</h1>
    <a class="btn-primary" href="/admin/sync">Resynchroniser</a>
  </div>
  <p class="subtitle">
    Statut de la fiche (photos, description, capacité…) : synchronisée manuellement uniquement, via le bouton « Resynchroniser » ci-dessus.
    Statut Prix : rafraîchi automatiquement toutes les 30 minutes.
    Les disponibilités ne sont jamais mises en cache : elles sont interrogées en direct auprès de Lodgify à chaque recherche.
  </p>
  <?php if ($rows === []): ?>
    <p class="empty-state">Aucun bien Lodgify disponible pour le moment.</p>
  <?php else: ?>
    <div class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Photo</th>
            <th>Titre</th>
            <th>Capacité max</th>
            <th>Nb de lits</th>
            <th>Canapé lit</th>
            <th>Min personnes (tarif de base)</th>
            <th>Frais de nettoyage</th>
            <th>Frais pers. suppl. / nuit</th>
            <th>Coordonnées GPS</th>
            <th>Statut de la fiche</th>
            <th>Statut Prix</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $photo = $row['images'][0]['url'] ?? 'https://via.placeholder.com/56x40?text=%20';
            $hasCoords = $row['latitude'] !== null && $row['longitude'] !== null;
          ?>
            <tr>
              <td><img class="lodgify-thumb" src="<?= \App\View::e($photo) ?>" alt="<?= \App\View::e((string) $row['name']) ?>"></td>
              <td><?= \App\View::e((string) $row['name']) ?></td>
              <td><?= (int) $row['max_guests'] ?> pers.</td>
              <td><?= (int) $row['bedrooms'] ?></td>
              <td>
                <?php $sofaBedCount = (int) ($row['sofa_bed_count'] ?? 0); ?>
                <?php if ($sofaBedCount > 0): ?>
                  Oui (<?= $sofaBedCount ?>)
                <?php else: ?>
                  <span class="muted">Non</span>
                <?php endif; ?>
                <br><a href="/admin/lodgify-properties/<?= (int) ($row['id'] ?? 0) ?>/sofa-bed-debug" style="font-size:.78rem">Voir données brutes</a>
              </td>
              <td>
                <?php if (isset($row['min_people']) && $row['min_people'] !== null): ?>
                  <?= (int) $row['min_people'] ?> pers.
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (isset($row['cleaning_fee']) && $row['cleaning_fee'] !== null): ?>
                  <?= \App\View::e(number_format((float) $row['cleaning_fee'], 0)) ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (isset($row['extra_person_fee']) && $row['extra_person_fee'] !== null): ?>
                  <?= \App\View::e(number_format((float) $row['extra_person_fee'], 2)) ?> / nuit
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($hasCoords): ?>
                  <?= \App\View::e(number_format((float) $row['latitude'], 5)) ?>, <?= \App\View::e(number_format((float) $row['longitude'], 5)) ?>
                <?php else: ?>
                  <span class="badge badge-stale">Absentes</span>
                <?php endif; ?>
              </td>
              <td><?= $statusBadge($row['fiche_updated_at'], $row['fiche_fresh']) ?></td>
              <td>
                <?= $statusBadge($row['price_updated_at'], $row['price_fresh']) ?>
                <?php if (!empty($row['sample_price'])): ?>
                  <br><span class="muted" style="font-size:.78rem">≈ <?= \App\View::e(number_format((float) $row['sample_price'], 0)) ?> <?= \App\View::e((string) $row['currency']) ?> / nuit</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
