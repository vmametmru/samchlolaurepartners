<?php declare(strict_types=1);
use App\View;
/** @var array $debug */
$debug = $debug ?? [];
?>
<section class="container section-lg narrow-wide">
  <div class="section-header">
    <div>
      <h1>Diagnostic tarifs Lodgify — bien #<?= (int) ($debug['property_id'] ?? 0) ?></h1>
      <p>JSON bruts des endpoints de tarifs et valeurs extraites pour savoir si le problème vient du fetch API ou du mapping local.</p>
    </div>
    <a class="btn-primary" href="/admin/lodgify-properties">← Retour</a>
  </div>

  <div class="card card-body stack-sm">
    <h2>Résumé des champs clés</h2>
    <pre style="max-height:320px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['extracted'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

  <div class="stack-md">
    <div class="card card-body stack-sm">
      <h2>GET /v2/rates/settings/properties/{id}</h2>
      <?php if (!empty($debug['v2_rate_settings_property_error'])): ?>
        <p style="color:var(--red)">Erreur : <?= View::e((string) $debug['v2_rate_settings_property_error']) ?></p>
      <?php else: ?>
        <pre style="max-height:420px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['v2_rate_settings_property'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>

    <div class="card card-body stack-sm">
      <h2>GET /v2/rates/settings?houseId={id} (fallback legacy)</h2>
      <?php if (!empty($debug['v2_rate_settings_legacy_error'])): ?>
        <p style="color:var(--red)">Erreur : <?= View::e((string) $debug['v2_rate_settings_legacy_error']) ?></p>
      <?php else: ?>
        <pre style="max-height:420px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['v2_rate_settings_legacy'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>

    <div class="card card-body stack-sm">
      <h2>GET /v2/rates/properties/{id}</h2>
      <?php if (!empty($debug['v2_rates_properties_error'])): ?>
        <p style="color:var(--red)">Erreur : <?= View::e((string) $debug['v2_rates_properties_error']) ?></p>
      <?php else: ?>
        <pre style="max-height:420px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['v2_rates_properties'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</section>
