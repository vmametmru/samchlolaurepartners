<?php declare(strict_types=1); $nationalities = ['Mauricienne','Française','Britannique','Allemande','Italienne','Espagnole','Belge','Suisse','Américaine','Australienne','Autre']; ?>
<div class="stack-sm" data-nationalities>
  <div class="inline-check"><input type="checkbox" id="sameNat" data-same-nationality><label for="sameNat">Même nationalité pour tous</label></div>
  <div class="nationality-single hidden" data-nationality-single>
    <label><span>Nationalité (tous)</span>
      <select class="input" data-uniform-nationality>
        <option value="">Sélectionner...</option>
        <?php foreach ($nationalities as $nationality): ?><option value="<?= \App\View::e($nationality) ?>"><?= \App\View::e($nationality) ?></option><?php endforeach; ?>
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
