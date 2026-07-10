<?php declare(strict_types=1);
$dialCodes = [
    '+230' => 'Maurice (+230)',
    '+33' => 'France (+33)',
    '+44' => 'Royaume-Uni (+44)',
    '+49' => 'Allemagne (+49)',
    '+39' => 'Italie (+39)',
    '+34' => 'Espagne (+34)',
    '+32' => 'Belgique (+32)',
    '+41' => 'Suisse (+41)',
    '+1' => 'États-Unis / Canada (+1)',
    '+61' => 'Australie (+61)',
    '+27' => 'Afrique du Sud (+27)',
    '+91' => 'Inde (+91)',
    '+86' => 'Chine (+86)',
];
?>
<div class="stack-sm" data-phone-input>
  <span>Téléphone (avec indicatif pays) *</span>
  <div class="form-grid cols-2">
    <select class="input" data-phone-dial-code required>
      <option value="">Indicatif...</option>
      <?php foreach ($dialCodes as $code => $label): ?><option value="<?= \App\View::e($code) ?>"><?= \App\View::e($label) ?></option><?php endforeach; ?>
    </select>
    <input class="input" type="tel" data-phone-number placeholder="Numéro (avec région)" required>
  </div>
  <input type="hidden" name="client_phone" data-phone-combined>
</div>
