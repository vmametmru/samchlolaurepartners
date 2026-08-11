<?php declare(strict_types=1); ?>
<div class="stack-sm" data-phone-input>
  <span>Téléphone (avec indicatif pays)</span>
  <div class="form-grid cols-phone">
    <input class="input" type="text" placeholder="Indicatif (ex: +230)" data-phone-dial-code>
    <input class="input" type="tel" data-phone-number placeholder="Numéro (avec région)" required>
  </div>
  <input type="hidden" name="client_phone" data-phone-combined>
</div>
