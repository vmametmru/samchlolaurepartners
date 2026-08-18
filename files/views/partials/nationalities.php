<?php declare(strict_types=1); $nationalities = ['Mauricienne','Française','Britannique','Allemande','Italienne','Espagnole','Belge','Suisse','Américaine','Australienne','Autre'];
// Optional pre-fill for an existing set of guests (e.g. editing an already
// submitted reservation request): $initialGuests = [{type, nationality}, ...].
// When every guest shares the same non-empty nationality, "Même nationalité
// pour tous" starts checked with that value pre-selected; otherwise each
// guest's own dropdown starts pre-filled (see initNationalities() in
// assets/js/app.js, which reads data-initial-guests once on first render).
$initialGuests = $initialGuests ?? [];
$initialNationalities = array_values(array_filter(array_map(
    static fn (array $guest): string => trim((string) ($guest['nationality'] ?? '')),
    $initialGuests
)));
$initialSame = $initialGuests !== [] && count(array_unique($initialNationalities)) === 1 && count($initialNationalities) === count($initialGuests);
$initialUniform = $initialSame ? $initialNationalities[0] : '';
?>
<div class="stack-sm" data-nationalities data-initial-guests="<?= \App\View::e(json_encode($initialGuests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
  <div class="inline-check"><input type="checkbox" id="sameNat" data-same-nationality<?= $initialSame ? ' checked' : '' ?>><label for="sameNat">Même nationalité pour tous</label></div>
  <div class="nationality-single<?= $initialSame ? '' : ' hidden' ?>" data-nationality-single>
    <label><span>Nationalité (tous)</span>
      <select class="input" data-uniform-nationality>
        <option value="">Sélectionner...</option>
        <?php foreach ($nationalities as $nationality): ?><option value="<?= \App\View::e($nationality) ?>"<?= $nationality === $initialUniform ? ' selected' : '' ?>><?= \App\View::e($nationality) ?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="nationality-list" data-nationality-list></div>
  <template data-nationality-template>
    <label class="nationality-entry">
      <span></span>
      <select class="input" data-nationality-select>
        <option value="">Sélectionner...</option>
        <?php foreach ($nationalities as $nationality): ?><option value="<?= \App\View::e($nationality) ?>"><?= \App\View::e($nationality) ?></option><?php endforeach; ?>
      </select>
    </label>
  </template>
</div>
