<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Frais &amp; Taxes</h1>
  <div class="card card-body stack-md">
    <h2 class="section-title">Taxe touristique</h2>
    <form method="post" action="/admin/fees/tourist-tax" class="stack-md">
      <label><span>Tarif par personne / nuit (€)</span><input class="input" type="number" step="0.01" name="per_person_per_night" value="<?= \App\View::e((string) ($tax['per_person_per_night'] ?? 0)) ?>"></label>
      <label class="inline-check"><input type="checkbox" name="applies_to_foreigners_only" <?= !empty($tax['applies_to_foreigners_only']) ? 'checked' : '' ?>> Applicable aux étrangers uniquement (les Mauriciens sont exonérés)</label>
      <label class="inline-check"><input type="checkbox" name="applies_to_children" <?= !empty($tax['applies_to_children']) ? 'checked' : '' ?>> Applicable aux enfants de moins de 12 ans</label>
      <p class="muted">Rappel : les enfants de moins de 3 ans sont toujours gratuits et les enfants de moins de 12 ans sont exonérés de la taxe touristique pour les étrangers. Pour les résidents mauriciens, le séjour est gratuit de taxe.</p>
      <button class="btn-primary" type="submit">Sauvegarder</button>
    </form>
  </div>
  <div class="card card-body stack-md">
    <h2 class="section-title">Frais de nettoyage</h2>
    <?php if ($cleaningFees === []): ?>
      <p class="muted">Aucun frais configuré. Utilisez l'API PUT /api/fees/cleaning/:propertyId pour en ajouter.</p>
    <?php else: ?>
      <table class="table"><thead><tr><th>Hébergement</th><th>Tarif (€/pers/nuit)</th></tr></thead><tbody><?php foreach ($cleaningFees as $fee): ?><tr><td><?= \App\View::e($fee['property_id'] ?? 'Par défaut') ?></td><td><?= \App\View::e((string) $fee['per_person_per_night']) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </div>
</section>
