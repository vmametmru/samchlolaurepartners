<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Settings;
use App\Flash;
use App\HttpException;
use App\LodgifyClient;
use App\Scheduler;
use App\Tenant;
use App\View;
use PDO;
use Throwable;

final class PageController extends Controller
{
    public static function home(): void
    {
        $properties = [];
        $searched = false;
        if (!empty($_GET['checkin']) && !empty($_GET['checkout'])) {
            $searched = true;
            try {
                $properties = (new LodgifyClient())->getProperties();
            } catch (Throwable $e) {
                Flash::set('Impossible de charger les hébergements pour le moment.', 'error');
            }
        }
        View::render('pages/home', [
            'pageTitle' => 'Accueil',
            'properties' => $properties,
            'searched' => $searched,
            'search' => [
                'checkin' => $_GET['checkin'] ?? '',
                'checkout' => $_GET['checkout'] ?? '',
                'adults' => $_GET['adults'] ?? 2,
                'children' => $_GET['children'] ?? 0,
                'nationality' => $_GET['nationality'] ?? '',
            ],
        ]);
    }

    public static function properties(): void
    {
        $properties = [];
        $query = trim((string) ($_GET['q'] ?? ''));
        try {
            $properties = (new LodgifyClient())->getProperties();
        } catch (Throwable $e) {
            Flash::set('Impossible de charger les hébergements. Vérifiez la configuration Lodgify.', 'error');
        }
        if ($query !== '') {
            $properties = array_values(array_filter($properties, static function (array $property) use ($query): bool {
                $needle = mb_strtolower($query);
                return str_contains(mb_strtolower((string) $property['name']), $needle)
                    || str_contains(mb_strtolower((string) $property['description']), $needle);
            }));
        }
        View::render('pages/properties', ['pageTitle' => 'Hébergements', 'properties' => $properties, 'query' => $query]);
    }

    public static function propertyDetail(int $id): void
    {
        $client = new LodgifyClient();
        $today = date('Y-m-d');
        $nextMonth = date('Y-m-d', strtotime('+30 days'));
        try {
            $property = $client->getProperty($id);
            $availability = $client->getAvailability($id, $today, $nextMonth);
            $rates = self::publicRates($client, $id, $today, $nextMonth);
        } catch (Throwable $e) {
            error_log('Property detail load failed for id ' . $id . ': ' . $e->getMessage());
            throw new HttpException(404, 'Not Found', 'Hébergement introuvable');
        }
        View::render('pages/property-detail', [
            'pageTitle' => (string) $property['name'],
            'property' => $property,
            'availability' => $availability,
            'rates' => $rates,
            'today' => $today,
        ]);
    }

    public static function submitBooking(int $propertyId): never
    {
        $_POST['property_id'] = (string) $propertyId;
        $_POST['property_name'] = trim((string) ($_POST['property_name'] ?? ''));
        ob_start();
        ReservationsController::requestReservation();
        ob_end_clean();
    }

    public static function contact(): void
    {
        View::render('pages/contact', ['pageTitle' => 'Contact']);
    }

    public static function submitContact(): never
    {
        ob_start();
        ContactController::submit();
        ob_end_clean();
    }

    public static function login(): void
    {
        if (Auth::user()) {
            header('Location: /partner/dashboard');
            exit;
        }
        View::render('pages/login', ['pageTitle' => 'Connexion']);
    }

    public static function partnerDashboard(): void
    {
        $user = self::requirePartnerUser();
        $requests = ReservationsController::listForPartner((int) $user['partner_id']);
        View::render('pages/partner-dashboard', ['pageTitle' => 'Dashboard partenaire', 'requests' => $requests]);
    }

    public static function partnerReservations(): void
    {
        $user = self::requirePartnerUser();
        $filter = (string) ($_GET['filter'] ?? 'all');
        $reservations = ReservationsController::listForPartner((int) $user['partner_id']);
        if ($filter !== 'all') {
            $reservations = array_values(array_filter($reservations, static fn(array $row): bool => $row['status'] === $filter));
        }
        View::render('pages/partner-reservations', ['pageTitle' => 'Réservations', 'reservations' => $reservations, 'filter' => $filter]);
    }

    public static function partnerReservationDetail(int $id): void
    {
        $user = self::requirePartnerUser();
        $reservation = ReservationsController::findForPartner((int) $user['partner_id'], $id);
        if (!$reservation) {
            throw new HttpException(404, 'Not Found', 'Réservation introuvable');
        }
        View::render('pages/partner-reservation-detail', ['pageTitle' => 'Demande #' . $id, 'reservation' => $reservation]);
    }

    public static function partnerConfirmReservation(int $id): never
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        if (!ReservationsController::findForPartner($partnerId, $id)) {
            throw new HttpException(404, 'Not Found', 'Réservation introuvable');
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        Database::connection()->prepare(
            'INSERT INTO reservations (request_id, partner_id, confirmed_at, notes)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE confirmed_at = NOW(), cancelled_at = NULL, notes = VALUES(notes)'
        )->execute([$id, $partnerId, $notes !== '' ? $notes : null]);
        Database::connection()->prepare("UPDATE reservation_requests SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partnerId]);
        self::redirect('/partner/reservations/' . $id, 'Réservation confirmée.');
    }

    public static function partnerCancelReservation(int $id): never
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        if (!ReservationsController::findForPartner($partnerId, $id)) {
            throw new HttpException(404, 'Not Found', 'Réservation introuvable');
        }
        Database::connection()->prepare('UPDATE reservations SET cancelled_at = NOW() WHERE request_id = ?')->execute([$id]);
        Database::connection()->prepare("UPDATE reservation_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partnerId]);
        self::redirect('/partner/reservations/' . $id, 'Réservation annulée.', 'info');
    }

    public static function partnerTemplates(): void
    {
        $user = self::requirePartnerUser();
        $templates = EmailTemplatesController::listForPartner((int) $user['partner_id']);
        $selectedId = (int) ($_GET['id'] ?? ($templates[0]['id'] ?? 0));
        $selected = null;
        foreach ($templates as $template) {
            if ((int) $template['id'] === $selectedId) {
                $selected = $template;
                break;
            }
        }
        View::render('pages/partner-templates', ['pageTitle' => 'Templates email', 'templates' => $templates, 'selected' => $selected]);
    }

    public static function partnerSaveTemplate(int $id): never
    {
        $user = self::requirePartnerUser();
        Database::connection()->prepare('UPDATE email_templates SET subject = ?, body_html = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?')->execute([
            (string) ($_POST['subject'] ?? ''),
            (string) ($_POST['body_html'] ?? ''),
            $id,
            $user['partner_id'],
        ]);
        self::redirect('/partner/templates?id=' . $id, 'Template sauvegardé.');
    }

    public static function partnerSettings(): void
    {
        $user = self::requirePartnerUser();
        $partner = PartnersController::formData((int) $user['partner_id']);
        View::render('pages/partner-settings', ['pageTitle' => 'Paramètres partenaire', 'partnerData' => $partner]);
    }

    public static function partnerSaveSettings(): never
    {
        $user = self::requirePartnerUser();
        Database::connection()->prepare('UPDATE partners SET name = ?, email = ?, logo_url = ?, primary_color = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, updated_at = NOW() WHERE id = ?')->execute([
            trim((string) ($_POST['name'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            trim((string) ($_POST['logo_url'] ?? '')) ?: null,
            trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
            trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
            ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
            trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
            trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
            $user['partner_id'],
        ]);
        self::redirect('/partner/settings', 'Paramètres sauvegardés.');
    }

    public static function adminPartners(): void
    {
        self::requireAdminUser();
        $partners = Database::connection()->query('SELECT * FROM partners ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        View::render('pages/admin-partners', ['pageTitle' => 'Partenaires', 'partners' => $partners]);
    }

    public static function adminPartnerForm(?int $id = null): void
    {
        self::requireAdminUser();
        $partner = $id ? PartnersController::formData($id) : ['primary_color' => '#E61E4D', 'markup_percent' => 0, 'active' => 1];
        View::render('pages/admin-partner-form', ['pageTitle' => $id ? 'Modifier partenaire' : 'Nouveau partenaire', 'partnerData' => $partner, 'editing' => $id !== null]);
    }

    public static function adminSavePartner(?int $id = null): never
    {
        self::requireAdminUser();
        if ($id === null) {
            Database::connection()->prepare('INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                trim((string) ($_POST['subdomain'] ?? '')),
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['logo_url'] ?? '')) ?: null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                (float) ($_POST['markup_percent'] ?? 0),
                trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
                ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
                trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
                trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
                isset($_POST['active']) ? 1 : 0,
            ]);
        } else {
            Database::connection()->prepare('UPDATE partners SET name = ?, logo_url = ?, primary_color = ?, email = ?, markup_percent = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, active = ?, updated_at = NOW() WHERE id = ?')->execute([
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['logo_url'] ?? '')) ?: null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                (float) ($_POST['markup_percent'] ?? 0),
                trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
                ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
                trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
                trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
                isset($_POST['active']) ? 1 : 0,
                $id,
            ]);
        }
        self::redirect('/admin/partners', 'Partenaire sauvegardé.');
    }

    public static function adminDeletePartner(int $id): never
    {
        self::requireAdminUser();
        Database::connection()->prepare('UPDATE partners SET active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        self::redirect('/admin/partners', 'Partenaire désactivé.', 'info');
    }

    public static function adminFees(): void
    {
        self::requireAdminUser();
        $tax = Database::connection()->query('SELECT * FROM tourist_tax LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: ['per_person_per_night' => 0, 'applies_to_foreigners_only' => 1, 'applies_to_children' => 0];
        $cleaningFees = Database::connection()->query('SELECT * FROM cleaning_fees ORDER BY property_id')->fetchAll(PDO::FETCH_ASSOC);
        View::render('pages/admin-fees', ['pageTitle' => 'Frais & taxes', 'tax' => $tax, 'cleaningFees' => $cleaningFees]);
    }

    public static function adminSaveTax(): never
    {
        self::requireAdminUser();
        Database::connection()->prepare(
            'INSERT INTO tourist_tax (id, per_person_per_night, applies_to_foreigners_only, applies_to_children)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE per_person_per_night = VALUES(per_person_per_night), applies_to_foreigners_only = VALUES(applies_to_foreigners_only), applies_to_children = VALUES(applies_to_children), updated_at = NOW()'
        )->execute([
            (float) ($_POST['per_person_per_night'] ?? 0),
            isset($_POST['applies_to_foreigners_only']) ? 1 : 0,
            isset($_POST['applies_to_children']) ? 1 : 0,
        ]);
        self::redirect('/admin/fees', 'Taxe touristique sauvegardée.');
    }

    public static function adminVersions(): void
    {
        self::requireAdminUser();
        $versions = Database::connection()->query('SELECT * FROM app_versions ORDER BY deployed_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $migrations = Database::connection()->query('SELECT * FROM db_migrations ORDER BY applied_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        View::render('pages/admin-versions', ['pageTitle' => 'Versions', 'versions' => $versions, 'migrations' => $migrations]);
    }

    public static function adminDeployVersion(): never
    {
        $user = self::requireAdminUser();
        $version = trim((string) ($_POST['version'] ?? ''));
        if ($version === '') {
            throw new HttpException(400, 'Bad Request', 'Version requise.');
        }
        Database::connection()->prepare('INSERT INTO app_versions (version, deployed_by, notes) VALUES (?, ?, ?)')->execute([$version, $user['email'], trim((string) ($_POST['notes'] ?? '')) ?: null]);
        self::redirect('/admin/versions', 'Version enregistrée.');
    }

    public static function adminRollbackVersion(): never
    {
        self::requireAdminUser();
        Database::connection()->prepare('UPDATE app_versions SET rolled_back_at = NOW() WHERE id = ?')->execute([(int) ($_POST['version_id'] ?? 0)]);
        self::redirect('/admin/versions', 'Rollback enregistré.', 'info');
    }

    public static function adminSync(): void
    {
        self::requireAdminUser();
        View::render('pages/admin-sync', ['pageTitle' => 'Synchronisation Lodgify']);
    }

    public static function adminRunSync(): never
    {
        self::requireAdminUser();
        $client = new LodgifyClient();
        $client->invalidate('lodgify:');
        $client->getProperties();
        self::redirect('/admin/sync', 'Synchronisation Lodgify terminée.');
    }

    public static function adminDiagnostic(): void
    {
        self::requireAdminUser();
        $data = null;
        if (isset($_GET['run'])) {
            $data = [];

            // Step 1 — db/config.php (DB connection credentials)
            // Clear PHP's realpath/stat cache first: on long-running workers (FPM/opcache)
            // a stale cache can keep reporting a file as missing after it was created.
            clearstatcache(true);
            $configPath  = defined('BASE_PATH') ? BASE_PATH . '/db/config.php' : '';
            $configRealBase = $configPath !== '' ? realpath(BASE_PATH) : false;
            $configRealPath = $configPath !== '' ? realpath($configPath) : false;
            $configExists   = $configPath !== '' && is_file($configPath);
            $configIsLink   = $configPath !== '' && is_link($configPath);
            $configPerms    = ($configExists && function_exists('fileperms')) ? substr(sprintf('%o', fileperms($configPath)), -4) : null;
            $configOwner    = ($configExists && function_exists('posix_getpwuid') && function_exists('fileowner'))
                ? (posix_getpwuid(fileowner($configPath))['name'] ?? null) : null;
            $phpUser     = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
            $openBasedir = ini_get('open_basedir');

            // Directory listing of BASE_PATH/db exactly as PHP sees it on disk, so we can
            // prove — without guessing — whether "config.php" is really there or the app
            // is simply looking in the wrong folder (wrong BASE_PATH / stale deploy).
            $dbDirListing = null;
            if (defined('BASE_PATH') && is_dir(BASE_PATH . '/db')) {
                $entries = @scandir(BASE_PATH . '/db');
                if ($entries !== false) {
                    $dbDirListing = array_values(array_diff($entries, ['.', '..']));
                    sort($dbDirListing);
                }
            }

            $data['config_file'] = [
                'path'             => $configPath !== '' ? $configPath : '(BASE_PATH non défini)',
                'base_path'        => defined('BASE_PATH') ? BASE_PATH : '(non défini)',
                'base_realpath'    => $configRealBase !== false ? $configRealBase : '(introuvable — vérifiez les liens symboliques)',
                'real_path'        => $configRealPath !== false ? $configRealPath : null,
                'exists'           => $configExists,
                'is_link'          => $configIsLink,
                'readable'         => $configPath !== '' && is_readable($configPath),
                'perms'            => $configPerms,
                'owner'            => $configOwner,
                'php_user'         => $phpUser,
                'open_basedir'     => $openBasedir !== false && $openBasedir !== '' ? $openBasedir : null,
                'document_root'    => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'script_filename'  => $_SERVER['SCRIPT_FILENAME'] ?? null,
                'db_dir_listing'   => $dbDirListing,
            ];

            // Step 2 — Settings (stored in MySQL, not .env)
            $data['env'] = [
                'APP_ENV'          => ($v = trim((string) (Settings::get('APP_ENV') ?? ''))) !== '' ? $v : '(non défini)',
                'PORT'             => ($v = trim((string) (Settings::get('PORT') ?? ''))) !== '' ? $v : '(non défini)',
                'LODGIFY_BASE_URL' => ($v = trim((string) (Settings::get('LODGIFY_BASE_URL') ?? ''))) !== '' ? $v : 'https://api.lodgify.com/v2',
                'LODGIFY_API_KEY_SET' => trim((string) (Settings::get('LODGIFY_API_KEY', '') ?? '')) !== '',
                'CORS_ORIGIN'      => ($v = trim((string) (Settings::get('CORS_ORIGIN') ?? ''))) !== '' ? $v : '(non défini)',
            ];

            // Step 3 — Database
            $data['database'] = Database::test();

            // Step 4 — Lodgify network connectivity (independent check, never touches cache/mapping)
            try {
                $data['lodgify_connectivity'] = (new LodgifyClient())->testConnectivity();
            } catch (Throwable $e) {
                $data['lodgify_connectivity'] = ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Erreur inconnue (voir logs serveur)'];
            }

            // Step 5 — Lodgify API (full property fetch)
            try {
                $client = new LodgifyClient();
                $raw    = $client->getRawProperties();
                $mapped = $client->getProperties();
                $data['lodgify'] = [
                    'ok'             => true,
                    'property_count' => count($mapped),
                    'raw_sample'     => array_slice($raw, 0, 1),
                    'mapped_sample'  => array_slice($mapped, 0, 2),
                ];
            } catch (Throwable $e) {
                $data['lodgify'] = ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Erreur inconnue (voir logs serveur)'];
            }

            // Step 6 — Cache
            $cacheState = false;
            try {
                $cacheState = Database::connection()->query("SELECT COUNT(*) FROM lodgify_cache WHERE cache_key = 'lodgify:v2:properties' AND expires_at > NOW()")->fetchColumn() > 0;
            } catch (Throwable) {
                $cacheState = false;
            }
            $data['cache'] = ['properties_cached' => $cacheState];
        }
        View::render('pages/admin-diagnostic', ['pageTitle' => 'Diagnostic', 'diagnostic' => $data]);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not-found', ['pageTitle' => 'Introuvable']);
    }

    private static function requirePartnerUser(): array
    {
        $user = Auth::requireUser();
        if (($user['role'] ?? '') !== 'partner' && ($user['role'] ?? '') !== 'admin') {
            throw new HttpException(403, 'Forbidden', 'Accès partenaire requis.');
        }
        return $user;
    }

    private static function requireAdminUser(): array
    {
        return Auth::requireUser(true);
    }

    private static function publicRates(LodgifyClient $client, int $propertyId, string $from, string $to): array
    {
        $rawRates = $client->getRates($propertyId, $from, $to, 2);
        return array_map(static fn(array $rate): array => [
            'date_from' => $rate['date_from'],
            'date_to' => $rate['date_to'],
            'currency' => $rate['currency'],
            'price_per_night' => $rate['price_per_night'],
            'price_per_night_with_markup' => $rate['price_per_night'],
            'markup_percent' => 0,
        ], $rawRates);
    }
}
