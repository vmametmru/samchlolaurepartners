<?php declare(strict_types=1);
/**
 * Create/edit an "Offre Complète": general information, then the three
 * blocks (Vol, Hébergement, Activités) described on the public page.
 */
$package = $package ?? null;
$isAdmin = !empty($isAdmin);
$basePath = (string) ($basePath ?? '/partner/offres');
$flights = $package['flights'] ?? [];
$activities = $package['activities'] ?? [];
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

    <h2 class="section-title">Activités</h2>
    <p class="muted">Une activité obligatoire est toujours incluse dans le prix ; sinon le client peut la cocher ou non.</p>
    <div data-package-rows="activities">
      <?php foreach ($activities as $index => $activity): ?>
        <div class="card card-body mt-16" data-package-row>
          <input type="hidden" name="activities[<?= (int) $index ?>][id]" value="<?= (int) $activity['id'] ?>">
          <input type="hidden" name="activities[<?= (int) $index ?>][photo_url]" value="<?= $e($activity['photo_url'] ?? '') ?>">
          <div class="form-grid cols-2">
            <label><span>Nom (FR) *</span><input class="input" type="text" name="activities[<?= (int) $index ?>][label]" value="<?= $e($activity['label']) ?>"></label>
            <label><span>Nom (EN)</span><input class="input" type="text" name="activities[<?= (int) $index ?>][label_en]" value="<?= $e($activity['label_en'] ?? '') ?>"></label>
            <label>
              <span>Type de prix</span>
              <select class="input" name="activities[<?= (int) $index ?>][price_mode]">
                <option value="per_person" <?= (string) $activity['price_mode'] === 'per_person' ? 'selected' : '' ?>>Par personne</option>
                <option value="per_group" <?= (string) $activity['price_mode'] === 'per_group' ? 'selected' : '' ?>>Par groupe</option>
              </select>
            </label>
            <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="activities[<?= (int) $index ?>][price]" value="<?= $e((string) $activity['price']) ?>"></label>
          </div>
          <label><span>Description (FR)</span><textarea class="input" name="activities[<?= (int) $index ?>][description]" rows="3"><?= $e($activity['description'] ?? '') ?></textarea></label>
          <label><span>Description (EN)</span><textarea class="input" name="activities[<?= (int) $index ?>][description_en]" rows="3"><?= $e($activity['description_en'] ?? '') ?></textarea></label>
          <label><span>Photo</span><input class="input" type="file" name="activities[<?= (int) $index ?>][photo]" accept="image/*"></label>
          <?php if (!empty($activity['photo_url'])): ?>
            <img src="<?= $e($activity['photo_url']) ?>" alt="" class="gallery-thumb" style="max-width:160px">
          <?php endif; ?>
          <label class="inline-check"><input type="checkbox" name="activities[<?= (int) $index ?>][is_mandatory]" value="1" <?= (int) $activity['is_mandatory'] === 1 ? 'checked' : '' ?>> Activité obligatoire (incluse dans le prix)</label>
          <button type="button" class="btn-secondary" data-package-row-remove>Supprimer cette activité</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-secondary mt-16" data-package-row-add="activities">Ajouter une activité</button>

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

<template data-package-template="activities">
  <div class="card card-body mt-16" data-package-row>
    <div class="form-grid cols-2">
      <label><span>Nom (FR) *</span><input class="input" type="text" name="activities[__INDEX__][label]"></label>
      <label><span>Nom (EN)</span><input class="input" type="text" name="activities[__INDEX__][label_en]"></label>
      <label>
        <span>Type de prix</span>
        <select class="input" name="activities[__INDEX__][price_mode]">
          <option value="per_person">Par personne</option>
          <option value="per_group">Par groupe</option>
        </select>
      </label>
      <label><span>Prix</span><input class="input" type="number" min="0" step="0.01" name="activities[__INDEX__][price]" value="0"></label>
    </div>
    <label><span>Description (FR)</span><textarea class="input" name="activities[__INDEX__][description]" rows="3"></textarea></label>
    <label><span>Description (EN)</span><textarea class="input" name="activities[__INDEX__][description_en]" rows="3"></textarea></label>
    <label><span>Photo</span><input class="input" type="file" name="activities[__INDEX__][photo]" accept="image/*"></label>
    <label class="inline-check"><input type="checkbox" name="activities[__INDEX__][is_mandatory]" value="1"> Activité obligatoire (incluse dans le prix)</label>
    <button type="button" class="btn-secondary" data-package-row-remove>Supprimer cette activité</button>
  </div>
</template>

<script>
  (function () {
    var form = document.getElementById('package-form');
    if (!form) { return; }
    // Row indexes only have to be unique within the submitted form: start
    // above the highest index already rendered server-side.
    var nextIndex = <?= (int) (max(count($flights), count($activities)) + 1) ?>;

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
