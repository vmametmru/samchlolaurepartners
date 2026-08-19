<?php declare(strict_types=1);
// Optional prefill (e.g. the partner "Modifier la demande" form): the stored
// "+230 5 4221025" value is split back into its dial-code / local-number
// cells. $phoneValue is unset on the public booking forms, which start empty.
$phoneValue = trim((string) ($phoneValue ?? ''));
$phoneDialCode = '';
$phoneNumber = $phoneValue;
if ($phoneValue !== '' && preg_match('/^(\+\d{1,4})[\s.-]*(.*)$/', $phoneValue, $phoneParts) === 1) {
    $phoneDialCode = $phoneParts[1];
    $phoneNumber = trim($phoneParts[2]);
}
?>
<div class="stack-sm" data-phone-input>
  <span>Téléphone (avec indicatif pays)</span>
  <div class="form-grid cols-phone">
    <input class="input" type="text" placeholder="ex: +230" value="<?= \App\View::e($phoneDialCode) ?>" data-phone-dial-code>
    <input class="input" type="tel" data-phone-number placeholder="ex: 5 4221025" value="<?= \App\View::e($phoneNumber) ?>" required>
  </div>
  <input type="hidden" name="client_phone" data-phone-combined value="<?= \App\View::e($phoneValue) ?>">
</div>
<?php $phoneValue = null; ?>
