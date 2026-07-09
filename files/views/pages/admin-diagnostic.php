<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <div class="section-header"><div><h1>Diagnostic système</h1><p>Vérifie la connectivité backend, la base de données et l'API Lodgify.</p></div><a class="btn-primary" href="/admin/diagnostic?run=1">▶ Lancer le diagnostic</a></div>
  <div class="card card-body stack-md">
    <h2 class="section-title">Configuration frontend</h2>
    <div class="diag-row"><span>Webroot public</span><code>/</code></div>
    <div class="diag-row"><span>API login</span><code>/api/auth/login</code></div>
    <div class="diag-row"><span>API hébergements</span><code>/api/lodgify/properties</code></div>
  </div>
  <?php if (is_array($data)): ?>
    <div class="card card-body stack-md">
      <h2 class="section-title">Connectivité Lodgify (vérification directe)</h2>
      <?php $conn = $data['lodgify_connectivity'] ?? null; ?>
      <div class="diag-row"><span>Statut</span><strong><?= !empty($conn['ok']) ? '✓ OK' : '✕ Erreur' ?></strong></div>
      <div class="diag-row"><span>URL de base</span><code><?= \App\View::e($conn['base_url'] ?? '') ?></code></div>
      <div class="diag-row"><span>Clé API configurée</span><code><?= !empty($conn['api_key_set']) ? 'Oui' : 'Non' ?></code></div>
      <?php if (!empty($conn['resolved_ip'])): ?><div class="diag-row"><span>IP résolue (DNS)</span><code><?= \App\View::e((string) $conn['resolved_ip']) ?></code></div><?php endif; ?>
      <?php if (isset($conn['http_status']) && $conn['http_status'] !== null): ?><div class="diag-row"><span>Statut HTTP</span><code><?= \App\View::e((string) $conn['http_status']) ?></code></div><?php endif; ?>
      <?php if (isset($conn['duration_ms']) && $conn['duration_ms'] !== null): ?><div class="diag-row"><span>Temps de réponse</span><code><?= \App\View::e((string) $conn['duration_ms']) ?> ms</code></div><?php endif; ?>
      <?php if (!empty($conn['error'])): ?><pre class="message-box"><?= \App\View::e((string) $conn['error']) ?></pre><?php endif; ?>
    </div>
    <div class="card card-body stack-md">
      <h2 class="section-title">Base de données</h2>
      <div class="diag-row"><span>Statut</span><strong><?= !empty($data['database']['ok']) ? '✓ OK' : '✕ Erreur' ?></strong></div>
      <?php if (empty($data['database']['ok']) && !empty($data['database']['error'])): ?>
        <pre class="message-box"><?= \App\View::e((string) $data['database']['error']) ?></pre>
      <?php endif; ?>
      <div class="diag-row"><span>Hôte</span><code><?= \App\View::e($data['env']['DB_HOST']) ?></code></div>
      <div class="diag-row"><span>Base</span><code><?= \App\View::e($data['env']['DB_NAME']) ?></code></div>
    </div>
    <div class="card card-body stack-md">
      <h2 class="section-title">API Lodgify</h2>
      <div class="diag-row"><span>Statut</span><strong><?= !empty($data['lodgify']['ok']) ? '✓ OK' : '✕ Erreur' ?></strong></div>
      <div class="diag-row"><span>URL de base</span><code><?= \App\View::e($data['env']['LODGIFY_BASE_URL']) ?></code></div>
      <div class="diag-row"><span>Clé API configurée</span><code><?= !empty($data['env']['LODGIFY_API_KEY_SET']) ? 'Oui' : 'Non' ?></code></div>
      <?php if (!empty($data['lodgify']['ok'])): ?><div class="diag-row"><span>Propriétés trouvées</span><code><?= \App\View::e((string) $data['lodgify']['property_count']) ?></code></div><?php else: ?><pre class="message-box"><?= \App\View::e($data['lodgify']['error'] ?? 'Erreur inconnue') ?></pre><?php endif; ?>
      <?php if (empty($data['env']['LODGIFY_API_KEY_SET']) && !empty($data['cache']['properties_cached'])): ?>
        <pre class="message-box">Des données Lodgify peuvent encore s'afficher via le cache local, même sans clé API active.</pre>
      <?php endif; ?>
    </div>
    <div class="card card-body stack-md">
      <h2 class="section-title">Cache</h2>
      <div class="diag-row"><span>Propriétés en cache</span><code><?= !empty($data['cache']['properties_cached']) ? 'Oui' : 'Non' ?></code></div>
    </div>
    <div class="card card-body stack-md">
      <h2 class="section-title">Environnement backend</h2>
      <div class="diag-row"><span>APP_ENV</span><code><?= \App\View::e($data['env']['NODE_ENV']) ?></code></div>
      <div class="diag-row"><span>PORT</span><code><?= \App\View::e($data['env']['PORT']) ?></code></div>
      <div class="diag-row"><span>CORS_ORIGIN</span><code><?= \App\View::e($data['env']['CORS_ORIGIN']) ?></code></div>
    </div>
    <?php if (!empty($data['lodgify']['raw_sample'])): ?>
      <div class="card card-body stack-md">
        <h2 class="section-title">Réponse brute Lodgify (1 bien, champs réels)</h2>
        <pre class="message-box"><?= \App\View::e(json_encode($data['lodgify']['raw_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      </div>
    <?php endif; ?>
    <?php if (!empty($data['lodgify']['mapped_sample'])): ?><div class="card card-body stack-md"><h2 class="section-title">Données mappées (après transformation)</h2><pre class="message-box"><?= \App\View::e(json_encode($data['lodgify']['mapped_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div><?php endif; ?>
  <?php endif; ?>
</section>
