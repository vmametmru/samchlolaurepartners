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
<style>
.manual-col { background: #fffbe6; }
.manual-col input[type="number"] { width: 6rem; }
</style>
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
    <form method="post" action="/admin/lodgify-properties/manual">
    <div class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Photo</th>
            <th>Titre</th>
            <th>Capacité max</th>
            <th>Nb de lits</th>
            <th class="manual-col" title="Colonne manuelle — non synchronisée depuis Lodgify">Canapé lit ✎</th>
            <th class="manual-col" title="Colonne manuelle — non synchronisée depuis Lodgify">Min personnes (tarif de base) ✎</th>
            <th>Frais de nettoyage</th>
            <th class="manual-col" title="Colonne manuelle — non synchronisée depuis Lodgify">Frais pers. suppl. / nuit ✎</th>
            <th>Coordonnées GPS</th>
            <th>Statut de la fiche</th>
            <th>Statut Prix</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $propertyId = (int) ($row['id'] ?? 0);
            $photo = $row['images'][0]['url'] ?? 'https://via.placeholder.com/56x40?text=%20';
            $hasCoords = $row['latitude'] !== null && $row['longitude'] !== null;
            $sofaBedVal = $row['sofa_bed_count'] !== null ? (string) (int) $row['sofa_bed_count'] : '';
            $minPeopleVal = $row['min_people'] !== null ? (string) (int) $row['min_people'] : '';
            $extraFeeVal = $row['extra_person_fee'] !== null ? number_format((float) $row['extra_person_fee'], 2, '.', '') : '';
          ?>
            <tr>
              <td><img class="lodgify-thumb" src="<?= \App\View::e($photo) ?>" alt="<?= \App\View::e((string) $row['name']) ?>"></td>
              <td><?= \App\View::e((string) $row['name']) ?></td>
              <td><?= (int) $row['max_guests'] ?> pers.</td>
              <td><?= (int) $row['bedrooms'] ?></td>
              <td class="manual-col">
                <input class="input" type="number" min="0" step="1"
                  name="manual[<?= $propertyId ?>][sofa_bed_count]"
                  value="<?= \App\View::e($sofaBedVal) ?>"
                  placeholder="—">
              </td>
              <td class="manual-col">
                <input class="input" type="number" min="1" step="1"
                  name="manual[<?= $propertyId ?>][min_people]"
                  value="<?= \App\View::e($minPeopleVal) ?>"
                  placeholder="—">
              </td>
              <td>
                <?php if (isset($row['cleaning_fee']) && $row['cleaning_fee'] !== null): ?>
                  <?= \App\View::e(number_format((float) $row['cleaning_fee'], 0)) ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="manual-col">
                <input class="input" type="number" min="0" step="0.01"
                  name="manual[<?= $propertyId ?>][extra_person_fee]"
                  value="<?= \App\View::e($extraFeeVal) ?>"
                  placeholder="—">
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
    <div style="margin-top:1rem">
      <p class="muted" style="margin-bottom:.5rem">Les colonnes surlignées (✎) sont saisies manuellement et ne sont pas synchronisées depuis Lodgify.</p>
      <button class="btn-primary" type="submit">Sauvegarder les colonnes manuelles</button>
    </div>
    </form>
  <?php endif; ?>
</section>
