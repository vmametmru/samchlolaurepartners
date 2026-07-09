<?php
/**
 * deploiement.php — Tableau de bord de déploiement pour hébergement cPanel mutualisé
 *
 * Permet, depuis un simple navigateur (sans "Setup Node.js App"), de :
 *  - vérifier les prérequis serveur (exec(), binaire Node.js) ;
 *  - installer les dépendances Node du backend (npm install) ;
 *  - générer un mini reverse-proxy PHP (api/.htaccess + api/index.php) qui
 *    route /api/* vers le process Node et le redémarre automatiquement s'il
 *    n'est plus actif ;
 *  - démarrer / arrêter / redémarrer le process Node en arrière-plan.
 *
 * Placez ce fichier à la racine du projet (à côté de install.php) et
 * accédez-y via votre navigateur. Un jeton d'accès est généré à la première
 * visite : conservez-le, il est nécessaire pour revenir sur cette page.
 *
 * ⚠️  Ce fichier peut démarrer/arrêter des process serveur — gardez le jeton
 *     secret et supprimez ce fichier si vous n'en avez plus l'utilité.
 */

define('BASE_DIR',    __DIR__);
define('API_DIR',     BASE_DIR . '/api');
define('ENV_FILE',    API_DIR . '/.env');
define('TOKEN_FILE',  BASE_DIR . '/.deploy_token');
define('PID_FILE',    API_DIR . '/.node.pid');
define('DEPLOY_LOG',  API_DIR . '/deploy.log');
define('NODE_LOG',    API_DIR . '/node.log');

session_start();

// ─── Bootstrap the access token ────────────────────────────────────────────
$freshToken = null;
if (!file_exists(TOKEN_FILE)) {
    $freshToken = bin2hex(random_bytes(20));
    file_put_contents(TOKEN_FILE, $freshToken, LOCK_EX);
    @chmod(TOKEN_FILE, 0600);
}
$validToken = trim((string)file_get_contents(TOKEN_FILE));

// ─── Authentication ─────────────────────────────────────────────────────────
$suppliedToken = (string)($_POST['token'] ?? $_GET['token'] ?? ($_SESSION['deploy_token'] ?? ''));
$authenticated = $suppliedToken !== '' && hash_equals($validToken, $suppliedToken);
if ($authenticated) {
    $_SESSION['deploy_token'] = $suppliedToken;
}

$message = '';
$messageType = 'info';

// ─── Handle actions (only once authenticated) ──────────────────────────────
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'install_deps':
            [$message, $messageType] = actionInstallDependencies();
            break;
        case 'write_proxy':
            [$message, $messageType] = actionWriteProxyFiles();
            break;
        case 'start':
            [$message, $messageType] = actionStartServer();
            break;
        case 'stop':
            [$message, $messageType] = actionStopServer();
            break;
        case 'restart':
            [$message, $messageType] = actionStopServer();
            [$message, $messageType] = actionStartServer();
            break;
    }
}

renderPage($freshToken, $authenticated, $suppliedToken, $message, $messageType);


// ═══════════════════════════════════════════════════════════════════════════
//  ACTIONS
// ═══════════════════════════════════════════════════════════════════════════

function execAvailable(): bool
{
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true) && !in_array('shell_exec', $disabled, true);
}

/** Best-effort detection of a usable Node.js binary on shared hosting. */
function detectNodeBinary(): ?string
{
    if (!execAvailable()) {
        return null;
    }

    $candidates = [];

    $which = trim((string)@shell_exec('command -v node 2>/dev/null'));
    if ($which !== '') {
        $candidates[] = $which;
    }
    $which = trim((string)@shell_exec('command -v nodejs 2>/dev/null'));
    if ($which !== '') {
        $candidates[] = $which;
    }

    // cPanel EasyApache Node.js packages
    foreach (glob('/opt/cpanel/ea-node*/bin/node') ?: [] as $path) {
        $candidates[] = $path;
    }

    // cPanel "Setup Node.js App" virtualenvs (if one was ever created manually).
    // The venv path mirrors the application root, which cPanel sometimes nests
    // as a full absolute path (e.g. nodevenv/home/user/domain.com/api/20/bin/node),
    // so we can't assume a fixed depth here — scan recursively instead.
    $home = getenv('HOME') ?: (BASE_DIR . '/..');
    $nodevenvDir = $home . '/nodevenv';
    if (is_dir($nodevenvDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nodevenvDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getFilename() === 'node' && $fileInfo->isFile()) {
                $candidates[] = $fileInfo->getPathname();
            }
        }
    }

    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function npmBinaryFor(string $nodeBin): ?string
{
    $dir = dirname($nodeBin);
    foreach (['npm', 'npm-cli.js'] as $name) {
        $candidate = $dir . '/' . $name;
        if (is_file($candidate)) {
            return $name === 'npm' ? $candidate : ($nodeBin . ' ' . escapeshellarg($candidate));
        }
    }
    // Fall back to whatever "npm" resolves to on PATH.
    $which = trim((string)@shell_exec('command -v npm 2>/dev/null'));
    return $which !== '' ? $which : null;
}

function readEnvValue(string $key, string $default = ''): string
{
    if (!is_file(ENV_FILE)) {
        return $default;
    }
    foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, $key . '=')) {
            return substr($line, strlen($key) + 1);
        }
    }
    return $default;
}

function getPort(): int
{
    $port = (int)readEnvValue('PORT', '3000');
    return $port > 0 ? $port : 3000;
}

/**
 * Returns the list of dependency names declared in api/package.json that are
 * NOT present under api/node_modules. An empty array means everything needed
 * to run the backend is actually installed.
 *
 * We check this instead of merely testing that node_modules/ exists, because
 * a failed "npm install" (e.g. a 404 on an unpublished package) can still
 * leave an empty or partially-populated node_modules directory behind.
 */
function missingNodeModules(): array
{
    $packageJsonPath = API_DIR . '/package.json';
    if (!is_file($packageJsonPath)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($packageJsonPath), true);
    if (!is_array($decoded) || !isset($decoded['dependencies']) || !is_array($decoded['dependencies'])) {
        return [];
    }

    $missing = [];
    foreach (array_keys($decoded['dependencies']) as $dependency) {
        if (!is_dir(API_DIR . '/node_modules/' . $dependency)) {
            $missing[] = $dependency;
        }
    }
    return $missing;
}

function readPid(): ?int
{
    if (!is_file(PID_FILE)) {
        return null;
    }
    $pid = (int)trim((string)file_get_contents(PID_FILE));
    return $pid > 0 ? $pid : null;
}

function isPidAlive(?int $pid): bool
{
    if (!$pid) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

function healthCheck(int $timeoutSeconds = 3): ?array
{
    $port = getPort();
    $url  = 'http://127.0.0.1:' . $port . '/health';
    if (!function_exists('curl_init')) {
        $ctx  = stream_context_create(['http' => ['timeout' => $timeoutSeconds]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? ['ok' => true, 'body' => $body] : null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);
    $body = curl_exec($ch);
    $ok   = $body !== false && curl_errno($ch) === 0;
    curl_close($ch);
    return $ok ? ['ok' => true, 'body' => $body] : null;
}

function appendLog(string $line): void
{
    @file_put_contents(DEPLOY_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function tailFile(string $path, int $lines = 40): string
{
    if (!is_file($path)) {
        return '';
    }
    $content = (string)file_get_contents($path);
    $parts   = explode("\n", rtrim($content, "\n"));
    return implode("\n", array_slice($parts, -$lines));
}

function actionInstallDependencies(): array
{
    if (!execAvailable()) {
        return ["L'exécution de commandes (exec/shell_exec) est désactivée par votre hébergeur — impossible d'installer les dépendances automatiquement.", 'error'];
    }
    if (!is_dir(API_DIR)) {
        return ["Le dossier api/ est introuvable à la racine du projet.", 'error'];
    }
    $node = detectNodeBinary();
    if (!$node) {
        return ["Aucun binaire Node.js détecté sur le serveur. Contactez votre hébergeur pour activer Node.js.", 'error'];
    }
    $npm = npmBinaryFor($node);
    if (!$npm) {
        return ["npm est introuvable à côté du binaire Node détecté ({$node}).", 'error'];
    }

    // Shared hosting typically caps PHP execution at 30s by default, which is
    // often shorter than the time "npm install" needs to fetch ~11 packages
    // and their transitive dependencies. npm creates the node_modules/
    // directory up front and populates it progressively, so a request killed
    // mid-install leaves an empty or partially-populated directory behind —
    // exactly the "module(s) manquant(s)" symptom this function detects.
    // Lift those limits (best effort; some hosts disable these functions)
    // so the install can actually run to completion.
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }

    $cmd = sprintf(
        'cd %s && %s install --omit=dev --no-audit --no-fund 2>&1',
        escapeshellarg(API_DIR),
        $npm
    );
    $output = @shell_exec($cmd);
    appendLog("npm install:\n" . ($output ?? ''));

    if (!is_dir(API_DIR . '/node_modules')) {
        return ["L'installation des dépendances a échoué. Consultez le journal ci-dessous.", 'error'];
    }
    $missing = missingNodeModules();
    if ($missing !== []) {
        return [
            "L'installation des dépendances a échoué : module(s) manquant(s) dans api/node_modules : "
                . implode(', ', $missing) . ". Consultez le journal ci-dessous.",
            'error',
        ];
    }
    return ["Dépendances installées avec succès (node: {$node}).", 'success'];
}

function actionWriteProxyFiles(): array
{
    if (!is_dir(API_DIR)) {
        return ["Le dossier api/ est introuvable à la racine du projet.", 'error'];
    }

    $htaccess = <<<HT
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]
RewriteRule ^ index.php [QSA,L]
HT;
    if (file_put_contents(API_DIR . '/.htaccess', $htaccess . "\n") === false) {
        return ["Impossible d'écrire api/.htaccess — vérifiez les permissions.", 'error'];
    }

    $indexPhp = buildProxyIndexPhp();
    if (file_put_contents(API_DIR . '/index.php', $indexPhp) === false) {
        return ["Impossible d'écrire api/index.php — vérifiez les permissions.", 'error'];
    }

    appendLog('Fichiers de proxy (api/.htaccess, api/index.php) générés.');
    return ["Proxy PHP généré : les requêtes /api/* seront relayées vers le process Node (redémarrage automatique inclus).", 'success'];
}

/** Builds the standalone self-healing reverse-proxy shim placed at api/index.php. */
function buildProxyIndexPhp(): string
{
    $port = getPort();
    return <<<PHP
<?php
/**
 * api/index.php — Généré par deploiement.php
 * Relaye les requêtes HTTP vers le process Node.js local et le redémarre
 * automatiquement s'il n'est plus actif.
 */

\$port    = {$port};
\$pidFile = __DIR__ . '/.node.pid';
\$logFile = __DIR__ . '/node.log';

function proxyIsPidAlive(?int \$pid): bool
{
    if (!\$pid) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill(\$pid, 0);
    }
    return is_dir('/proc/' . \$pid);
}

function proxyEnsureNodeRunning(string \$pidFile, string \$logFile): void
{
    \$pid = null;
    if (is_file(\$pidFile)) {
        \$pid = (int)trim((string)file_get_contents(\$pidFile));
    }
    if (proxyIsPidAlive(\$pid)) {
        return;
    }
    if (!function_exists('shell_exec')) {
        return;
    }
    \$cmd = sprintf(
        'cd %s && nohup node index.js >> %s 2>&1 & echo \$!',
        escapeshellarg(__DIR__),
        escapeshellarg(\$logFile)
    );
    \$newPid = trim((string)@shell_exec(\$cmd));
    if (ctype_digit(\$newPid)) {
        file_put_contents(\$pidFile, \$newPid);
        usleep(700000); // brief warm-up before proxying the first request
    }
}

proxyEnsureNodeRunning(\$pidFile, \$logFile);

// Rebuild the target from the path/query only — never trust REQUEST_URI verbatim,
// since a crafted request-target (e.g. containing "//" or "@") could otherwise be
// used to redirect the proxied request to a different host (SSRF).
\$requestParts = parse_url(\$_SERVER['REQUEST_URI'] ?? '/');
\$path  = \$requestParts['path'] ?? '/';
if (\$path === '' || \$path[0] !== '/') {
    \$path = '/' . ltrim(\$path, '/');
}
\$query  = isset(\$requestParts['query']) ? '?' . \$requestParts['query'] : '';
\$target = 'http://127.0.0.1:' . \$port . \$path . \$query;
\$method = \$_SERVER['REQUEST_METHOD'];
\$body   = file_get_contents('php://input');

\$headers = [];
foreach (getallheaders() ?: [] as \$name => \$value) {
    if (strtolower(\$name) === 'host') {
        continue;
    }
    \$headers[] = \$name . ': ' . \$value;
}

\$ch = curl_init(\$target);
curl_setopt_array(\$ch, [
    CURLOPT_CUSTOMREQUEST  => \$method,
    CURLOPT_HTTPHEADER     => \$headers,
    CURLOPT_POSTFIELDS     => \$body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
]);
\$response = curl_exec(\$ch);

if (\$response === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Bad Gateway', 'message' => 'Le backend Node.js ne répond pas.']);
    curl_close(\$ch);
    exit;
}

\$headerSize = curl_getinfo(\$ch, CURLINFO_HEADER_SIZE);
\$statusCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
curl_close(\$ch);

\$rawHeaders  = substr(\$response, 0, \$headerSize);
\$responseBody = substr(\$response, \$headerSize);

http_response_code(\$statusCode);
foreach (explode("\\r\\n", \$rawHeaders) as \$headerLine) {
    \$lower = strtolower(\$headerLine);
    if (\$headerLine === '' || str_starts_with(\$lower, 'transfer-encoding') || str_starts_with(\$lower, 'connection') || str_starts_with(\$lower, 'http/')) {
        continue;
    }
    header(\$headerLine, false);
}
echo \$responseBody;
PHP;
}

function actionStartServer(): array
{
    if (!execAvailable()) {
        return ["L'exécution de commandes est désactivée — impossible de démarrer le serveur.", 'error'];
    }
    if (isPidAlive(readPid())) {
        return ['Le serveur Node.js est déjà en cours d\'exécution.', 'info'];
    }
    $node = detectNodeBinary();
    if (!$node) {
        return ["Aucun binaire Node.js détecté sur le serveur.", 'error'];
    }
    if (!is_file(API_DIR . '/index.js')) {
        return ["api/index.js est introuvable — avez-vous déployé le build du backend ?", 'error'];
    }

    $cmd = sprintf(
        'cd %s && nohup %s index.js >> %s 2>&1 & echo $!',
        escapeshellarg(API_DIR),
        escapeshellarg($node),
        escapeshellarg(NODE_LOG)
    );
    $pid = trim((string)@shell_exec($cmd));
    if (!ctype_digit($pid)) {
        return ["Impossible de démarrer le process Node.js.", 'error'];
    }
    file_put_contents(PID_FILE, $pid);
    appendLog("Serveur démarré (PID {$pid}, node: {$node}).");

    sleep(1);
    $health = healthCheck();
    if ($health === null) {
        return ["Process démarré (PID {$pid}) mais le contrôle de santé /health n'a pas répondu. Consultez node.log.", 'error'];
    }
    return ["Serveur Node.js démarré avec succès (PID {$pid}).", 'success'];
}

function actionStopServer(): array
{
    $pid = readPid();
    if (!isPidAlive($pid)) {
        @unlink(PID_FILE);
        return ["Le serveur Node.js n'était pas en cours d'exécution.", 'info'];
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, 15);
    } elseif (execAvailable()) {
        @shell_exec('kill ' . (int)$pid . ' 2>/dev/null');
    }
    @unlink(PID_FILE);
    appendLog("Serveur arrêté (PID {$pid}).");
    return ["Serveur Node.js arrêté.", 'success'];
}


// ═══════════════════════════════════════════════════════════════════════════
//  RENDERING
// ═══════════════════════════════════════════════════════════════════════════

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function renderPage(?string $freshToken, bool $authenticated, string $suppliedToken, string $message, string $messageType): void
{
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Déploiement — samchlolaurepartners</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f0f4f8; color: #1e293b; min-height: 100vh; padding: 2rem 1rem;
  }
  .wrapper { max-width: 900px; margin: 0 auto; }
  .logo-bar { text-align: center; font-size: 1.25rem; margin-bottom: 1.5rem; color: #334155; }
  .card { background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 4px 12px rgba(0,0,0,.05); margin-bottom: 1.25rem; }
  .card h2 { font-size: 1.25rem; margin-bottom: .75rem; }
  .card p { margin-bottom: .75rem; color: #475569; }
  code, pre { background: #f1f5f9; border-radius: 6px; padding: .15rem .4rem; font-size: .85em; }
  pre { padding: 1rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
  .alert { border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: 1.25rem; }
  .alert-success { background: #dcfce7; color: #166534; }
  .alert-error   { background: #fee2e2; color: #991b1b; }
  .alert-info    { background: #e0f2fe; color: #075985; }
  .alert-warn    { background: #fef9c3; color: #854d0e; }
  .btn { display: inline-block; border: none; border-radius: 8px; padding: .6rem 1.1rem; font-size: .9rem; font-weight: 600; cursor: pointer; margin: .2rem .3rem .2rem 0; }
  .btn-primary { background: #4f46e5; color: #fff; }
  .btn-outline { background: #fff; color: #4f46e5; border: 1px solid #4f46e5; }
  .btn-danger  { background: #dc2626; color: #fff; }
  table.check-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
  table.check-table td { padding: .5rem .25rem; border-bottom: 1px solid #e2e8f0; }
  .ok   { color: #16a34a; font-weight: 700; }
  .fail { color: #dc2626; font-weight: 700; }
  input[type=text] { width: 100%; padding: .6rem .8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .95rem; margin-bottom: .75rem; }
  .token-box { font-family: monospace; word-break: break-all; background: #1e293b; color: #a7f3d0; padding: 1rem; border-radius: 8px; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="logo-bar">🏝️ <strong>samchlolaurepartners</strong> — Tableau de bord de déploiement</div>

  <?php if ($freshToken !== null): ?>
  <div class="card">
    <h2>🔑 Jeton d'accès créé</h2>
    <p>Ce jeton est nécessaire pour revenir sur cette page. <strong>Notez-le maintenant</strong>, il ne sera plus jamais affiché :</p>
    <div class="token-box"><?= h($freshToken) ?></div>
  </div>
  <?php endif; ?>

  <?php if (!$authenticated): ?>
    <?php renderLoginCard($suppliedToken, $freshToken !== null); ?>
  <?php else: ?>
    <?php if ($message !== ''): ?>
    <div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div>
    <?php endif; ?>
    <?php renderRequirementsCard(); ?>
    <?php renderActionsCard(); ?>
    <?php renderStatusCard(); ?>
    <?php renderLogsCard(); ?>
    <p style="text-align:center;color:#94a3b8;font-size:.8rem;">Gardez votre jeton secret. Supprimez ce fichier si vous n'avez plus besoin de gérer le déploiement depuis le navigateur.</p>
  <?php endif; ?>
</div>
</body>
</html><?php
}

function renderLoginCard(string $suppliedToken, bool $justCreated): void
{
    ?>
    <div class="card">
      <h2>Accès protégé</h2>
      <?php if (!$justCreated && $suppliedToken !== ''): ?>
        <div class="alert alert-error">Jeton invalide.</div>
      <?php endif; ?>
      <p>Saisissez le jeton d'accès pour continuer.</p>
      <form method="GET">
        <input type="text" name="token" placeholder="Jeton d'accès" autofocus>
        <button type="submit" class="btn btn-primary">Continuer</button>
      </form>
    </div>
    <?php
}

function renderRequirementsCard(): void
{
    $node = detectNodeBinary();
    $checks = [
        'Exécution de commandes (exec/shell_exec)' => execAvailable(),
        'Binaire Node.js détecté' . ($node ? " ({$node})" : '') => $node !== null,
        'Extension cURL (proxy PHP)'               => function_exists('curl_init'),
        'Fichier api/.env présent'                 => is_file(ENV_FILE),
        'Dossier api/ présent'                     => is_dir(API_DIR),
        'api/index.js présent (build backend)'     => is_file(API_DIR . '/index.js'),
        'Dépendances installées (api/node_modules)' => is_dir(API_DIR . '/node_modules') && missingNodeModules() === [],
    ];
    ?>
    <div class="card">
      <h2>Étape 1 — Vérification de l'environnement</h2>
      <table class="check-table">
        <tbody>
        <?php foreach ($checks as $label => $ok): ?>
          <tr>
            <td><?= h($label) ?></td>
            <td class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✅ OK' : '❌ Manquant' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!execAvailable()): ?>
        <div class="alert alert-warn">Votre hébergeur désactive exec()/shell_exec() : contactez le support pour l'activer, ou utilisez "Setup Node.js App" dans cPanel à la place.</div>
      <?php elseif ($node === null): ?>
        <div class="alert alert-warn">Aucun binaire Node.js n'a été trouvé automatiquement. Demandez à votre hébergeur d'activer Node.js (EasyApache Node.js, ou création ponctuelle d'une app via "Setup Node.js App").</div>
      <?php endif; ?>
    </div>
    <?php
}

function renderActionsCard(): void
{
    $token = h($_SESSION['deploy_token'] ?? '');
    ?>
    <div class="card">
      <h2>Étape 2 — Actions de déploiement</h2>
      <p>Exécutez ces étapes dans l'ordre lors du premier déploiement (ou après chaque mise à jour du code).</p>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="action" value="install_deps">
        <button type="submit" class="btn btn-primary">1. Installer les dépendances (npm install)</button>
      </form>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="action" value="write_proxy">
        <button type="submit" class="btn btn-primary">2. Générer le proxy PHP (api/*)</button>
      </form>
      <br>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="action" value="start">
        <button type="submit" class="btn btn-outline">▶️ Démarrer le serveur</button>
      </form>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="action" value="restart">
        <button type="submit" class="btn btn-outline">🔄 Redémarrer</button>
      </form>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <input type="hidden" name="action" value="stop">
        <button type="submit" class="btn btn-danger">⏹️ Arrêter</button>
      </form>
      <form method="GET" style="display:inline;">
        <input type="hidden" name="token" value="<?= $token ?>">
        <button type="submit" class="btn btn-outline">↻ Rafraîchir le statut</button>
      </form>
    </div>
    <?php
}

function renderStatusCard(): void
{
    $pid    = readPid();
    $alive  = isPidAlive($pid);
    $health = $alive ? healthCheck() : null;
    ?>
    <div class="card">
      <h2>Statut</h2>
      <table class="check-table">
        <tbody>
          <tr><td>Process Node.js (PID)</td><td class="<?= $alive ? 'ok' : 'fail' ?>"><?= $alive ? '✅ Actif (PID ' . (int)$pid . ')' : '❌ Arrêté' ?></td></tr>
          <tr><td>Contrôle /health</td><td class="<?= $health ? 'ok' : 'fail' ?>"><?= $health ? '✅ Répond' : '❌ Aucune réponse' ?></td></tr>
        </tbody>
      </table>
      <p>Une fois le proxy PHP généré (étape 2), toutes les URL <code>/api/*</code> de votre domaine fonctionneront automatiquement — y compris <code>/api/auth/login</code>, <code>/api/partners</code>, <code>/api/lodgify/properties</code> — et le serveur Node.js redémarrera de lui-même s'il venait à s'arrêter.</p>
    </div>
    <?php
}

function renderLogsCard(): void
{
    $deployLog = h(tailFile(DEPLOY_LOG));
    $nodeLog   = h(tailFile(NODE_LOG));
    ?>
    <div class="card">
      <h2>Journaux</h2>
      <p><strong>deploy.log</strong></p>
      <pre><?= $deployLog !== '' ? $deployLog : '(vide)' ?></pre>
      <p><strong>node.log</strong></p>
      <pre><?= $nodeLog !== '' ? $nodeLog : '(vide)' ?></pre>
    </div>
    <?php
}
