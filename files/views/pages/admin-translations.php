<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Traductions</h1>
  <p class="muted">Lodgify ne traduit jamais automatiquement vos contenus : il ne renvoie que ce qui a été saisi dans son back-office pour chaque langue. Pour les biens sans texte français configuré côté Lodgify, saisissez ici une traduction manuelle (ou utilisez « Suggérer » pour obtenir une proposition automatique à relire avant de sauvegarder).</p>
  <?php if (($rows ?? []) === []): ?>
    <p class="empty-state">Aucun bien Lodgify trouvé.</p>
  <?php endif; ?>
  <?php foreach (($rows ?? []) as $row): ?>
    <div class="card card-body stack-md">
      <h2 class="section-title"><?= \App\View::e($row['name']) ?> <span class="muted">#<?= (int) $row['id'] ?></span></h2>
      <?php foreach ($row['fields'] as $fieldName => $field): ?>
        <?php
          $fieldLabel = $fieldName === 'name' ? 'Nom' : ($fieldName === 'amenities' ? 'Équipements' : 'Description');
          $sourceId = 'translation-source-' . $row['id'] . '-' . $fieldName;
          $targetId = 'translation-fr-' . $row['id'] . '-' . $fieldName;
          $hasLodgifyFr = trim($field['lodgify_fr']) !== '';
          $placeholder = $fieldName === 'amenities'
            ? 'Laissez vide pour utiliser les équipements Lodgify (un par ligne : "Catégorie: équipement 1, équipement 2")'
            : ($hasLodgifyFr ? 'Laissez vide pour utiliser la traduction Lodgify' : 'Laissez vide pour utiliser le texte anglais');
        ?>
        <div class="stack-sm">
          <h3><?= \App\View::e($fieldLabel) ?><?php if ($hasLodgifyFr): ?> <span class="badge badge-fresh">Traduit dans Lodgify</span><?php else: ?> <span class="badge badge-stale">Absent de Lodgify</span><?php endif; ?></h3>
          <label><span>Anglais (Lodgify)</span><textarea class="input" id="<?= \App\View::e($sourceId) ?>" rows="6" readonly><?= \App\View::e(\App\View::plainTextWithLineBreaks($field['default'])) ?></textarea></label>
          <?php if ($hasLodgifyFr): ?>
            <label><span>Français (Lodgify)</span><textarea class="input" rows="6" readonly><?= \App\View::e(\App\View::plainTextWithLineBreaks($field['lodgify_fr'])) ?></textarea></label>
          <?php endif; ?>
          <form method="post" action="/admin/translations/save" class="stack-sm">
            <input type="hidden" name="property_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="field" value="<?= \App\View::e($fieldName) ?>">
            <label><span>Français (traduction manuelle)</span><textarea class="input" id="<?= \App\View::e($targetId) ?>" name="text" rows="6" placeholder="<?= \App\View::e($placeholder) ?>"><?= \App\View::e($field['manual_fr']) ?></textarea></label>
            <div class="button-row">
              <button class="btn-secondary" type="button" data-suggest-translation="#<?= \App\View::e($targetId) ?>" data-suggest-source="#<?= \App\View::e($sourceId) ?>">Suggérer</button>
              <button class="btn-primary" type="submit">Sauvegarder</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>
