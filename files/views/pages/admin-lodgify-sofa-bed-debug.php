<?php declare(strict_types=1);
use App\View;
/** @var array $debug */
$debug = $debug ?? [];
?>
<section class="container section-lg narrow-wide">
  <div class="section-header">
    <div>
      <h1>Débogage canapé-lit — bien #<?= (int) ($debug['property_id'] ?? 0) ?></h1>
      <p>Réponses brutes de l'API Lodgify (v1) utilisées pour détecter le canapé-lit, et le nombre calculé à partir de chacune.</p>
    </div>
    <a class="btn-primary" href="/admin/lodgify-properties">← Retour</a>
  </div>

  <div class="stack-md">
    <div class="card card-body stack-sm">
      <h2>GET /v1/properties/{id}</h2>
      <p>Canapés-lits détectés : <strong><?= (int) ($debug['v1_property_sofa_count'] ?? 0) ?></strong></p>
      <?php if (!empty($debug['v1_property_error'])): ?>
        <p style="color:var(--red)">Erreur : <?= View::e((string) $debug['v1_property_error']) ?></p>
      <?php else: ?>
        <pre style="max-height:400px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['v1_property'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>

    <div class="card card-body stack-sm">
      <h2>GET /v1/rooms?propertyId={id}</h2>
      <p>Canapés-lits détectés : <strong><?= (int) ($debug['v1_rooms_sofa_count'] ?? 0) ?></strong></p>
      <?php if (!empty($debug['v1_rooms_error'])): ?>
        <p style="color:var(--red)">Erreur : <?= View::e((string) $debug['v1_rooms_error']) ?></p>
      <?php else: ?>
        <pre style="max-height:400px;overflow:auto;white-space:pre-wrap;"><?= View::e(json_encode($debug['v1_rooms'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</section>
