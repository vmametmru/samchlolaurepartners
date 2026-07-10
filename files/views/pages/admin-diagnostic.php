<?php declare(strict_types=1);
use App\View;
?>
<section class="container section-lg narrow-wide">
  <div class="section-header">
    <div><h1>Diagnostic système</h1><p>Vérifie <code>db/config.php</code>, les paramètres en base (table <code>settings</code>), la base de données et l'API Lodgify.</p></div>
    <a class="btn-primary" href="/admin/diagnostic?run=1">▶ Lancer le diagnostic</a>
  </div>

  <?php if (!is_array($diagnostic)): ?>
  <div class="card card-body" style="color:var(--muted);text-align:center;padding:2rem;">
    Cliquez sur « Lancer le diagnostic » pour démarrer les vérifications.
  </div>
  <?php else: ?>

  <?php
  $configFileOk       = !empty($diagnostic['config_file']['exists']) && !empty($diagnostic['config_file']['readable']);
  $configFileExists   = !empty($diagnostic['config_file']['exists']);
  $configFileReadable = !empty($diagnostic['config_file']['readable']);
  $envVarsOk = !empty($diagnostic['env']['LODGIFY_API_KEY_SET']);
  $dbOk      = !empty($diagnostic['database']['ok']);
  $connOk    = !empty($diagnostic['lodgify_connectivity']['ok']);
  $lodgifyOk = !empty($diagnostic['lodgify']['ok']);
  $cacheOk   = !empty($diagnostic['cache']['properties_cached']);

  function diagStatus(bool $ok, string $labelOk = '✓ OK', string $labelErr = '✕ Erreur'): string {
    $color = $ok ? 'var(--green)' : 'var(--red)';
    $label = $ok ? $labelOk : $labelErr;
    return '<strong style="color:' . $color . '">' . htmlspecialchars($label, ENT_QUOTES) . '</strong>';
  }
  ?>

  <div class="stack-md">

    <!-- ─── Étape 1 : db/config.php (connexion base de données) ── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">1</span>
        <span class="diag-step-title">Fichier <code>db/config.php</code></span>
        <?php
          if ($configFileOk) {
              echo diagStatus(true, '✓ Trouvé &amp; lisible');
          } elseif ($configFileExists) {
              echo diagStatus(false, '', '✕ Non lisible par PHP');
          } else {
              echo diagStatus(false, '', '✕ Absent');
          }
        ?>
      </div>
      <div class="diag-row"><span>Chemin attendu</span><code><?= View::e((string) $diagnostic['config_file']['path']) ?></code></div>
      <div class="diag-row"><span>BASE_PATH (racine app)</span><code><?= View::e((string) $diagnostic['config_file']['base_path']) ?></code></div>
      <div class="diag-row"><span>BASE_PATH réel (realpath)</span><code><?= View::e((string) $diagnostic['config_file']['base_realpath']) ?></code></div>
      <?php if (!empty($diagnostic['config_file']['real_path']) && $diagnostic['config_file']['real_path'] !== $diagnostic['config_file']['path']): ?>
      <div class="diag-row"><span>Chemin réel du fichier</span><code><?= View::e((string) $diagnostic['config_file']['real_path']) ?></code></div>
      <?php endif; ?>
      <div class="diag-row"><span>Fichier présent</span><code><?= $configFileExists ? 'Oui' : 'Non' ?></code></div>
      <?php if (!empty($diagnostic['config_file']['is_link'])): ?>
      <div class="diag-row"><span>Lien symbolique</span><code>Oui</code></div>
      <?php endif; ?>
      <div class="diag-row"><span>Lisible par PHP</span><code><?= $configFileReadable ? 'Oui' : 'Non' ?></code></div>
      <?php if (!is_null($diagnostic['config_file']['perms'] ?? null)): ?>
      <div class="diag-row"><span>Permissions</span><code><?= View::e((string) $diagnostic['config_file']['perms']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($diagnostic['config_file']['owner'] ?? null)): ?>
      <div class="diag-row"><span>Propriétaire du fichier</span><code><?= View::e((string) $diagnostic['config_file']['owner']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($diagnostic['config_file']['php_user'] ?? null)): ?>
      <div class="diag-row"><span>Utilisateur PHP</span><code><?= View::e((string) $diagnostic['config_file']['php_user']) ?></code></div>
      <?php endif; ?>
      <?php if (!is_null($diagnostic['config_file']['open_basedir'] ?? null)): ?>
      <div class="diag-row"><span>open_basedir (PHP)</span><code><?= View::e((string) $diagnostic['config_file']['open_basedir']) ?></code></div>
      <?php endif; ?>
      <?php if (!empty($diagnostic['config_file']['document_root'])): ?>
      <div class="diag-row"><span>DOCUMENT_ROOT (serveur web)</span><code><?= View::e((string) $diagnostic['config_file']['document_root']) ?></code></div>
      <?php endif; ?>
      <?php if (!empty($diagnostic['config_file']['script_filename'])): ?>
      <div class="diag-row"><span>SCRIPT_FILENAME</span><code><?= View::e((string) $diagnostic['config_file']['script_filename']) ?></code></div>
      <?php endif; ?>
      <?php if (is_array($diagnostic['config_file']['db_dir_listing'] ?? null)): ?>
      <div class="diag-row"><span>Contenu du dossier <code>db/</code></span></div>
      <pre class="message-box"><?= View::e(implode("\n", $diagnostic['config_file']['db_dir_listing'])) ?></pre>
      <?php endif; ?>
      <?php if (!$configFileOk): ?>
      <?php if ($configFileExists && !$configFileReadable): ?>
      <pre class="message-box" style="color:var(--red)">Le fichier db/config.php existe mais PHP ne peut pas le lire.
Vérifiez les permissions et que le propriétaire correspond à l'utilisateur PHP.</pre>
      <?php else: ?>
      <pre class="message-box" style="color:var(--red)">Le fichier db/config.php est introuvable.
Copiez db/config.example.php vers db/config.php et renseignez vos identifiants MySQL réels
(hôte, port, nom de base, utilisateur, mot de passe). Ce fichier reste le seul élément de
configuration hors base de données : il faut bien pouvoir se connecter à MySQL avant de
pouvoir y lire quoi que ce soit d'autre.</pre>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 2 : Paramètres (table settings) ─────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">2</span>
        <span class="diag-step-title">Paramètres (table <code>settings</code>)</span>
        <?= diagStatus($envVarsOk) ?>
      </div>
      <div class="diag-row"><span>APP_ENV</span><code><?= View::e($diagnostic['env']['APP_ENV']) ?></code></div>
      <div class="diag-row"><span>PORT</span><code><?= View::e($diagnostic['env']['PORT']) ?></code></div>
      <div class="diag-row"><span>CORS_ORIGIN</span><code><?= View::e($diagnostic['env']['CORS_ORIGIN']) ?></code></div>
      <div class="diag-row"><span>LODGIFY_BASE_URL</span><code><?= View::e($diagnostic['env']['LODGIFY_BASE_URL']) ?></code></div>
      <div class="diag-row">
        <span>LODGIFY_API_KEY</span>
        <code><?= !empty($diagnostic['env']['LODGIFY_API_KEY_SET']) ? 'Configurée ✓' : 'Non configurée ✕' ?></code>
      </div>
    </div>

    <!-- ─── Étape 3 : Base de données ────────────────────────── -->
    <div class="card card-body stack-sm">
      <div class="diag-step-header">
        <span class="diag-step-num">3</span>
        <span class="diag-step-title">Base de données</span>
        <?= diagStatus($dbOk) ?>
      </div>
      <?php if (!$dbOk && !empty($diagnostic['database']['error'])): ?>
      <pre class="message-box" style="color:var(--red)"><?= View::e((string) $diagnostic['database']['error']) ?></pre>
      <?php endif; ?>
    </div>

    <!-- ─── Étape 4 : Connectivité réseau Lodgify ─────────────── -->
    <?php $conn = $diagnostic['lodgify_connectivity'] ?? []; ?>
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
      <div class="diag-row"><span>Propriétés trouvées</span><code><?= View::e((string) $diagnostic['lodgify']['property_count']) ?></code></div>
      <?php else: ?>
      <pre class="message-box" style="color:var(--red)"><?= View::e($diagnostic['lodgify']['error'] ?? 'Erreur inconnue') ?></pre>
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
      <?php if (empty($diagnostic['env']['LODGIFY_API_KEY_SET']) && $cacheOk): ?>
      <pre class="message-box">Des données Lodgify peuvent encore s'afficher via le cache local, même sans clé API active.</pre>
      <?php endif; ?>
    </div>

    <!-- ─── Réponse brute / mappée (debug avancé) ─────────────── -->
    <?php if (!empty($diagnostic['lodgify']['raw_sample'])): ?>
    <div class="card card-body stack-sm">
      <h2 class="section-title">Réponse brute Lodgify (1 bien, champs réels)</h2>
      <pre class="message-box"><?= View::e(json_encode($diagnostic['lodgify']['raw_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
    <?php endif; ?>
    <?php if (!empty($diagnostic['lodgify']['mapped_sample'])): ?>
    <div class="card card-body stack-sm">
      <h2 class="section-title">Données mappées (après transformation)</h2>
      <pre class="message-box"><?= View::e(json_encode($diagnostic['lodgify']['mapped_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
    <?php endif; ?>

  </div><!-- /.stack-md -->
  <?php endif; ?>
</section>
