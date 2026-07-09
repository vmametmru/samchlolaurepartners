<?php
/**
 * install/install.php — Assistant d'installation pour samchlolaurepartners.
 *
 * Ouvrez-le dans votre navigateur via https://votre-domaine/install/install.php.
 * ⚠️ Supprimez le dossier install/ après installation pour des raisons de sécurité.
 */

declare(strict_types=1);

session_start();

define('BASE_DIR', dirname(__DIR__));
define('ENV_FILE', BASE_DIR . '/.env');
define('MIGRATIONS_DIR', BASE_DIR . '/db/migrations');
define('LOGO_UPLOAD_DIR', BASE_DIR . '/images/logo');

if (is_file(ENV_FILE) && !isset($_GET['reinstall'])) {
    renderPage('Installation déjà effectuée', '<p>Le fichier <code>.env</code> existe déjà.</p><p><a class="btn" href="?reinstall=1">Relancer l\'installation</a></p>');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        runInstallation();
        session_destroy();
        renderPage('Installation terminée', '<p>✅ Installation terminée avec succès.</p><ul><li>Le webroot doit déjà pointer sur la racine du projet.</li><li>Supprimez le dossier <code>install/</code> après vérification.</li><li>Ajoutez un cron: <code>php ' . htmlspecialchars(BASE_DIR . '/bin/run-scheduler.php', ENT_QUOTES) . '</code></li></ul>');
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

renderForm($errors);

function runInstallation(): void
{
    $db = [
        'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'password' => (string) ($_POST['db_password'] ?? ''),
        'name' => trim((string) ($_POST['db_name'] ?? '')),
    ];
    $site = [
        'name' => trim((string) ($_POST['site_name'] ?? '')),
        'subdomain' => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string) ($_POST['subdomain'] ?? 'default')))) ?: 'default',
        'email' => trim((string) ($_POST['site_email'] ?? '')),
        'logo_url' => trim((string) ($_POST['logo_url'] ?? '')),
        'primary_color' => trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
        'markup' => max(0.0, (float) ($_POST['markup'] ?? 0)),
        'lodgify_key' => trim((string) ($_POST['lodgify_key'] ?? '')),
        'lodgify_url' => trim((string) ($_POST['lodgify_url'] ?? 'https://api.lodgify.com/v2')),
        'cors_origin' => trim((string) ($_POST['cors_origin'] ?? 'http://localhost:8080')),
        'app_url' => trim((string) ($_POST['app_url'] ?? 'http://localhost:8080')),
    ];
    $admin = [
        'email' => trim((string) ($_POST['admin_email'] ?? '')),
        'password' => (string) ($_POST['admin_password'] ?? ''),
        'confirm' => (string) ($_POST['admin_confirm'] ?? ''),
    ];
    $smtp = [
        'host' => trim((string) ($_POST['smtp_host'] ?? 'localhost')),
        'port' => (int) ($_POST['smtp_port'] ?? 1025),
        'user' => trim((string) ($_POST['smtp_user'] ?? '')),
        'password' => (string) ($_POST['smtp_password'] ?? ''),
    ];

    validateInput($db, $site, $admin);

    if (!empty($_FILES['logo_file']['tmp_name']) && (int) $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $site['logo_url'] = handleLogoUpload($_FILES['logo_file']);
    }

    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $db['name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS db_migrations (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');

    $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $filename = basename($file);
        $check = $pdo->prepare('SELECT id FROM db_migrations WHERE filename = ? LIMIT 1');
        $check->execute([$filename]);
        if ($check->fetch()) {
            continue;
        }
        $sql = (string) file_get_contents($file);
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO db_migrations (filename) VALUES (?)')->execute([$filename]);
    }

    $stmt = $pdo->prepare('INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), logo_url = VALUES(logo_url), primary_color = VALUES(primary_color), email = VALUES(email), markup_percent = VALUES(markup_percent), smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port), smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass)');
    $stmt->execute([$site['subdomain'], $site['name'], $site['logo_url'] !== '' ? $site['logo_url'] : null, $site['primary_color'], $site['email'], $site['markup'], $smtp['host'] !== '' ? $smtp['host'] : null, $smtp['port'] ?: null, $smtp['user'] !== '' ? $smtp['user'] : null, $smtp['password'] !== '' ? $smtp['password'] : null]);
    $partnerId = (int) $pdo->lastInsertId();
    if ($partnerId === 0) {
        $lookup = $pdo->prepare('SELECT id FROM partners WHERE subdomain = ? LIMIT 1');
        $lookup->execute([$site['subdomain']]);
        $partnerId = (int) $lookup->fetchColumn();
    }

    $passwordHash = password_hash($admin['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('INSERT INTO users (email, password_hash, role, partner_id) VALUES (?, ?, "admin", NULL) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)')->execute([$admin['email'], $passwordHash]);

    foreach (defaultTemplates($partnerId, $site['name']) as $template) {
        $pdo->prepare('INSERT IGNORE INTO email_templates (partner_id, type, subject, body_html) VALUES (?, ?, ?, ?)')->execute([$partnerId, $template['type'], $template['subject'], $template['body_html']]);
    }
    $pdo->prepare('INSERT INTO app_versions (version, deployed_by, notes) VALUES ("1.0.0", ?, "Installation initiale via install.php")')->execute([$admin['email']]);

    $env = buildEnv($db, $site, $admin, $smtp);
    if (file_put_contents(ENV_FILE, $env) === false) {
        throw new RuntimeException('Impossible d\'écrire le fichier .env. Vérifiez les permissions.');
    }
}

function validateInput(array $db, array $site, array $admin): void
{
    $errors = [];
    if ($db['host'] === '' || $db['user'] === '' || $db['name'] === '') $errors[] = 'Hôte, utilisateur et nom de base obligatoires.';
    if ($site['name'] === '') $errors[] = 'Le nom du site est obligatoire.';
    if (!filter_var($site['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Une adresse email valide est obligatoire.';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $site['primary_color'])) $errors[] = 'Couleur principale invalide.';
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email admin valide obligatoire.';
    if (strlen($admin['password']) < 8) $errors[] = 'Le mot de passe admin doit contenir au moins 8 caractères.';
    if ($admin['password'] !== $admin['confirm']) $errors[] = 'Les mots de passe admin ne correspondent pas.';
    if ($errors !== []) throw new RuntimeException(implode(' ', $errors));
}

function handleLogoUpload(array $file): string
{
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new RuntimeException('Format de logo non autorisé.');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('Le logo ne doit pas dépasser 2 Mo.');
    if (!is_dir(LOGO_UPLOAD_DIR) && !mkdir(LOGO_UPLOAD_DIR, 0755, true)) throw new RuntimeException('Impossible de créer le répertoire des logos.');
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_' . time() . '.' . $ext;
    $destination = LOGO_UPLOAD_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('Impossible de sauvegarder le logo.');
    return '/images/logo/' . $filename;
}

function buildEnv(array $db, array $site, array $admin, array $smtp): string
{
    $secret = bin2hex(random_bytes(32));
    return implode("\n", [
        '# Generated by install.php on ' . date('Y-m-d H:i:s'),
        'APP_ENV=production',
        'PORT=8080',
        'APP_URL=' . quoteEnv($site['app_url']),
        '',
        'DB_HOST=' . quoteEnv($db['host']),
        'DB_PORT=' . $db['port'],
        'DB_USER=' . quoteEnv($db['user']),
        'DB_PASSWORD=' . quoteEnv($db['password']),
        'DB_NAME=' . quoteEnv($db['name']),
        '',
        'JWT_SECRET=' . $secret,
        'LODGIFY_API_KEY=' . quoteEnv($site['lodgify_key']),
        'LODGIFY_BASE_URL=' . quoteEnv($site['lodgify_url']),
        '',
        'SMTP_HOST=' . quoteEnv($smtp['host']),
        'SMTP_PORT=' . $smtp['port'],
        'SMTP_USER=' . quoteEnv($smtp['user']),
        'SMTP_PASS=' . quoteEnv($smtp['password']),
        'SMTP_FROM_EMAIL=' . quoteEnv($site['email']),
        'SMTP_FROM_NAME=' . quoteEnv($site['name']),
        '',
        'ADMIN_EMAIL=' . quoteEnv($admin['email']),
        'CORS_ORIGIN=' . quoteEnv($site['cors_origin']),
        '',
    ]) . "\n";
}

function quoteEnv(string $value): string
{
    if ($value === '') return '';
    if (preg_match('/[\s#"\'\\\\]/', $value)) return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    return $value;
}

function defaultTemplates(int $partnerId, string $partnerName): array
{
    return [
        ['partner_id' => $partnerId, 'type' => 'REQUEST_RECEIVED_PARTNER', 'subject' => 'Nouvelle demande de réservation - {{nom_client}}', 'body_html' => '<h2>Nouvelle demande de réservation</h2><p><strong>Client :</strong> {{nom_client}} ({{email_client}})</p><p><strong>Hébergement :</strong> {{hebergement}}</p><p><strong>Dates :</strong> {{dates}}</p><p><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</p><p><strong>Message :</strong><br>{{message}}</p><hr><p>Veuillez traiter cette demande depuis votre espace partenaire.</p>'],
        ['partner_id' => $partnerId, 'type' => 'REQUEST_RECEIVED_CLIENT', 'subject' => 'Votre demande de réservation a bien été reçue', 'body_html' => '<h2>Votre demande a bien été reçue</h2><p>Bonjour {{nom_client}},</p><p>Nous avons bien reçu votre demande pour <strong>{{hebergement}}</strong> du <strong>{{date_arrivee}}</strong> au <strong>{{date_depart}}</strong>.</p><p>Notre équipe vous contactera dans les plus brefs délais pour confirmer votre réservation.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'],
        ['partner_id' => $partnerId, 'type' => 'RESERVATION_CONFIRMED', 'subject' => 'Votre réservation est confirmée ! 🎉', 'body_html' => '<h2>Réservation confirmée</h2><p>Bonjour {{nom_client}},</p><p>Nous avons le plaisir de vous confirmer votre réservation :</p><ul><li><strong>Hébergement :</strong> {{hebergement}}</li><li><strong>Arrivée :</strong> {{date_arrivee}}</li><li><strong>Départ :</strong> {{date_depart}}</li><li><strong>Voyageurs :</strong> {{adultes}} adulte(s), {{enfants}} enfant(s)</li></ul>{{notes}}<p>À très bientôt à l\'île Maurice !</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'],
        ['partner_id' => $partnerId, 'type' => 'RESERVATION_CANCELLED', 'subject' => 'Annulation de votre réservation', 'body_html' => '<h2>Votre réservation a été annulée</h2><p>Bonjour {{nom_client}},</p><p>Nous vous informons que votre réservation pour <strong>{{hebergement}}</strong> ({{dates}}) a malheureusement dû être annulée.</p><p>N\'hésitez pas à nous contacter pour explorer d\'autres options.</p><p>Cordialement,<br><strong>{{partenaire}}</strong></p>'],
        ['partner_id' => $partnerId, 'type' => 'REMINDER', 'subject' => 'Rappel : votre séjour approche ! 🌴', 'body_html' => '<h2>Votre séjour approche !</h2><p>Bonjour {{nom_client}},</p><p>Nous vous rappelons que votre séjour à <strong>{{hebergement}}</strong> approche :</p><ul><li><strong>Arrivée :</strong> {{date_arrivee}}</li><li><strong>Départ :</strong> {{date_depart}}</li></ul><p>N\'hésitez pas à nous contacter si vous avez des questions.</p><p>À bientôt,<br><strong>{{partenaire}}</strong></p>'],
    ];
}

function renderForm(array $errors): void
{
    ob_start(); ?>
    <?php if ($errors): ?><div class="errors"><?php foreach ($errors as $error): ?><p>• <?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endforeach; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="install-grid">
      <fieldset><legend>Base de données</legend><label>Hôte<input name="db_host" value="<?= htmlspecialchars((string) ($_POST['db_host'] ?? 'localhost'), ENT_QUOTES) ?>"></label><label>Port<input name="db_port" type="number" value="<?= htmlspecialchars((string) ($_POST['db_port'] ?? '3306'), ENT_QUOTES) ?>"></label><label>Utilisateur<input name="db_user" value="<?= htmlspecialchars((string) ($_POST['db_user'] ?? ''), ENT_QUOTES) ?>"></label><label>Mot de passe<input name="db_password" type="password"></label><label>Nom de la base<input name="db_name" value="<?= htmlspecialchars((string) ($_POST['db_name'] ?? 'partners_db'), ENT_QUOTES) ?>"></label></fieldset>
      <fieldset><legend>Site / partenaire</legend><label>Nom du site<input name="site_name" value="<?= htmlspecialchars((string) ($_POST['site_name'] ?? ''), ENT_QUOTES) ?>"></label><label>Sous-domaine<input name="subdomain" value="<?= htmlspecialchars((string) ($_POST['subdomain'] ?? 'default'), ENT_QUOTES) ?>"></label><label>Email de contact<input name="site_email" type="email" value="<?= htmlspecialchars((string) ($_POST['site_email'] ?? ''), ENT_QUOTES) ?>"></label><label>URL logo<input name="logo_url" value="<?= htmlspecialchars((string) ($_POST['logo_url'] ?? ''), ENT_QUOTES) ?>"></label><label>Logo à téléverser<input name="logo_file" type="file"></label><label>Couleur principale<input name="primary_color" type="color" value="<?= htmlspecialchars((string) ($_POST['primary_color'] ?? '#E61E4D'), ENT_QUOTES) ?>"></label><label>Markup %<input name="markup" type="number" step="0.5" value="<?= htmlspecialchars((string) ($_POST['markup'] ?? '0'), ENT_QUOTES) ?>"></label><label>Clé Lodgify<input name="lodgify_key" value="<?= htmlspecialchars((string) ($_POST['lodgify_key'] ?? ''), ENT_QUOTES) ?>"></label><label>URL Lodgify<input name="lodgify_url" value="<?= htmlspecialchars((string) ($_POST['lodgify_url'] ?? 'https://api.lodgify.com/v2'), ENT_QUOTES) ?>"></label><label>Origines CORS<input name="cors_origin" value="<?= htmlspecialchars((string) ($_POST['cors_origin'] ?? 'http://localhost:8080'), ENT_QUOTES) ?>"></label><label>URL de l'application<input name="app_url" value="<?= htmlspecialchars((string) ($_POST['app_url'] ?? 'http://localhost:8080'), ENT_QUOTES) ?>"></label></fieldset>
      <fieldset><legend>Administrateur</legend><label>Email admin<input name="admin_email" type="email" value="<?= htmlspecialchars((string) ($_POST['admin_email'] ?? ''), ENT_QUOTES) ?>"></label><label>Mot de passe<input name="admin_password" type="password"></label><label>Confirmation<input name="admin_confirm" type="password"></label></fieldset>
      <fieldset><legend>SMTP</legend><label>Hôte SMTP<input name="smtp_host" value="<?= htmlspecialchars((string) ($_POST['smtp_host'] ?? 'localhost'), ENT_QUOTES) ?>"></label><label>Port SMTP<input name="smtp_port" type="number" value="<?= htmlspecialchars((string) ($_POST['smtp_port'] ?? '1025'), ENT_QUOTES) ?>"></label><label>Utilisateur SMTP<input name="smtp_user" value="<?= htmlspecialchars((string) ($_POST['smtp_user'] ?? ''), ENT_QUOTES) ?>"></label><label>Mot de passe SMTP<input name="smtp_password" type="password"></label></fieldset>
      <button class="btn" type="submit">Installer</button>
    </form>
    <?php $content = ob_get_clean();
    renderPage('Installation', $content);
}

function renderPage(string $title, string $content): void
{
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES) ?></title><style>body{font-family:Arial,sans-serif;background:#f7f7f8;color:#1f2937;margin:0}.wrap{max-width:960px;margin:0 auto;padding:2rem}h1{margin-top:0}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:1.5rem;box-shadow:0 10px 30px rgba(15,23,42,.05)}.install-grid{display:grid;gap:1rem}.install-grid fieldset{border:1px solid #e5e7eb;border-radius:14px;padding:1rem}.install-grid label{display:block;margin:.6rem 0}.install-grid input{width:100%;padding:.75rem;border:1px solid #d1d5db;border-radius:10px;box-sizing:border-box}.btn{display:inline-flex;padding:.85rem 1.1rem;background:#E61E4D;color:#fff;border-radius:12px;text-decoration:none;border:0;cursor:pointer;font-weight:700}.errors{background:#fee2e2;color:#991b1b;border-radius:12px;padding:1rem;margin-bottom:1rem}</style></head><body><div class="wrap"><div class="panel"><h1><?= htmlspecialchars($title, ENT_QUOTES) ?></h1><?= $content ?></div></div></body></html><?php
}
