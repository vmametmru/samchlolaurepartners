<?php declare(strict_types=1);
use App\View;
?>
<section class="container section-lg narrow-wide">
  <div class="section-header">
    <div><h1>Diagnostic système</h1><p>Vérifie le fichier .env, la base de données et l'API Lodgify.</p></div>
    <a class="btn-primary" href="/admin/diagnostic?run=1">▶ Lancer le diagnostic</a>
  </div>

  <?php if (!is_array($data)): ?>
  <div class="card card-body" style="color:var(--muted);text-align:center;padding:2rem;">
    Cliquez sur « Lancer le diagnostic » pour démarrer les vérifications.
  </div>
  <?php else: ?>

  <?php
  $envFileOk      = !empty($data['env_file']['exists']) && !empty($data['env_file']['readable']);
  $envFileExists  = !empty($data['env_file']['exists']);
  $envFileReadable = !empty($data['env_file']['readable']);
  $envVarsOk = !empty($data['env']['LODGIFY_API_KEY_SET']) && $data['env']['DB_HOST'] !== '(non défini)';
  $dbOk      = !empty($data['database']['ok']);
  $connOk    = !empty($data['lodgify_connectivity']['ok']);
  $lodgifyOk = !empty($data['lodgify']['ok']);
  $cacheOk   = !empty($data['cache']['properties_cached']);

  function diagStatus(bool $ok, string $labelOk = '✓ OK', string $labelErr = '✕ Erreur'): string {
    $color = $ok ? 'var(--green)' : 'var(--red)';
    $label = $ok ? $labelOk : $labelErr;
    return '<strong style="color:' . $color . '">' . htmlspecialchars($label, ENT_QUOTES) . '</strong>';
  }
  ?>

  <div class="stack-md">

    <!-- ─── Étape 1 : Fichier .env ─────────────────────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">1</span>
        <span class="diag-step-title">Fichier <code>.env</code></span>
        <?php
          if ($envFileOk) {
              echo diagStatus(true, '✓ Trouvé &amp; lisible');
          } elseif ($envFileExists) {
              echo diagStatus(false, '', '✕ Non lisible par PHP');
          } else {
              echo diagStatus(false, '', '✕ Absent');
          }
        ?>
      </div>
      <div class="diag-row"><span>Chemin attendu</span><code><?= View::e($data['env_file']['path']) ?></code></div>
      <div class="diag-row"><span>BASE_PATH (racine app)</span><code><?= View::e((string) $data['env_file']['base_path']) ?></code></div>
      <div class="diag-row"><span>BASE_PATH réel (realpath)</span><code><?= View::e((string) $data['env_file']['base_realpath']) ?></code></div>
      <?php if (!empty($data['env_file']['real_path']) && $data['env_file']['real_path'] !== $data['env_file']['path']): ?>
      <div class="diag-row"><span>Chemin réel du fichier</span><code><?= View::e((string) $data['env_file']['real_path']) ?></code></div>
      <?php endif; ?>
      <div class="diag-row"><span>Fichier présent</span><code><?= $envFileExists ? 'Oui' : 'Non' ?></code></div>
      <?php if (!empty($data['env_file']['is_link'])): ?>
      <div class="diag-row"><span>Lien symbolique</span><code>Oui</code></div>
      <?php endif; ?>
      <div class="diag-row"><span>Lisible par PHP</span><code><?= $envFileReadable ? 'Oui' : 'Non' ?></code></div>
      <?php if (!is_null($data['env_file']['perms'] ?? null)): ?>
      <div class="diag-row"><span>Permissions</span><code><?= View::e((string) $data['env_file']['perms']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($data['env_file']['owner'] ?? null)): ?>
      <div class="diag-row"><span>Propriétaire du fichier</span><code><?= View::e((string) $data['env_file']['owner']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($data['env_file']['php_user'] ?? null)): ?>
      <div class="diag-row"><span>Utilisateur PHP</span><code><?= View::e((string) $data['env_file']['php_user']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($data['env_file']['open_basedir'] ?? null)): ?>
      <div class="diag-row"><span>open_basedir (PHP)</span><code><?= View::e((string) $data['env_file']['open_basedir']) ?></code></div>
      <?php endif; ?>
      <?php if (!$envFileOk): ?>
      <?php if ($envFileExists && !$envFileReadable): ?>
      <pre class="message-box" style="color:var(--red)">Le fichier .env existe mais PHP ne peut pas le lire.
Vérifiez les permissions (chmod 640 .env) et que le propriétaire correspond à l'utilisateur PHP.</pre>
      <?php else: ?>
      <pre class="message-box" style="color:var(--red)">Le fichier .env est introuvable à ce chemin.
Créez-le (cp .env.example .env) et remplissez les valeurs.
Si le fichier existe réellement à cet emplacement sur le serveur, vérifiez :
- que "BASE_PATH réel (realpath)" ci-dessus correspond bien au dossier contenant votre .env (attention aux liens symboliques / docroot alias) ;
- que "open_basedir" (si défini) inclut bien ce chemin ;
- que le déploiement le plus récent a bien été appliqué sur le serveur (ce diagnostic reflète le code actuellement déployé).</pre>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 2 : Variables d'environnement ───────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">2</span>
        <span class="diag-step-title">Variables d'environnement</span>
        <?= diagStatus($envVarsOk) ?>
      </div>
      <div class="diag-row"><span>APP_ENV</span><code><?= View::e($data['env']['APP_ENV']) ?></code></div>
      <div class="diag-row"><span>PORT</span><code><?= View::e($data['env']['PORT']) ?></code></div>
      <div class="diag-row"><span>CORS_ORIGIN</span><code><?= View::e($data['env']['CORS_ORIGIN']) ?></code></div>
      <div class="diag-row"><span>DB_HOST</span><code><?= View::e($data['env']['DB_HOST']) ?></code></div>
      <div class="diag-row"><span>DB_NAME</span><code><?= View::e($data['env']['DB_NAME']) ?></code></div>
      <div class="diag-row"><span>LODGIFY_BASE_URL</span><code><?= View::e($data['env']['LODGIFY_BASE_URL']) ?></code></div>
      <div class="diag-row">
        <span>LODGIFY_API_KEY</span>
        <code><?= !empty($data['env']['LODGIFY_API_KEY_SET']) ? 'Configurée ✓' : 'Non configurée ✕' ?></code>
      </div>
    </div>

    <!-- ─── Étape 3 : Base de données ────────────────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">3</span>
        <span class="diag-step-title">Base de données</span>
        <?= diagStatus($dbOk) ?>
      </div>
      <div class="diag-row"><span>Hôte</span><code><?= View::e($data['env']['DB_HOST']) ?></code></div>
      <div class="diag-row"><span>Base</span><code><?= View::e($data['env']['DB_NAME']) ?></code></div>
      <?php if (!$dbOk && !empty($data['database']['error'])): ?>
      <pre class="message-box" style="color:var(--red)"><?= View::e((string) $data['database']['error']) ?></pre>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 4 : Connectivité réseau Lodgify ─────────────── -->
    <?php $conn = $data['lodgify_connectivity'] ?? []; ?>
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">4</span>
        <span class="diag-step-title">Connectivité réseau Lodgify</span>
        <?= diagStatus($connOk) ?>
      </div>
      <div class="diag-row"><span>URL de base</span><code><?= View::e((string) ($conn['base_url'] ?? '—')) ?></code></div>
      <div class="diag-row"><span>Clé API configurée</span><code><?= !empty($conn['api_key_set']) ? 'Oui' : 'Non' ?></code></div>
      <?php if (!empty($conn['resolved_ip'])): ?>
      <div class="diag-row"><span>IP résolue (DNS)</span><code><?= View::e((string) $conn['resolved_ip']) ?></code></div>
      <?php endif; ?>
      <?php if (isset($conn['http_status']) && $conn['http_status'] !== null): ?>
      <div class="diag-row"><span>Statut HTTP</span><code><?= View::e((string) $conn['http_status']) ?></code></div>
      <?php endif; ?>
      <?php if (isset($conn['duration_ms']) && $conn['duration_ms'] !== null): ?>
      <div class="diag-row"><span>Temps de réponse</span><code><?= View::e((string) $conn['duration_ms']) ?> ms</code></div>
      <?php endif; ?>
      <?php if (!empty($conn['error'])): ?>
      <pre class="message-box" style="color:var(--red)"><?= View::e((string) $conn['error']) ?></pre>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 5 : API Lodgify (récupération des biens) ──────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">5</span>
        <span class="diag-step-title">API Lodgify — récupération des biens</span>
        <?= diagStatus($lodgifyOk) ?>
      </div>
      <?php if ($lodgifyOk): ?>
      <div class="diag-row"><span>Propriétés trouvées</span><code><?= View::e((string) $data['lodgify']['property_count']) ?></code></div>
      <?php else: ?>
      <pre class="message-box" style="color:var(--red)"><?= View::e($data['lodgify']['error'] ?? 'Erreur inconnue') ?></pre>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 6 : Cache ────────────────────────────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">6</span>
        <span class="diag-step-title">Cache local</span>
        <?= diagStatus($cacheOk, '✓ En cache', 'ℹ Vide') ?>
      </div>
      <div class="diag-row"><span>Propriétés en cache</span><code><?= $cacheOk ? 'Oui' : 'Non' ?></code></div>
      <?php if (!$cacheOk && $lodgifyOk): ?>
      <p class="muted" style="margin:0;font-size:.9rem;">Le cache sera rempli automatiquement après la première requête.</p>
      <?php endif; ?>
      <?php if (empty($data['env']['LODGIFY_API_KEY_SET']) && $cacheOk): ?>
      <pre class="message-box">Des données Lodgify peuvent encore s'afficher via le cache local, même sans clé API active.</pre>
      <?php endif; ?>
    </div>

    <!-- ─── Réponse brute / mappée (debug avancé) ─────────────── -->
    <?php if (!empty($data['lodgify']['raw_sample'])): ?>
    <div class="card card-body stack-sm">
      <h2 class="section-title">Réponse brute Lodgify (1 bien, champs réels)</h2>
      <pre class="message-box"><?= View::e(json_encode($data['lodgify']['raw_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
    <?php endif; ?>
    <?php if (!empty($data['lodgify']['mapped_sample'])): ?>
    <div class="card card-body stack-sm">
      <h2 class="section-title">Données mappées (après transformation)</h2>
      <pre class="message-box"><?= View::e(json_encode($data['lodgify']['mapped_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
    <?php endif; ?>

  </div><!-- /.stack-md -->
  <?php endif; ?>
</section>
