<?php
/**
 * install.php — Wizard d'installation pour samchlolaurepartners
 *
 * Placez ce fichier à la racine du projet et accédez-y via votre navigateur.
 * ⚠️  SUPPRIMEZ CE FICHIER après l'installation pour des raisons de sécurité.
 */

define('BASE_DIR',        __DIR__);
define('ENV_FILE',        BASE_DIR . '/api/.env');
define('MIGRATIONS_DIR',  BASE_DIR . '/database/migrations');
define('LOGO_UPLOAD_DIR', BASE_DIR . '/logos');

// ─── Step constants ─────────────────────────────────────────────────────────
define('S_REQUIREMENTS', 1);
define('S_DATABASE',     2);
define('S_SITE',         3);
define('S_ADMIN',        4);
define('S_SMTP',         5);
define('S_CONFIRM',      6);
define('S_DONE',         7);

// ─── Already installed? ──────────────────────────────────────────────────────
if (file_exists(ENV_FILE) && !isset($_GET['reinstall'])) {
    renderAlreadyInstalled();
    exit;
}

session_start();

$errors  = [];
$step    = (int)(($_POST['step'] ?? $_GET['step'] ?? S_REQUIREMENTS));

// ─── Process POST submissions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (int)($_POST['step'] ?? 0);

    switch ($posted) {
        case S_REQUIREMENTS:
            $checks = checkRequirements();
            if (!in_array(false, $checks, true)) {
                $step = S_DATABASE;
            } else {
                $errors[] = 'Certains prérequis ne sont pas satisfaits.';
                $step     = S_REQUIREMENTS;
            }
            break;

        case S_DATABASE:
            [$errors, $step] = processDatabase();
            break;

        case S_SITE:
            [$errors, $step] = processSite();
            break;

        case S_ADMIN:
            [$errors, $step] = processAdmin();
            break;

        case S_SMTP:
            [$errors, $step] = processSmtp();
            break;

        case S_CONFIRM:
            $result = runInstallation();
            if ($result === true) {
                session_destroy();
                $step = S_DONE;
            } else {
                $errors[] = $result;
                $step     = S_CONFIRM;
            }
            break;
    }
}

// ─── Render ──────────────────────────────────────────────────────────────────
renderPage($step, $errors);


// ═══════════════════════════════════════════════════════════════════════════════
//  STEP PROCESSORS
// ═══════════════════════════════════════════════════════════════════════════════

function processDatabase(): array
{
    $data = [
        'host'     => trim($_POST['db_host'] ?? 'localhost'),
        'port'     => (int)($_POST['db_port'] ?? 3306),
        'user'     => trim($_POST['db_user'] ?? ''),
        'password' => $_POST['db_password'] ?? '',
        'name'     => trim($_POST['db_name'] ?? ''),
    ];

    if (empty($data['host']) || empty($data['user']) || empty($data['name'])) {
        return [['Hôte, utilisateur et nom de base de données sont obligatoires.'], S_DATABASE];
    }

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $data['host'], $data['port']);
        $pdo = new PDO($dsn, $data['user'], $data['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $safeName = str_replace(['`', "'", '"', ';', ' '], '', $data['name']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $_SESSION['db'] = $data;
        return [[], S_SITE];
    } catch (PDOException $e) {
        return [['Connexion impossible : ' . $e->getMessage()], S_DATABASE];
    }
}

function processSite(): array
{
    $errors = [];
    $data   = [
        'name'          => trim($_POST['site_name'] ?? ''),
        'subdomain'     => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['subdomain'] ?? 'default'))) ?: 'default',
        'email'         => trim($_POST['site_email'] ?? ''),
        'logo_url'      => trim($_POST['logo_url'] ?? ''),
        'primary_color' => trim($_POST['primary_color'] ?? '#E61E4D'),
        'markup'        => max(0.0, (float)($_POST['markup'] ?? 0)),
        'lodgify_key'   => trim($_POST['lodgify_key'] ?? ''),
        'lodgify_url'   => trim($_POST['lodgify_url'] ?? 'https://api.lodgify.com/v2'),
        'cors_origin'   => trim($_POST['cors_origin'] ?? 'http://localhost:5173'),
        'app_url'       => trim($_POST['app_url'] ?? 'http://localhost:3000'),
    ];

    // Logo file upload (optional)
    if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleLogoUpload($_FILES['logo_file']);
        if (str_starts_with($uploadResult, '/')) {
            $data['logo_url'] = $uploadResult;
        } else {
            $errors[] = $uploadResult;
        }
    }

    if (empty($data['name'])) {
        $errors[] = 'Le nom du site est obligatoire.';
    }
    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Une adresse email valide est obligatoire.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data['primary_color'])) {
        $data['primary_color'] = '#E61E4D';
    }

    if (empty($errors)) {
        $_SESSION['site'] = $data;
        return [[], S_ADMIN];
    }
    return [$errors, S_SITE];
}

function processAdmin(): array
{
    $email    = trim($_POST['admin_email'] ?? '');
    $password = $_POST['admin_password'] ?? '';
    $confirm  = $_POST['admin_confirm'] ?? '';
    $errors   = [];

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email admin valide obligatoire.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Les mots de passe ne correspondent pas.';
    }

    if (empty($errors)) {
        $_SESSION['admin'] = [
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ];
        return [[], S_SMTP];
    }
    return [$errors, S_ADMIN];
}

function processSmtp(): array
{
    $_SESSION['smtp'] = [
        'host'     => trim($_POST['smtp_host'] ?? 'localhost'),
        'port'     => (int)($_POST['smtp_port'] ?? 1025),
        'user'     => trim($_POST['smtp_user'] ?? ''),
        'password' => $_POST['smtp_password'] ?? '',
    ];
    return [[], S_CONFIRM];
}


// ═══════════════════════════════════════════════════════════════════════════════
//  INSTALLATION
// ═══════════════════════════════════════════════════════════════════════════════

function runInstallation(): bool|string
{
    $db    = $_SESSION['db']    ?? null;
    $site  = $_SESSION['site']  ?? null;
    $admin = $_SESSION['admin'] ?? null;
    $smtp  = $_SESSION['smtp']  ?? ['host' => 'localhost', 'port' => 1025, 'user' => '', 'password' => ''];

    if (!$db || !$site || !$admin) {
        return 'Session expirée. Veuillez recommencer l\'installation.';
    }

    // 1. Connect to the target database
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'], $db['port'], $db['name']);
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        return 'Connexion DB échouée : ' . $e->getMessage();
    }

    // 2. Run migrations
    $migrationFiles = glob(MIGRATIONS_DIR . '/*.sql');
    if ($migrationFiles === false || empty($migrationFiles)) {
        return 'Aucun fichier de migration trouvé dans ' . MIGRATIONS_DIR;
    }
    sort($migrationFiles);

    foreach ($migrationFiles as $file) {
        $sql        = file_get_contents($file);
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with(ltrim($stmt), '--')) {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Ignore "already exists" errors for idempotent re-runs
                $msg = strtolower($e->getMessage());
                if (strpos($msg, 'already exists') === false && $e->getCode() !== '42S01') {
                    // Still non-fatal for other errors; log but continue
                }
            }
        }
        // Track migration
        try {
            $pdo->prepare('INSERT IGNORE INTO db_migrations (filename) VALUES (:f)')
                ->execute([':f' => basename($file)]);
        } catch (PDOException $e) { /* non-fatal */ }
    }

    // 3. Create default partner
    $subdomain = $site['subdomain'];
    try {
        $stmt = $pdo->prepare('
            INSERT INTO partners
                (subdomain, name, logo_url, primary_color, email, markup_percent,
                 smtp_host, smtp_port, smtp_user, smtp_pass)
            VALUES
                (:sub, :name, :logo, :color, :email, :markup, :sh, :sp, :su, :sw)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                logo_url = VALUES(logo_url),
                primary_color = VALUES(primary_color),
                email = VALUES(email),
                markup_percent = VALUES(markup_percent)
        ');
        $stmt->execute([
            ':sub'    => $subdomain,
            ':name'   => $site['name'],
            ':logo'   => $site['logo_url'] ?: null,
            ':color'  => $site['primary_color'],
            ':email'  => $site['email'],
            ':markup' => $site['markup'],
            ':sh'     => $smtp['host'] ?: null,
            ':sp'     => $smtp['port'] ?: null,
            ':su'     => $smtp['user'] ?: null,
            ':sw'     => $smtp['password'] ?: null,
        ]);
        $partnerId = (int)$pdo->lastInsertId();
        if (!$partnerId) {
            $r = $pdo->prepare('SELECT id FROM partners WHERE subdomain = :s LIMIT 1');
            $r->execute([':s' => $subdomain]);
            $partnerId = (int)$r->fetchColumn();
        }
    } catch (PDOException $e) {
        return 'Erreur création partenaire : ' . $e->getMessage();
    }

    // 4. Create admin user
    try {
        $pdo->prepare('
            INSERT INTO users (email, password_hash, role, partner_id)
            VALUES (:e, :h, "admin", NULL)
            ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
        ')->execute([':e' => $admin['email'], ':h' => $admin['password_hash']]);
    } catch (PDOException $e) {
        return 'Erreur création admin : ' . $e->getMessage();
    }

    // 5. Default email templates for the new partner
    foreach (buildDefaultTemplates($partnerId, $site['name']) as $tpl) {
        try {
            $pdo->prepare('
                INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html)
                VALUES (:pid, :type, :subject, :body)
            ')->execute([
                ':pid'     => $partnerId,
                ':type'    => $tpl['type'],
                ':subject' => $tpl['subject'],
                ':body'    => $tpl['body'],
            ]);
        } catch (PDOException $e) { /* non-fatal */ }
    }

    // 6. Record the installation as first app version
    try {
        $pdo->prepare('
            INSERT INTO app_versions (version, deployed_by, notes)
            VALUES ("1.0.0", :by, "Installation initiale via install.php")
        ')->execute([':by' => $admin['email']]);
    } catch (PDOException $e) { /* non-fatal */ }

    // 7. Write .env file
    $jwtSecret = bin2hex(random_bytes(32));
    $envContent = buildEnvContent($db, $site, $admin, $smtp, $jwtSecret);
    if (file_put_contents(ENV_FILE, $envContent) === false) {
        return 'Impossible d\'écrire le fichier .env — vérifiez les permissions du répertoire.';
    }

    return true;
}


// ═══════════════════════════════════════════════════════════════════════════════
//  UTILITIES
// ═══════════════════════════════════════════════════════════════════════════════

function checkRequirements(): array
{
    return [
        'PHP ≥ 8.0'                              => version_compare(PHP_VERSION, '8.0.0', '>='),
        'Extension PDO'                          => extension_loaded('pdo'),
        'Extension PDO MySQL'                    => extension_loaded('pdo_mysql'),
        'Extension JSON'                         => extension_loaded('json'),
        'Extension FileInfo'                     => extension_loaded('fileinfo'),
        'Répertoire racine accessible en écriture' => is_writable(BASE_DIR),
        'Répertoire des migrations présent'      => is_dir(MIGRATIONS_DIR),
    ];
}

function handleLogoUpload(array $file): string
{
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        return 'Format non autorisé. Utilisez JPG, PNG, GIF, WebP ou SVG.';
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return 'Le logo ne doit pas dépasser 2 Mo.';
    }
    if (!is_dir(LOGO_UPLOAD_DIR) && !mkdir(LOGO_UPLOAD_DIR, 0755, true)) {
        return 'Impossible de créer le répertoire de logos.';
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_' . time() . '.' . $ext;
    $dest     = LOGO_UPLOAD_DIR . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return '/logos/' . $filename;
    }
    return 'Impossible de sauvegarder le logo.';
}

function buildEnvContent(array $db, array $site, array $admin, array $smtp, string $jwtSecret): string
{
    $lines = [
        '# Generated by install.php on ' . date('Y-m-d H:i:s'),
        '',
        '# ─── Backend ───────────────────────────────────────────',
        'PORT=3000',
        'NODE_ENV=production',
        '',
        '# MySQL',
        'DB_HOST=' . $db['host'],
        'DB_PORT=' . $db['port'],
        'DB_USER=' . $db['user'],
        'DB_PASSWORD=' . envVal($db['password']),
        'DB_NAME=' . $db['name'],
        '',
        '# JWT',
        'JWT_SECRET=' . $jwtSecret,
        '',
        '# Lodgify API (server-side only — never expose to frontend)',
        'LODGIFY_API_KEY=' . envVal($site['lodgify_key']),
        'LODGIFY_BASE_URL=' . $site['lodgify_url'],
        '',
        '# Default SMTP fallback (per-partner SMTP overrides this)',
        'SMTP_HOST=' . $smtp['host'],
        'SMTP_PORT=' . $smtp['port'],
        '',
        '# Admin credentials (for reference)',
        'ADMIN_EMAIL=' . $admin['email'],
        '',
        '# CORS — comma-separated list of allowed frontend origins',
        'CORS_ORIGIN=' . $site['cors_origin'],
        '',
        '# ─── Frontend ──────────────────────────────────────────',
        'VITE_API_URL=' . $site['app_url'],
        'VITE_MAP_TILE_URL=https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    ];

    return implode("\n", $lines) . "\n";
}

/** Quote a .env value if it contains special characters. */
function envVal(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/[\s#"\'\\\\]/', $value)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    return $value;
}

function buildDefaultTemplates(int $partnerId, string $partnerName): array
{
    // Encode only HTML-special chars (not quotes) so the name is safe in HTML email bodies
    $p = htmlspecialchars($partnerName, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return [
        [
            'type'    => 'REQUEST_RECEIVED_PARTNER',
            'subject' => 'Nouvelle demande de réservation - {{nom_client}}',
            'body'    => "<h2>Nouvelle demande de réservation</h2>\n"
                       . "<p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p>\n"
                       . "<p><strong>Hébergement :</strong> {{hebergement}}</p>\n"
                       . "<p><strong>Dates :</strong> {{dates}}</p>\n"
                       . "<p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p>\n"
                       . "<p><strong>Message :</strong><br>{{message}}</p>\n"
                       . "<hr>\n"
                       . "<p>Veuillez traiter cette demande depuis votre espace partenaire.</p>",
        ],
        [
            'type'    => 'REQUEST_RECEIVED_CLIENT',
            'subject' => 'Votre demande de réservation a bien été reçue',
            'body'    => "<h2>Votre demande a bien été reçue</h2>\n"
                       . "<p>Bonjour {{nom_client}},</p>\n"
                       . "<p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> "
                       . "du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p>\n"
                       . "<p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p>\n"
                       . "<p>Cordialement,<br><strong>{$p}</strong></p>",
        ],
        [
            'type'    => 'RESERVATION_CONFIRMED',
            'subject' => 'Votre réservation est confirmée ! 🎉',
            'body'    => "<h2>Réservation confirmée</h2>\n"
                       . "<p>Bonjour {{nom_client}},</p>\n"
                       . "<p>Nous avons le plaisir de vous confirmer votre réservation :</p>\n"
                       . "<ul>\n"
                       . "  <li><strong>Hébergement :</strong> {{hebergement}}</li>\n"
                       . "  <li><strong>Arrivée :</strong> {{date_arrivee}}</li>\n"
                       . "  <li><strong>Départ :</strong> {{date_depart}}</li>\n"
                       . "  <li><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</li>\n"
                       . "</ul>\n"
                       . "{{notes}}\n"
                       . "<p>À très bientôt à l'île Maurice !</p>\n"
                       . "<p>Cordialement,<br><strong>{$p}</strong></p>",
        ],
        [
            'type'    => 'RESERVATION_CANCELLED',
            'subject' => 'Annulation de votre réservation',
            'body'    => "<h2>Votre réservation a été annulée</h2>\n"
                       . "<p>Bonjour {{nom_client}},</p>\n"
                       . "<p>Nous vous informons que votre réservation pour <strong>{{hebergement}}</strong> "
                       . "({{dates}}) a malheureusement dû être annulée.</p>\n"
                       . "<p>N'hésitez pas à nous contacter pour explorer d'autres options.</p>\n"
                       . "<p>Cordialement,<br><strong>{$p}</strong></p>",
        ],
        [
            'type'    => 'REMINDER',
            'subject' => 'Rappel : votre séjour approche ! 🌴',
            'body'    => "<h2>Votre séjour approche !</h2>\n"
                       . "<p>Bonjour {{nom_client}},</p>\n"
                       . "<p>Nous vous rappelons que votre séjour à <strong>{{hebergement}}</strong> approche :</p>\n"
                       . "<ul>\n"
                       . "  <li><strong>Arrivée :</strong> {{date_arrivee}}</li>\n"
                       . "  <li><strong>Départ :</strong> {{date_depart}}</li>\n"
                       . "</ul>\n"
                       . "<p>N'hésitez pas à nous contacter si vous avez des questions.</p>\n"
                       . "<p>À bientôt,<br><strong>{$p}</strong></p>",
        ],
    ];
}

/** HTML-escape a string for safe output. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Get a session value, HTML-escaped. */
function sess(string $key, string $sub = '', string $default = ''): string
{
    if ($sub !== '') {
        return h((string)(($_SESSION[$key] ?? [])[$sub] ?? $default));
    }
    return h((string)($_SESSION[$key] ?? $default));
}


// ═══════════════════════════════════════════════════════════════════════════════
//  RENDERING
// ═══════════════════════════════════════════════════════════════════════════════

function renderAlreadyInstalled(): void
{
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Installation — samchlolaurepartners</title>
<?php renderHead(); ?>
</head>
<body>
<div class="wrapper">
  <div class="logo-bar">🏝️ <strong>samchlolaurepartners</strong></div>
  <div class="card" style="text-align:center;padding:3rem 2rem;">
    <div style="font-size:3rem;margin-bottom:1rem;">✅</div>
    <h2>Déjà installé</h2>
    <p>Le fichier <code>.env</code> existe déjà — le site est configuré.</p>
    <p style="margin-top:1.5rem;">
      <a href="?reinstall=1" class="btn btn-outline" onclick="return confirm('Attention : cela écrasera votre .env existant. Continuer ?')">
        Réinstaller (écraser)
      </a>
    </p>
  </div>
</div>
</body>
</html><?php
}

function renderPage(int $step, array $errors): void
{
    $stepLabels = [
        S_REQUIREMENTS => 'Prérequis',
        S_DATABASE     => 'Base de données',
        S_SITE         => 'Site &amp; Partenaire',
        S_ADMIN        => 'Compte admin',
        S_SMTP         => 'Email SMTP',
        S_CONFIRM      => 'Confirmation',
        S_DONE         => 'Terminé',
    ];
    $totalSteps  = S_CONFIRM;
    $currentStep = min($step, $totalSteps);
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installation — samchlolaurepartners</title>
<?php renderHead(); ?>
</head>
<body>
<div class="wrapper">

  <div class="logo-bar">🏝️ <strong>samchlolaurepartners</strong> — Assistant d'installation</div>

  <?php if ($step < S_DONE): ?>
  <div class="progress-bar-wrap">
    <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
      <div class="progress-step <?= $i < $currentStep ? 'done' : ($i === $currentStep ? 'active' : '') ?>">
        <div class="step-circle"><?= $i < $currentStep ? '✓' : $i ?></div>
        <div class="step-label"><?= $stepLabels[$i] ?? '' ?></div>
      </div>
      <?php if ($i < $totalSteps): ?>
        <div class="progress-connector <?= $i < $currentStep ? 'done' : '' ?>"></div>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <?php foreach ($errors as $err): ?>
      <p>⚠️ <?= h($err) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <?php
    switch ($step) {
        case S_REQUIREMENTS: renderStepRequirements(); break;
        case S_DATABASE:     renderStepDatabase();     break;
        case S_SITE:         renderStepSite();         break;
        case S_ADMIN:        renderStepAdmin();        break;
        case S_SMTP:         renderStepSmtp();         break;
        case S_CONFIRM:      renderStepConfirm();      break;
        case S_DONE:         renderStepDone();         break;
    }
    ?>
  </div>

  <p class="footer-note">⚠️ Supprimez <code>install.php</code> après l'installation pour des raisons de sécurité.</p>
</div>
</body>
</html>
    <?php
}

// ─── Step 1: Requirements ─────────────────────────────────────────────────────
function renderStepRequirements(): void
{
    $checks = checkRequirements();
    $allOk  = !in_array(false, $checks, true);
    ?>
    <h2>Étape 1 — Vérification des prérequis</h2>
    <p>Vérification de l'environnement serveur avant de commencer.</p>

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

    <?php if (!$allOk): ?>
      <div class="alert alert-warn">Installez les extensions PHP manquantes et actualisez la page.</div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="step" value="<?= S_REQUIREMENTS ?>">
      <button type="submit" class="btn btn-primary" <?= $allOk ? '' : 'disabled' ?>>
        Suivant →
      </button>
    </form>
    <?php
}

// ─── Step 2: Database ─────────────────────────────────────────────────────────
function renderStepDatabase(): void
{
    ?>
    <h2>Étape 2 — Base de données MySQL</h2>
    <p>Renseignez les paramètres de connexion à votre serveur MySQL. La base de données sera créée si elle n'existe pas encore.</p>

    <form method="POST">
      <input type="hidden" name="step" value="<?= S_DATABASE ?>">

      <div class="form-grid">
        <div class="field">
          <label for="db_host">Hôte <span class="req">*</span></label>
          <input type="text" id="db_host" name="db_host" value="<?= sess('db', 'host', 'localhost') ?>" required>
        </div>
        <div class="field">
          <label for="db_port">Port <span class="req">*</span></label>
          <input type="number" id="db_port" name="db_port" value="<?= sess('db', 'port', '3306') ?>" min="1" max="65535" required>
        </div>
        <div class="field">
          <label for="db_user">Utilisateur <span class="req">*</span></label>
          <input type="text" id="db_user" name="db_user" value="<?= sess('db', 'user', 'partners_user') ?>" required autocomplete="off">
        </div>
        <div class="field">
          <label for="db_password">Mot de passe</label>
          <input type="password" id="db_password" name="db_password" autocomplete="new-password">
        </div>
        <div class="field field-full">
          <label for="db_name">Nom de la base de données <span class="req">*</span></label>
          <input type="text" id="db_name" name="db_name" value="<?= sess('db', 'name', 'partners_db') ?>" required>
        </div>
      </div>

      <div class="btn-row">
        <a href="?step=<?= S_REQUIREMENTS ?>" class="btn btn-outline">← Retour</a>
        <button type="submit" class="btn btn-primary">Tester et continuer →</button>
      </div>
    </form>
    <?php
}

// ─── Step 3: Site / Partner ───────────────────────────────────────────────────
function renderStepSite(): void
{
    ?>
    <h2>Étape 3 — Site &amp; Partenaire</h2>
    <p>Ces informations définissent le partenaire principal (votre agence) visible sur le site.</p>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="step" value="<?= S_SITE ?>">

      <h3 class="section-title">Identité</h3>
      <div class="form-grid">
        <div class="field field-full">
          <label for="site_name">Nom du site / agence <span class="req">*</span></label>
          <input type="text" id="site_name" name="site_name" value="<?= sess('site', 'name') ?>" placeholder="Ex : Sam Chlo Laure Vacances" required>
        </div>
        <div class="field">
          <label for="subdomain">Sous-domaine <span class="req">*</span></label>
          <input type="text" id="subdomain" name="subdomain" value="<?= sess('site', 'subdomain', 'default') ?>" placeholder="ex: partner1" required>
          <small>Uniquement lettres minuscules, chiffres et tirets.</small>
        </div>
        <div class="field">
          <label for="site_email">Email de contact <span class="req">*</span></label>
          <input type="email" id="site_email" name="site_email" value="<?= sess('site', 'email') ?>" required>
        </div>
      </div>

      <h3 class="section-title">Logo</h3>
      <div class="form-grid">
        <div class="field field-full">
          <label for="logo_file">Uploader un logo (JPG, PNG, SVG, max 2 Mo)</label>
          <input type="file" id="logo_file" name="logo_file" accept="image/*">
        </div>
        <div class="field field-full">
          <label for="logo_url">— ou — URL du logo</label>
          <input type="url" id="logo_url" name="logo_url" value="<?= sess('site', 'logo_url') ?>" placeholder="https://example.com/logo.png">
        </div>
      </div>

      <h3 class="section-title">Apparence &amp; tarification</h3>
      <div class="form-grid">
        <div class="field">
          <label for="primary_color">Couleur principale</label>
          <div style="display:flex;gap:.5rem;align-items:center;">
            <input type="color" id="primary_color" name="primary_color" value="<?= sess('site', 'primary_color', '#E61E4D') ?>" style="height:2.5rem;width:4rem;padding:.2rem;">
            <input type="text" id="primary_color_text" value="<?= sess('site', 'primary_color', '#E61E4D') ?>" style="flex:1;" readonly>
          </div>
        </div>
        <div class="field">
          <label for="markup">Majoration des prix Lodgify (%)</label>
          <input type="number" id="markup" name="markup" value="<?= sess('site', 'markup', '0') ?>" min="0" max="100" step="0.01">
        </div>
      </div>

      <h3 class="section-title">Intégration Lodgify</h3>
      <div class="form-grid">
        <div class="field field-full">
          <label for="lodgify_key">Clé API Lodgify</label>
          <input type="text" id="lodgify_key" name="lodgify_key" value="<?= sess('site', 'lodgify_key') ?>" placeholder="Votre clé API Lodgify" autocomplete="off">
          <small>Laissez vide pour configurer plus tard.</small>
        </div>
        <div class="field field-full">
          <label for="lodgify_url">URL base API Lodgify</label>
          <input type="url" id="lodgify_url" name="lodgify_url" value="<?= sess('site', 'lodgify_url', 'https://api.lodgify.com/v2') ?>">
        </div>
      </div>

      <h3 class="section-title">URLs de l'application</h3>
      <div class="form-grid">
        <div class="field">
          <label for="app_url">URL du backend (API)</label>
          <input type="url" id="app_url" name="app_url" value="<?= sess('site', 'app_url', 'http://localhost:3000') ?>">
        </div>
        <div class="field">
          <label for="cors_origin">Origine(s) frontend autorisée(s) (CORS)</label>
          <input type="text" id="cors_origin" name="cors_origin" value="<?= sess('site', 'cors_origin', 'http://localhost:5173') ?>">
          <small>Séparez plusieurs origines par des virgules.</small>
        </div>
      </div>

      <div class="btn-row">
        <a href="?step=<?= S_DATABASE ?>" class="btn btn-outline">← Retour</a>
        <button type="submit" class="btn btn-primary">Suivant →</button>
      </div>
    </form>
    <script>
    document.getElementById('primary_color').addEventListener('input', function() {
        document.getElementById('primary_color_text').value = this.value;
    });
    </script>
    <?php
}

// ─── Step 4: Admin account ────────────────────────────────────────────────────
function renderStepAdmin(): void
{
    ?>
    <h2>Étape 4 — Compte administrateur</h2>
    <p>Ce compte vous permettra de vous connecter à l'interface d'administration du site.</p>

    <form method="POST">
      <input type="hidden" name="step" value="<?= S_ADMIN ?>">

      <div class="form-grid">
        <div class="field field-full">
          <label for="admin_email">Email admin <span class="req">*</span></label>
          <input type="email" id="admin_email" name="admin_email"
                 value="<?= sess('admin', 'email', 'admin@mauritius-booking.com') ?>" required>
        </div>
        <div class="field">
          <label for="admin_password">Mot de passe <span class="req">*</span></label>
          <input type="password" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password">
          <small>8 caractères minimum.</small>
        </div>
        <div class="field">
          <label for="admin_confirm">Confirmer le mot de passe <span class="req">*</span></label>
          <input type="password" id="admin_confirm" name="admin_confirm" required minlength="8" autocomplete="new-password">
        </div>
      </div>

      <div class="btn-row">
        <a href="?step=<?= S_SITE ?>" class="btn btn-outline">← Retour</a>
        <button type="submit" class="btn btn-primary">Suivant →</button>
      </div>
    </form>
    <?php
}

// ─── Step 5: SMTP ─────────────────────────────────────────────────────────────
function renderStepSmtp(): void
{
    ?>
    <h2>Étape 5 — Configuration email (SMTP)</h2>
    <p>Configurez le serveur SMTP pour l'envoi des emails de réservation. Ces paramètres peuvent être modifiés plus tard dans le panel admin.</p>

    <div class="alert alert-info">
      💡 Pour un environnement de développement, laissez les valeurs par défaut et lancez <code>docker-compose up -d mailhog</code> pour intercepter les emails sur <a href="http://localhost:8025" target="_blank">localhost:8025</a>.
    </div>

    <form method="POST">
      <input type="hidden" name="step" value="<?= S_SMTP ?>">

      <div class="form-grid">
        <div class="field">
          <label for="smtp_host">Hôte SMTP</label>
          <input type="text" id="smtp_host" name="smtp_host" value="<?= sess('smtp', 'host', 'localhost') ?>">
        </div>
        <div class="field">
          <label for="smtp_port">Port SMTP</label>
          <input type="number" id="smtp_port" name="smtp_port" value="<?= sess('smtp', 'port', '1025') ?>" min="1" max="65535">
        </div>
        <div class="field">
          <label for="smtp_user">Utilisateur SMTP</label>
          <input type="text" id="smtp_user" name="smtp_user" value="<?= sess('smtp', 'user') ?>" autocomplete="off">
        </div>
        <div class="field">
          <label for="smtp_password">Mot de passe SMTP</label>
          <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password">
        </div>
      </div>

      <div class="btn-row">
        <a href="?step=<?= S_ADMIN ?>" class="btn btn-outline">← Retour</a>
        <button type="submit" class="btn btn-primary">Suivant →</button>
      </div>
    </form>
    <?php
}

// ─── Step 6: Confirm & install ────────────────────────────────────────────────
function renderStepConfirm(): void
{
    $db   = $_SESSION['db']   ?? [];
    $site = $_SESSION['site'] ?? [];
    $admin= $_SESSION['admin']?? [];
    $smtp = $_SESSION['smtp'] ?? [];
    ?>
    <h2>Étape 6 — Récapitulatif &amp; installation</h2>
    <p>Vérifiez les informations ci-dessous avant de lancer l'installation.</p>

    <div class="summary">
      <div class="summary-section">
        <h3>Base de données</h3>
        <dl>
          <dt>Hôte</dt><dd><?= h(($db['host'] ?? '') . ':' . ($db['port'] ?? '')) ?></dd>
          <dt>Utilisateur</dt><dd><?= h($db['user'] ?? '') ?></dd>
          <dt>Base de données</dt><dd><?= h($db['name'] ?? '') ?></dd>
        </dl>
      </div>
      <div class="summary-section">
        <h3>Partenaire</h3>
        <dl>
          <dt>Nom</dt><dd><?= h($site['name'] ?? '') ?></dd>
          <dt>Sous-domaine</dt><dd><?= h($site['subdomain'] ?? '') ?></dd>
          <dt>Email</dt><dd><?= h($site['email'] ?? '') ?></dd>
          <dt>Couleur</dt>
          <dd>
            <span style="display:inline-block;width:1rem;height:1rem;background:<?= h($site['primary_color'] ?? '#E61E4D') ?>;border-radius:3px;vertical-align:middle;margin-right:.3rem;border:1px solid #ccc;"></span>
            <?= h($site['primary_color'] ?? '') ?>
          </dd>
          <?php if (!empty($site['logo_url'])): ?>
          <dt>Logo</dt><dd><?= h($site['logo_url']) ?></dd>
          <?php endif; ?>
          <dt>Majoration</dt><dd><?= h((string)($site['markup'] ?? 0)) ?>%</dd>
          <dt>URL backend</dt><dd><?= h($site['app_url'] ?? '') ?></dd>
          <dt>CORS</dt><dd><?= h($site['cors_origin'] ?? '') ?></dd>
        </dl>
      </div>
      <div class="summary-section">
        <h3>Admin</h3>
        <dl>
          <dt>Email</dt><dd><?= h($admin['email'] ?? '') ?></dd>
          <dt>Mot de passe</dt><dd>••••••••</dd>
        </dl>
      </div>
      <div class="summary-section">
        <h3>SMTP</h3>
        <dl>
          <dt>Hôte</dt><dd><?= h(($smtp['host'] ?? '') . ':' . ($smtp['port'] ?? '')) ?></dd>
          <?php if (!empty($smtp['user'])): ?>
          <dt>Utilisateur</dt><dd><?= h($smtp['user']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="alert alert-warn">
      ⚠️ L'installation va : créer la base de données, exécuter les migrations SQL, créer le partenaire et l'utilisateur admin, et générer le fichier <code>.env</code>.
    </div>

    <form method="POST">
      <input type="hidden" name="step" value="<?= S_CONFIRM ?>">
      <div class="btn-row">
        <a href="?step=<?= S_SMTP ?>" class="btn btn-outline">← Retour</a>
        <button type="submit" class="btn btn-success">🚀 Lancer l'installation</button>
      </div>
    </form>
    <?php
}

// ─── Step 7: Done ─────────────────────────────────────────────────────────────
function renderStepDone(): void
{
    ?>
    <div style="text-align:center;padding:2rem 1rem;">
      <div style="font-size:4rem;margin-bottom:1rem;">🎉</div>
      <h2 style="color:#16a34a;">Installation réussie !</h2>
      <p>Votre site <strong>samchlolaurepartners</strong> est prêt.</p>

      <div class="next-steps">
        <h3>Prochaines étapes</h3>
        <ol>
          <li>
            <strong>Démarrez l'application</strong> :
            <pre><code>npm install
npm run dev:backend   # port 3000
npm run dev:frontend  # port 5173</code></pre>
            <span>— ou en production :</span>
            <pre><code>docker-compose up -d</code></pre>
          </li>
          <li>
            <strong>Connectez-vous</strong> à l'interface admin sur
            <a href="http://localhost:5173/login" target="_blank">http://localhost:5173/login</a>
            avec les identifiants que vous venez de définir.
          </li>
          <li>
            <strong>Renseignez votre clé API Lodgify</strong> dans <code>.env</code>
            si vous ne l'avez pas fournie pendant l'installation.
          </li>
          <li>
            <strong class="danger">Supprimez <code>install.php</code></strong> du serveur immédiatement.
            <pre><code>rm install.php</code></pre>
          </li>
        </ol>
      </div>
    </div>
    <?php
}


// ─── Shared CSS & head ────────────────────────────────────────────────────────
function renderHead(): void
{
    ?>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f0f4f8;
    color: #1e293b;
    min-height: 100vh;
    padding: 2rem 1rem;
  }

  .wrapper {
    max-width: 820px;
    margin: 0 auto;
  }

  /* Logo bar */
  .logo-bar {
    text-align: center;
    font-size: 1.25rem;
    margin-bottom: 1.5rem;
    color: #334155;
  }

  /* Progress bar */
  .progress-bar-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 2rem;
    gap: 0;
    overflow-x: auto;
    padding: .5rem 0;
  }

  .progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .3rem;
    flex-shrink: 0;
  }

  .step-circle {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .8rem;
    font-weight: 700;
    color: #94a3b8;
    transition: all .2s;
  }

  .progress-step.active .step-circle {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #fff;
  }

  .progress-step.done .step-circle {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
  }

  .step-label {
    font-size: .65rem;
    color: #94a3b8;
    white-space: nowrap;
    font-weight: 500;
  }

  .progress-step.active .step-label { color: #4f46e5; font-weight: 700; }
  .progress-step.done   .step-label { color: #16a34a; }

  .progress-connector {
    height: 2px;
    width: 2.5rem;
    background: #cbd5e1;
    margin: 0 .25rem;
    margin-bottom: 1.1rem;
    flex-shrink: 0;
    transition: background .2s;
  }

  .progress-connector.done { background: #16a34a; }

  /* Card */
  .card {
    background: #fff;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 4px 12px rgba(0,0,0,.05);
    margin-bottom: 1rem;
  }

  .card h2 {
    font-size: 1.35rem;
    color: #1e293b;
    margin-bottom: .5rem;
  }

  .card > p {
    color: #64748b;
    margin-bottom: 1.5rem;
    line-height: 1.6;
  }

  /* Section title inside card */
  .section-title {
    font-size: .9rem;
    font-weight: 600;
    color: #4f46e5;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin: 1.5rem 0 .75rem;
    padding-bottom: .4rem;
    border-bottom: 1px solid #e2e8f0;
  }

  .section-title:first-of-type { margin-top: 0; }

  /* Alerts */
  .alert {
    padding: .85rem 1rem;
    border-radius: 8px;
    margin-bottom: 1.25rem;
    font-size: .9rem;
    line-height: 1.5;
  }

  .alert p { margin: .2rem 0; }

  .alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
  }

  .alert-warn {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
  }

  .alert-info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
  }

  /* Form grid */
  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  @media (max-width: 600px) {
    .form-grid { grid-template-columns: 1fr; }
  }

  .field { display: flex; flex-direction: column; gap: .35rem; }
  .field-full { grid-column: 1 / -1; }

  label {
    font-size: .85rem;
    font-weight: 600;
    color: #374151;
  }

  .req { color: #dc2626; }

  input[type="text"],
  input[type="email"],
  input[type="password"],
  input[type="url"],
  input[type="number"],
  input[type="file"] {
    padding: .55rem .75rem;
    border: 1.5px solid #d1d5db;
    border-radius: 7px;
    font-size: .95rem;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s;
    width: 100%;
  }

  input:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79,70,229,.12);
  }

  input[type="file"] { padding: .4rem .6rem; }

  small {
    font-size: .78rem;
    color: #9ca3af;
  }

  /* Buttons */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .6rem 1.4rem;
    border-radius: 8px;
    font-size: .95rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: opacity .15s, transform .1s;
  }

  .btn:hover { opacity: .9; }
  .btn:active { transform: scale(.97); }
  .btn:disabled { opacity: .45; cursor: not-allowed; }

  .btn-primary { background: #4f46e5; color: #fff; }
  .btn-success { background: #16a34a; color: #fff; }
  .btn-outline  {
    background: #fff;
    color: #374151;
    border: 1.5px solid #d1d5db;
  }

  .btn-row {
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    margin-top: 1.75rem;
  }

  /* Requirements table */
  .check-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
  }

  .check-table td {
    padding: .65rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    font-size: .9rem;
  }

  .check-table .ok   { color: #16a34a; font-weight: 700; width: 8rem; }
  .check-table .fail { color: #dc2626; font-weight: 700; width: 8rem; }

  /* Summary */
  .summary {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
  }

  @media (max-width: 600px) { .summary { grid-template-columns: 1fr; } }

  .summary-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
  }

  .summary-section h3 {
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #4f46e5;
    font-weight: 700;
    margin-bottom: .75rem;
  }

  .summary dl { display: grid; grid-template-columns: auto 1fr; gap: .3rem .75rem; }
  .summary dt { font-size: .8rem; color: #64748b; font-weight: 600; }
  .summary dd { font-size: .85rem; color: #1e293b; word-break: break-word; }

  /* Next steps */
  .next-steps {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 1.5rem;
    margin: 1.5rem auto;
    max-width: 600px;
    text-align: left;
  }

  .next-steps h3 {
    font-size: 1rem;
    color: #15803d;
    margin-bottom: 1rem;
  }

  .next-steps ol { padding-left: 1.2rem; }
  .next-steps li {
    margin-bottom: 1rem;
    font-size: .9rem;
    line-height: 1.6;
  }

  .next-steps pre {
    background: #1e293b;
    color: #e2e8f0;
    padding: .5rem .75rem;
    border-radius: 6px;
    margin: .4rem 0;
    overflow-x: auto;
  }

  .next-steps code { font-size: .82rem; font-family: monospace; }
  .danger { color: #dc2626; }

  /* Footer note */
  .footer-note {
    text-align: center;
    font-size: .8rem;
    color: #94a3b8;
    margin-top: .5rem;
  }

  code {
    background: #f1f5f9;
    padding: .1rem .3rem;
    border-radius: 3px;
    font-size: .88em;
    font-family: monospace;
  }
</style>
    <?php
}
