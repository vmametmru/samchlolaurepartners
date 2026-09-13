<?php declare(strict_types=1);
/**
 * Create/edit an "Offre Complète": general information, then the blocks
 * (Hébergement, Vol, Transport, Activités, Restauration) shown step by step
 * on the public page, in that same order.
 */
$package = $package ?? null;
$isAdmin = !empty($isAdmin);
$basePath = (string) ($basePath ?? '/partner/offres');
$flights = $package['flights'] ?? [];
$transports = $package['transports'] ?? [];
$activities = $package['activities'] ?? [];
$meals = $package['meals'] ?? [];
// "Transport", "Activités" and "Restauration" share exactly the same row
// shape, so all three are rendered by the same loop, in the order of the
// public page's steps. Transport only appears once migration 066 created its
// table, Restauration once migration 065 did.
$extraBlocks = [];
if (!empty($transportsEnabled)) {
    $extraBlocks['transports'] = [
        'title' => 'Transport',
        'hint' => 'Transferts, location de voiture… : une option obligatoire est toujours incluse dans le prix, sinon le client peut la cocher ou non.',
        'addLabel' => 'Ajouter une option de transport',
        'removeLabel' => 'Supprimer cette option',
        'mandatoryLabel' => 'Transport obligatoire (inclus dans le prix)',
    ];
}
$extraBlocks += [
    'activities' => [
        'title' => 'Activités',
        'hint' => 'Une activité obligatoire est toujours incluse dans le prix ; sinon le client peut la cocher ou non.',
        'addLabel' => 'Ajouter une activité',
        'removeLabel' => 'Supprimer cette activité',
        'mandatoryLabel' => 'Activité obligatoire (incluse dans le prix)',
    ],
];
if (!empty($mealsEnabled)) {
    $extraBlocks['meals'] = [
        'title' => 'Restauration',
        'hint' => 'Formules repas proposées avec l\'offre : une formule obligatoire est toujours incluse dans le prix, sinon le client peut la cocher ou non.',
        'addLabel' => 'Ajouter une formule',
        'removeLabel' => 'Supprimer cette formule',
        'mandatoryLabel' => 'Formule obligatoire (incluse dans le prix)',
    ];
}
$selectedPropertyIds = array_map('intval', $package['property_ids'] ?? []);
$allProperties = $package === null ? true : (int) $package['all_properties'] === 1;
$stockMode = (string) ($package['stock_mode'] ?? 'none');
$e = static fn (mixed $value): string => \App\View::e($value);
?>
<section class="container section-lg">
  <h1><?= $package === null ? 'Nouvelle offre' : 'Modifier l\'offre' ?></h1>
  <?php if ($isAdmin): ?>
    <p class="muted">Partenaire : <strong><?= $e($partnerName) ?></strong></p>
  <?php endif; ?>

  <form method="post" action="<?= $e($basePath) ?>" enctype="multipart/form-data" class="mt-16" id="package-form">
    <?php if ($package !== null): ?><input type="hidden" name="id" value="<?= (int) $package['id'] ?>"><?php endif; ?>
    <?php if ($isAdmin): ?><input type="hidden" name="partner_id" value="<?= (int) $partnerId ?>"><?php endif; ?>

    <h2 class="section-title">Informations générales</h2>
    <div class="form-grid cols-2">
      <label><span>Titre (FR) *</span><input class="input" type="text" name="title" required value="<?= $e($package['title'] ?? '') ?>"></label>
      <label><span>Titre (EN)</span><input class="input" type="text" name="title_en" value="<?= $e($package['title_en'] ?? '') ?>"></label>
    </div>
    <label><span>Description générale (FR)</span><textarea class="input" name="description" rows="5"><?= $e($package['description'] ?? '') ?></textarea></label>
    <label><span>Description générale (EN)</span><textarea class="input" name="description_en" rows="5"><?= $e($package['description_en'] ?? '') ?></textarea></label>
    <label>
      <span>Photo principale</span>
      <input class="input" type="file" name="photo" accept="image/*">
    </label>
    <?php if (!empty($package['photo_url'])): ?>
      <img src="<?= $e($package['photo_url']) ?>" alt="" class="gallery-thumb" style="max-width:220px">
    <?php endif; ?>

    <div class="form-grid cols-2">
      <label>
        <span>Statut</span>
        <select class="input" name="status">
          <option value="draft" <?= (string) ($package['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Brouillon (non visible)</option>
          <option value="active" <?= (string) ($package['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active (visible par les clients)</option>
        </select>
      </label>
      <label>
        <span>Date d'expiration (GMT+4, facultatif)</span>
        <input class="input" type="datetime-local" name="expires_at" value="<?= $e($expiresAtInput ?? '') ?>">
      </label>
      <label>
        <span>Limite de stock</span>
        <select class="input" name="stock_mode">
          <option value="none" <?= $stockMode === 'none' ? 'selected' : '' ?>>Aucune limite</option>
          <option value="bookings" <?= $stockMode === 'bookings' ? 'selected' : '' ?>>Limiter en nombre de réservations</option>
          <option value="persons" <?= $stockMode === 'persons' ? 'selected' : '' ?>>Limiter en nombre de personnes</option>
        </select>
      </label>
      <label>
        <span>Quantité maximum</span>
        <input class="input" type="number" min="0" step="1" name="stock_limit" value="<?= $e((string) ($package['stock_limit'] ?? '')) ?>">
      </label>
    </div>

    <h2 class="section-title">Vol</h2>
    <p class="muted">Ajoutez une ligne par option proposée au client (ex. classe économique, classe affaires).</p>
    <div data-package-rows="flights">
      <?php foreach ($flights as $index => $flight): ?>
        <div class="card card-body mt-16" data-package-row>
          <div class="form-grid cols-2">
            <label><span>Intitulé de l'option *</span><input class="input" type="text" name="flights[<?= (int) $index ?>][label]" value="<?= $e($flight['label']) ?>"></label>
            <label><span>Compagnie / ligne aérienne</span><input class="input" type="text" name="flights[<?= (int) $index ?>][airline]" value="<?= $e($flight['airline'] ?? '') ?>"></label>
            <label><span>Classe</span><input class="input" type="text" name="flights[<?= (int) $index ?>][cabin_class]" value="<?= $e($flight['cabin_class'] ?? '') ?>"></label>
            <label>
              <span>Type de prix</span>
              <select class="input" name="flights[<?= (int) $index ?>][price_mode]">
                <option value="per_person" <?= (string) $flight['price_mode'] === 'per_person' ? 'selected' : '' ?>>Par personne</option>
                <option value="per_group" <?= (string) $flight['price_mode'] === 'per_group' ? 'selected' : '' ?>>Par groupe</option>
              </select>
            </label>
            <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="flights[<?= (int) $index ?>][price]" value="<?= $e((string) $flight['price']) ?>"></label>
          </div>
          <label><span>Description</span><textarea class="input" name="flights[<?= (int) $index ?>][description]" rows="3"><?= $e($flight['description'] ?? '') ?></textarea></label>
          <label class="inline-check"><input type="radio" name="flight_default" value="<?= (int) $index ?>" <?= (int) $flight['is_default'] === 1 ? 'checked' : '' ?>> Option proposée par défaut</label>
          <button type="button" class="btn-secondary" data-package-row-remove>Supprimer cette option</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-secondary mt-16" data-package-row-add="flights">Ajouter une option de vol</button>

    <h2 class="section-title">Hébergement</h2>
    <label class="inline-check">
      <input type="checkbox" name="all_properties" value="1" <?= $allProperties ? 'checked' : '' ?> data-package-all-properties>
      Proposer tous mes biens
    </label>
    <div class="form-grid cols-2 mt-16" data-package-properties <?= $allProperties ? 'hidden' : '' ?>>
      <?php if ($properties === []): ?>
        <p class="muted">Aucun bien disponible en base locale pour le moment.</p>
      <?php else: foreach ($properties as $property): ?>
        <label class="inline-check">
          <input type="checkbox" name="property_ids[]" value="<?= (int) $property['id'] ?>" <?= in_array((int) $property['id'], $selectedPropertyIds, true) ? 'checked' : '' ?>>
          <?= $e($property['name']) ?>
        </label>
      <?php endforeach; endif; ?>
    </div>
    <p class="muted">Lors d'une recherche sur la page publique, seuls les biens réellement disponibles pour les dates et le nombre de personnes demandés sont affichés.</p>

<?php foreach ($extraBlocks as $blockKey => $block):
      $rows = ['transports' => $transports, 'meals' => $meals, 'activities' => $activities][$blockKey]; ?>
      <h2 class="section-title"><?= $e($block['title']) ?></h2>
      <p class="muted"><?= $e($block['hint']) ?></p>
      <div data-package-rows="<?= $e($blockKey) ?>">
        <?php foreach ($rows as $index => $row): $name = $blockKey . '[' . (int) $index . ']'; ?>
          <div class="card card-body mt-16" data-package-row>
            <input type="hidden" name="<?= $e($name) ?>[id]" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="<?= $e($name) ?>[photo_url]" value="<?= $e($row['photo_url'] ?? '') ?>">
            <div class="form-grid cols-2">
              <label><span>Nom (FR) *</span><input class="input" type="text" name="<?= $e($name) ?>[label]" value="<?= $e($row['label']) ?>"></label>
              <label><span>Nom (EN)</span><input class="input" type="text" name="<?= $e($name) ?>[label_en]" value="<?= $e($row['label_en'] ?? '') ?>"></label>
              <label>
                <span>Type de prix</span>
                <select class="input" name="<?= $e($name) ?>[price_mode]">
                  <option value="per_person" <?= (string) $row['price_mode'] === 'per_person' ? 'selected' : '' ?>>Par personne</option>
                  <option value="per_group" <?= (string) $row['price_mode'] === 'per_group' ? 'selected' : '' ?>>Par groupe</option>
                </select>
              </label>
              <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="<?= $e($name) ?>[price]" value="<?= $e((string) $row['price']) ?>"></label>
            </div>
            <label><span>Description (FR)</span><textarea class="input" name="<?= $e($name) ?>[description]" rows="3"><?= $e($row['description'] ?? '') ?></textarea></label>
            <label><span>Description (EN)</span><textarea class="input" name="<?= $e($name) ?>[description_en]" rows="3"><?= $e($row['description_en'] ?? '') ?></textarea></label>
            <label><span>Photo</span><input class="input" type="file" name="<?= $e($name) ?>[photo]" accept="image/*"></label>
            <?php if (!empty($row['photo_url'])): ?>
              <img src="<?= $e($row['photo_url']) ?>" alt="" class="gallery-thumb" style="max-width:160px">
            <?php endif; ?>
            <label class="inline-check"><input type="checkbox" name="<?= $e($name) ?>[is_mandatory]" value="1" <?= (int) $row['is_mandatory'] === 1 ? 'checked' : '' ?>> <?= $e($block['mandatoryLabel']) ?></label>
            <button type="button" class="btn-secondary" data-package-row-remove><?= $e($block['removeLabel']) ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-secondary mt-16" data-package-row-add="<?= $e($blockKey) ?>"><?= $e($block['addLabel']) ?></button>
    <?php endforeach; ?>

    <div class="button-row mt-16">
      <button class="btn-primary" type="submit">Sauvegarder</button>
      <a class="btn-secondary" href="<?= $e($basePath) ?>">Retour</a>
    </div>
  </form>
</section>

<template data-package-template="flights">
  <div class="card card-body mt-16" data-package-row>
    <div class="form-grid cols-2">
      <label><span>Intitulé de l'option *</span><input class="input" type="text" name="flights[__INDEX__][label]"></label>
      <label><span>Compagnie / ligne aérienne</span><input class="input" type="text" name="flights[__INDEX__][airline]"></label>
      <label><span>Classe</span><input class="input" type="text" name="flights[__INDEX__][cabin_class]"></label>
      <label>
        <span>Type de prix</span>
        <select class="input" name="flights[__INDEX__][price_mode]">
          <option value="per_person">Par personne</option>
          <option value="per_group">Par groupe</option>
        </select>
      </label>
      <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="flights[__INDEX__][price]" value="0"></label>
    </div>
    <label><span>Description</span><textarea class="input" name="flights[__INDEX__][description]" rows="3"></textarea></label>
    <label class="inline-check"><input type="radio" name="flight_default" value="__INDEX__"> Option proposée par défaut</label>
    <button type="button" class="btn-secondary" data-package-row-remove>Supprimer cette option</button>
  </div>
</template>

<?php foreach ($extraBlocks as $blockKey => $block): ?>
  <template data-package-template="<?= $e($blockKey) ?>">
    <div class="card card-body mt-16" data-package-row>
      <div class="form-grid cols-2">
        <label><span>Nom (FR) *</span><input class="input" type="text" name="<?= $e($blockKey) ?>[__INDEX__][label]"></label>
        <label><span>Nom (EN)</span><input class="input" type="text" name="<?= $e($blockKey) ?>[__INDEX__][label_en]"></label>
        <label>
          <span>Type de prix</span>
          <select class="input" name="<?= $e($blockKey) ?>[__INDEX__][price_mode]">
            <option value="per_person">Par personne</option>
            <option value="per_group">Par groupe</option>
          </select>
        </label>
        <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="<?= $e($blockKey) ?>[__INDEX__][price]" value="0"></label>
      </div>
      <label><span>Description (FR)</span><textarea class="input" name="<?= $e($blockKey) ?>[__INDEX__][description]" rows="3"></textarea></label>
      <label><span>Description (EN)</span><textarea class="input" name="<?= $e($blockKey) ?>[__INDEX__][description_en]" rows="3"></textarea></label>
      <label><span>Photo</span><input class="input" type="file" name="<?= $e($blockKey) ?>[__INDEX__][photo]" accept="image/*"></label>
      <label class="inline-check"><input type="checkbox" name="<?= $e($blockKey) ?>[__INDEX__][is_mandatory]" value="1"> <?= $e($block['mandatoryLabel']) ?></label>
      <button type="button" class="btn-secondary" data-package-row-remove><?= $e($block['removeLabel']) ?></button>
    </div>
  </template>
<?php endforeach; ?>

<script>
  (function () {
    var form = document.getElementById('package-form');
    if (!form) { return; }
    // Row indexes only have to be unique within the submitted form: start
    // above the highest index already rendered server-side.
    var nextIndex = <?= (int) (max(count($flights), count($transports), count($activities), count($meals)) + 1) ?>;

    function addRow(kind) {
      var template = document.querySelector('[data-package-template="' + kind + '"]');
      var container = document.querySelector('[data-package-rows="' + kind + '"]');
      if (!template || !container) { return; }
      var html = template.innerHTML.split('__INDEX__').join(String(nextIndex++));
      var wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      var row = wrapper.firstElementChild;
      if (row) { container.appendChild(row); }
    }

    document.querySelectorAll('[data-package-row-add]').forEach(function (button) {
      button.addEventListener('click', function () { addRow(button.getAttribute('data-package-row-add')); });
    });

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (target && target.matches && target.matches('[data-package-row-remove]')) {
        var row = target.closest('[data-package-row]');
        if (row) { row.remove(); }
      }
    });

    var allProperties = document.querySelector('[data-package-all-properties]');
    var propertyList = document.querySelector('[data-package-properties]');
    if (allProperties && propertyList) {
      allProperties.addEventListener('change', function () {
        propertyList.hidden = allProperties.checked;
      });
    }
  })();
</script>
