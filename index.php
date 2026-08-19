<?php

declare(strict_types=1);

require __DIR__ . '/files/bootstrap.php';

use App\HttpException;
use App\Settings;
use App\Tenant;
use App\controllers\AccountController;
use App\controllers\AuthController;
use App\controllers\DiagnosticController;
use App\controllers\EmailSchedulesController;
use App\controllers\EmailTemplatesController;
use App\controllers\FeesController;
use App\controllers\GalleryController;
use App\controllers\LodgifyController;
use App\controllers\PageController;
use App\controllers\PartnersController;
use App\controllers\ReservationsController;
use App\controllers\VersionsController;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('UTC');

// Keep the live database schema in sync with the codebase automatically:
// on shared hosting there is often no shell access to run `bin/migrate.php`
// after a deploy, so any pending migration (new column/table) is applied
// here on the fly. Failures are logged but never break the page.
try {
    App\Migrator::autoRun();
} catch (\Throwable $e) {
    error_log('[migrator] ' . $e->getMessage());
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    $allowedOrigins = array_filter(array_map('trim', explode(',', Settings::get('CORS_ORIGIN', '') ?? '')));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && ($allowedOrigins === [] || in_array($origin, $allowedOrigins, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['status' => 'ok', 'timestamp' => gmdate('c')];
    // Publicly reachable (no login) on purpose: when the site itself is
    // unreachable/timing out (e.g. behind a reverse proxy "Request Timeout"
    // page), the admin login page is unreachable too, so this is the only
    // way to tell whether the DB connection/a stuck migration lock is the
    // cause without shell access. Database::connection() now caps its
    // connect attempt (PDO::ATTR_TIMEOUT) so this never itself hangs for
    // more than a few seconds even when the DB host is down/unreachable.
    if (($_GET['check'] ?? '') === 'db') {
        $dbCheck = App\Database::test();
        $payload['database'] = $dbCheck;
        try {
            $pdo = App\Database::connection();
            $stmt = $pdo->query('SELECT filename, applied_at FROM db_migrations ORDER BY id DESC LIMIT 1');
            $last = $stmt !== false ? $stmt->fetch() : false;
            $payload['last_migration'] = $last ?: null;
        } catch (\Throwable $e) {
            $payload['last_migration'] = ['error' => $e->getMessage()];
        }
        if (!$dbCheck['ok']) {
            $payload['status'] = 'error';
            http_response_code(503);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Allows deep-linking straight to any page with e.g.
// https://www.grand-baie-maurice.com/properties/123?partner=code_partenaire:
// a valid "partner" query parameter sets the partner_code cookie for this
// visitor exactly as if they had typed the code on the "/" gate page (see
// PageController::submitPartnerCode()), so the requested page renders
// directly instead of forcing a detour through the gate/hash flow. This is
// purely additive: it never touches the "#code_partenaire" hash deep-link
// (assets/js/app.js initPartnerCodeFromHash()) or the "/login" form, and an
// invalid/unknown code is silently ignored so the existing cookie (if any)
// or the gate page keeps handling the request as before.
if (!str_starts_with($path, '/api/')) {
    $partnerCode = trim((string) ($_GET['partner'] ?? ''));
    if ($partnerCode !== '') {
        $partnerFromQuery = Tenant::resolveByCode($partnerCode);
        if ($partnerFromQuery) {
            Tenant::setCodeCookie((string) $partnerFromQuery['subdomain']);
        }
    }
}

try {
    switch (true) {
        case route($method, $path, 'POST', '#^/api/auth/login$#'):
            AuthController::login();
        case route($method, $path, 'GET', '#^/api/auth/me$#'):
            AuthController::me();
        case route($method, $path, 'GET', '#^/api/partners/current$#'):
            PartnersController::current();
        case route($method, $path, 'GET', '#^/api/partners$#'):
            PartnersController::index();
        case route($method, $path, 'GET', '#^/api/partners/(\d+)$#', $matches):
            PartnersController::show((int) $matches[1]);
        case route($method, $path, 'POST', '#^/api/partners$#'):
            PartnersController::create();
        case route($method, $path, 'PUT', '#^/api/partners/(\d+)$#', $matches):
            PartnersController::update((int) $matches[1]);
        case route($method, $path, 'DELETE', '#^/api/partners/(\d+)$#', $matches):
            PartnersController::delete((int) $matches[1]);
        case route($method, $path, 'POST', '#^/api/reservations/request$#'):
            ReservationsController::requestReservation();
        case route($method, $path, 'POST', '#^/api/reservations/request-multiple$#'):
            ReservationsController::requestMultiple();
        case route($method, $path, 'POST', '#^/api/reservations/quote$#'):
            ReservationsController::quote();
        case route($method, $path, 'GET', '#^/api/reservations$#'):
            ReservationsController::index();
        case route($method, $path, 'GET', '#^/api/reservations/(\d+)$#', $matches):
            ReservationsController::show((int) $matches[1]);
        case route($method, $path, 'PUT', '#^/api/reservations/(\d+)/confirm$#', $matches):
            ReservationsController::confirm((int) $matches[1]);
        case route($method, $path, 'PUT', '#^/api/reservations/(\d+)/cancel$#', $matches):
            ReservationsController::cancel((int) $matches[1]);
        case route($method, $path, 'GET', '#^/api/email-templates$#'):
            EmailTemplatesController::index();
        case route($method, $path, 'GET', '#^/api/email-templates/(\d+)$#', $matches):
            EmailTemplatesController::show((int) $matches[1]);
        case route($method, $path, 'POST', '#^/api/email-templates$#'):
            EmailTemplatesController::create();
        case route($method, $path, 'PUT', '#^/api/email-templates/(\d+)$#', $matches):
            EmailTemplatesController::update((int) $matches[1]);
        case route($method, $path, 'DELETE', '#^/api/email-templates/(\d+)$#', $matches):
            EmailTemplatesController::delete((int) $matches[1]);
        case route($method, $path, 'GET', '#^/api/email-schedules$#'):
            EmailSchedulesController::index();
        case route($method, $path, 'POST', '#^/api/email-schedules$#'):
            EmailSchedulesController::create();
        case route($method, $path, 'PUT', '#^/api/email-schedules/(\d+)$#', $matches):
            EmailSchedulesController::update((int) $matches[1]);
        case route($method, $path, 'DELETE', '#^/api/email-schedules/(\d+)$#', $matches):
            EmailSchedulesController::delete((int) $matches[1]);
        case route($method, $path, 'POST', '#^/api/contact$#'):
            App\controllers\ContactController::submit();
        case route($method, $path, 'GET', '#^/api/fees/cleaning$#'):
            FeesController::cleaning();
        case route($method, $path, 'PUT', '#^/api/fees/cleaning/([^/]+)$#', $matches):
            FeesController::updateCleaning((string) $matches[1]);
        case route($method, $path, 'GET', '#^/api/fees/tourist-tax$#'):
            FeesController::touristTax();
        case route($method, $path, 'PUT', '#^/api/fees/tourist-tax$#'):
            FeesController::updateTouristTax();
        case route($method, $path, 'GET', '#^/api/versions$#'):
            VersionsController::index();
        case route($method, $path, 'POST', '#^/api/versions/deploy$#'):
            VersionsController::deploy();
        case route($method, $path, 'POST', '#^/api/versions/rollback$#'):
            VersionsController::rollback();
        case route($method, $path, 'GET', '#^/api/versions/migrations$#'):
            VersionsController::migrations();
        case route($method, $path, 'GET', '#^/api/diagnostic$#'):
            DiagnosticController::run();
        case route($method, $path, 'GET', '#^/api/lodgify/properties$#'):
            LodgifyController::properties();
        case route($method, $path, 'GET', '#^/api/lodgify/properties/(\d+)$#', $matches):
            LodgifyController::property((int) $matches[1]);
        case route($method, $path, 'GET', '#^/api/lodgify/properties/(\d+)/availability$#', $matches):
            LodgifyController::availability((int) $matches[1]);
        case route($method, $path, 'GET', '#^/api/lodgify/properties/(\d+)/rates$#', $matches):
            LodgifyController::rates((int) $matches[1]);
        case route($method, $path, 'POST', '#^/api/lodgify/sync$#'):
            LodgifyController::sync();
        case route($method, $path, 'GET', '#^/$#'):
            PageController::home();
            break;
        case route($method, $path, 'GET', '#^/lang/(fr|en)$#', $matches):
            PageController::switchLanguage((string) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/accueil$#'):
            PageController::accueil();
            break;
        case route($method, $path, 'GET', '#^/properties$#'):
            PageController::properties();
            break;
        case route($method, $path, 'GET', '#^/calendrier$#'):
            PageController::calendar();
            break;
        case route($method, $path, 'GET', '#^/properties/(\d+)$#', $matches):
            PageController::propertyDetail((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/properties/(\d+)/reservation-directe$#', $matches):
            PageController::bookingRedirect((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/properties/(\d+)/verifier-disponibilites$#', $matches):
            PageController::availabilityCheck((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/contact$#'):
            PageController::contact();
            break;
        case route($method, $path, 'GET', '#^/politique-confidentialite$#'):
            PageController::privacyPolicy();
            break;
        case route($method, $path, 'GET', '#^/aide$#'):
            PageController::help();
            break;
        case route($method, $path, 'GET', '#^/aide-partenaire$#'):
            PageController::partnerHelp();
            break;
        case route($method, $path, 'POST', '#^/partner-code$#'):
            PageController::submitPartnerCode();
        case route($method, $path, 'GET', '#^/login$#'):
            PageController::login();
            break;
        case route($method, $path, 'POST', '#^/login$#'):
            AuthController::pageLogin();
        case route($method, $path, 'GET', '#^/logout$#'):
            AuthController::logout();
        case route($method, $path, 'GET', '#^/forgot-password$#'):
            AccountController::forgotPassword();
            break;
        case route($method, $path, 'POST', '#^/forgot-password$#'):
            AccountController::submitForgotPassword();
        case route($method, $path, 'GET', '#^/reset-password/([^/]+)$#', $matches):
            AccountController::resetPassword((string) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/reset-password/([^/]+)$#', $matches):
            AccountController::submitResetPassword((string) $matches[1]);
        case route($method, $path, 'GET', '#^/account$#'):
            AccountController::profile();
            break;
        case route($method, $path, 'POST', '#^/account$#'):
            AccountController::updateProfile();
        // "Partager le lien" public reservation page: unauthenticated, but
        // gated by an unguessable token (see ReservationsController::
        // ensurePublicToken()/findByToken()) rather than by login.
        case route($method, $path, 'GET', '#^/r/([a-f0-9]{32})$#', $matches):
            PageController::reservationPublic((string) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/r/([a-f0-9]{32})/available-properties$#', $matches):
            PageController::reservationPublicAvailableProperties((string) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/r/([a-f0-9]{32})/dates-availability$#', $matches):
            PageController::reservationPublicDatesAvailability((string) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/r/([a-f0-9]{32})/property-photos$#', $matches):
            PageController::reservationPublicPropertyPhotos((string) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/r/([a-f0-9]{32})/email$#', $matches):
            PageController::reservationPublicSetEmail((string) $matches[1]);
        case route($method, $path, 'POST', '#^/r/([a-f0-9]{32})/update$#', $matches):
            PageController::reservationPublicUpdate((string) $matches[1]);
        case route($method, $path, 'POST', '#^/r/([a-f0-9]{32})/cancel$#', $matches):
            PageController::reservationPublicCancel((string) $matches[1]);
        case route($method, $path, 'GET', '#^/partner/dashboard$#'):
            PageController::partnerDashboard();
            break;
        case route($method, $path, 'GET', '#^/partner/reservations$#'):
            PageController::partnerReservations();
            break;
        case route($method, $path, 'GET', '#^/partner/reservations/(\d+)$#', $matches):
            PageController::partnerReservationDetail((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/confirm$#', $matches):
            PageController::partnerConfirmReservation((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/cancel$#', $matches):
            PageController::partnerCancelReservation((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/reopen$#', $matches):
            PageController::partnerReopenReservation((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/client-property-change$#', $matches):
            PageController::partnerToggleClientPropertyChange((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/update$#', $matches):
            PageController::partnerUpdateReservation((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/partner/reservations/(\d+)/available-properties$#', $matches):
            PageController::partnerReservationAvailableProperties((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/partner/reservations/(\d+)/dates-availability$#', $matches):
            PageController::partnerReservationDatesAvailability((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/partner/reservations/(\d+)/property-photos$#', $matches):
            PageController::partnerReservationPropertyPhotos((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/partner/settings$#'):
            PageController::partnerSettings();
            break;
        case route($method, $path, 'POST', '#^/partner/settings$#'):
            PageController::partnerSaveSettings();
        case route($method, $path, 'POST', '#^/partner/settings/policies$#'):
            PageController::partnerCreatePolicy();
        case route($method, $path, 'POST', '#^/partner/settings/policies/(\d+)$#', $matches):
            PageController::partnerUpdatePolicy((int) $matches[1]);
        case route($method, $path, 'POST', '#^/partner/settings/policies/(\d+)/delete$#', $matches):
            PageController::partnerDeletePolicy((int) $matches[1]);
        case route($method, $path, 'POST', '#^/partner/settings/policies/(\d+)/default$#', $matches):
            PageController::partnerSetDefaultPolicy((int) $matches[1]);
        case route($method, $path, 'GET', '#^/partner/gallery$#'):
            GalleryController::partnerIndex();
            break;
        case route($method, $path, 'GET', '#^/partner/gallery/(\d+)$#', $matches):
            GalleryController::partnerShow((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/partner/gallery/(\d+)/zip$#', $matches):
            GalleryController::partnerDownloadZip((int) $matches[1]);
        case route($method, $path, 'GET', '#^/admin/partners$#'):
            PageController::adminPartners();
            break;
        case route($method, $path, 'GET', '#^/admin/partners/new$#'):
            PageController::adminPartnerForm();
            break;
        case route($method, $path, 'GET', '#^/admin/partners/(\d+)/edit$#', $matches):
            PageController::adminPartnerForm((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/admin/partners$#'):
            PageController::adminSavePartner();
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)$#', $matches):
            PageController::adminSavePartner((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/delete$#', $matches):
            PageController::adminDeletePartner((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/properties$#', $matches):
            PageController::adminSavePartnerProperties((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/users$#', $matches):
            PageController::adminCreatePartnerUser((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/users/(\d+)/delete$#', $matches):
            PageController::adminDeletePartnerUser((int) $matches[1], (int) $matches[2]);
        case route($method, $path, 'GET', '#^/admin/partners/(\d+)/templates$#', $matches):
            PageController::adminPartnerTemplates((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/templates/(\d+)$#', $matches):
            PageController::adminSavePartnerTemplate((int) $matches[1], (int) $matches[2]);
        case route($method, $path, 'POST', '#^/admin/partners/(\d+)/templates/(\d+)/delete$#', $matches):
            PageController::adminDeletePartnerTemplate((int) $matches[1], (int) $matches[2]);
        case route($method, $path, 'GET', '#^/admin/gallery$#'):
            GalleryController::adminIndex();
            break;
        case route($method, $path, 'GET', '#^/admin/gallery/(\d+)$#', $matches):
            GalleryController::adminShow((int) $matches[1]);
            break;
        case route($method, $path, 'POST', '#^/admin/gallery/(\d+)/zip$#', $matches):
            GalleryController::adminDownloadZip((int) $matches[1]);
        case route($method, $path, 'GET', '#^/admin/reservations$#'):
            PageController::adminReservations();
            break;
        case route($method, $path, 'POST', '#^/admin/reservations/delete-batch$#'):
            PageController::adminDeleteReservationsBatch();
        case route($method, $path, 'POST', '#^/admin/reservations/(\d+)/confirm$#', $matches):
            PageController::adminConfirmReservation((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/reservations/(\d+)/cancel$#', $matches):
            PageController::adminCancelReservation((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/reservations/(\d+)/reopen$#', $matches):
            PageController::adminReopenReservation((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/reservations/(\d+)/delete$#', $matches):
            PageController::adminDeleteReservation((int) $matches[1]);
        case route($method, $path, 'GET', '#^/admin/fees$#'):
            PageController::adminFees();
            break;
        case route($method, $path, 'GET', '#^/admin/politique-reservation$#'):
            PageController::adminBookingPolicy();
            break;
        case route($method, $path, 'POST', '#^/admin/politique-reservation$#'):
            PageController::adminSaveBookingPolicy();
        case route($method, $path, 'GET', '#^/admin/smtp-settings$#'):
            PageController::adminSmtpSettings();
            break;
        case route($method, $path, 'POST', '#^/admin/smtp-settings$#'):
            PageController::adminSaveSmtpSettings();
        case route($method, $path, 'GET', '#^/admin/communication$#'):
            PageController::adminCommunication();
            break;
        case route($method, $path, 'POST', '#^/admin/communication/send$#'):
            PageController::adminSendCommunication();
        case route($method, $path, 'POST', '#^/admin/fees/tourist-tax$#'):
            PageController::adminSaveTax();
        case route($method, $path, 'POST', '#^/admin/fees/cleaning-default$#'):
            PageController::adminSaveDefaultCleaningFee();
        case route($method, $path, 'GET', '#^/admin/translations$#'):
            PageController::adminTranslations();
            break;
        case route($method, $path, 'POST', '#^/admin/translations/save$#'):
            PageController::adminSaveTranslation();
        case route($method, $path, 'POST', '#^/admin/translations/suggest$#'):
            PageController::adminSuggestTranslation();
        case route($method, $path, 'GET', '#^/admin/versions$#'):
            PageController::adminVersions();
            break;
        case route($method, $path, 'POST', '#^/admin/versions/deploy$#'):
            PageController::adminDeployVersion();
        case route($method, $path, 'POST', '#^/admin/versions/rollback$#'):
            PageController::adminRollbackVersion();
        case route($method, $path, 'GET', '#^/admin/templates$#'):
            PageController::adminAllTemplates();
            break;
        case route($method, $path, 'GET', '#^/admin/templates/default$#'):
            PageController::adminDefaultTemplates();
            break;
        case route($method, $path, 'POST', '#^/admin/templates/default/create$#'):
            PageController::adminCreateDefaultTemplate();
        case route($method, $path, 'POST', '#^/admin/templates/default/(\d+)$#', $matches):
            PageController::adminSaveDefaultTemplate((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/templates/default/(\d+)/delete$#', $matches):
            PageController::adminDeleteDefaultTemplate((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/templates/default/import-zip$#'):
            PageController::adminImportDefaultTemplateZip();
        case route($method, $path, 'POST', '#^/admin/templates/create$#'):
            PageController::adminCreateAllTemplate();
        case route($method, $path, 'POST', '#^/admin/templates/import$#'):
            PageController::adminImportAllTemplate();
        case route($method, $path, 'POST', '#^/admin/templates/import-zip$#'):
            PageController::adminImportTemplateZip();
        case route($method, $path, 'POST', '#^/admin/templates/assets/upload$#'):
            PageController::adminUploadTemplateGalleryAsset();
        case route($method, $path, 'POST', '#^/admin/templates/assets/delete$#'):
            PageController::adminDeleteTemplateGalleryAsset();
        case route($method, $path, 'POST', '#^/admin/templates/(\d+)/(\d+)$#', $matches):
            PageController::adminSaveAllTemplate((int) $matches[1], (int) $matches[2]);
        case route($method, $path, 'POST', '#^/admin/templates/(\d+)/(\d+)/delete$#', $matches):
            PageController::adminDeleteAllTemplate((int) $matches[1], (int) $matches[2]);
        case route($method, $path, 'GET', '#^/admin/mise-a-jour$#'):
            PageController::adminMiseAJour();
            break;
        case route($method, $path, 'POST', '#^/admin/mise-a-jour$#'):
            PageController::adminApplyUpdate();
        case route($method, $path, 'POST', '#^/admin/mise-a-jour/rollback$#'):
            PageController::adminRollbackUpdate();
        case route($method, $path, 'GET', '#^/admin/sync$#'):
            PageController::adminSync();
            break;
        case route($method, $path, 'POST', '#^/admin/sync$#'):
            PageController::adminRunSync();
        case route($method, $path, 'POST', '#^/admin/sync/start$#'):
            PageController::adminSyncStart();
        case route($method, $path, 'POST', '#^/admin/sync/property/(\d+)$#', $matches):
            PageController::adminSyncProperty((int) $matches[1]);
        case route($method, $path, 'POST', '#^/admin/sync/finish$#'):
            PageController::adminSyncFinish();
        case route($method, $path, 'GET', '#^/admin/lodgify-properties$#'):
            PageController::adminLodgifyProperties();
            break;
        case route($method, $path, 'POST', '#^/admin/lodgify-properties/manual$#'):
            PageController::adminSaveLodgifyPropertiesManual();
            break;
        case route($method, $path, 'GET', '#^/admin/diagnostic$#'):
            PageController::adminDiagnostic();
            break;
        case route($method, $path, 'GET', '#^/admin/cron$#'):
            PageController::adminCron();
            break;
        case route($method, $path, 'POST', '#^/admin/cron/run$#'):
            PageController::adminRunScheduler();
        case route($method, $path, 'POST', '#^/admin/cron/schedules$#'):
            PageController::adminSaveEmailSchedule();
        case route($method, $path, 'POST', '#^/admin/cron/schedules/(\d+)/delete$#', $matches):
            PageController::adminDeleteEmailSchedule((int) $matches[1]);
        default:
            PageController::notFound();
    }
} catch (HttpException $e) {
    if (str_starts_with($path, '/api/')) {
        http_response_code($e->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->error, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        if (in_array($e->statusCode, [401, 403], true)) {
            App\Flash::set($e->getMessage(), 'error');
            header('Location: /login');
            exit;
        }
        http_response_code($e->statusCode);
        if ($e->statusCode === 404) {
            PageController::notFound();
        } else {
            PageController::errorPage($e->statusCode, $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log((string) $e);
    if (str_starts_with($path, '/api/')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        http_response_code(500);
        $message = 'Une erreur est survenue : ' . $e->getMessage();
        App\Flash::set($message, 'error');
        PageController::errorPage(500, $message);
    }
}

function route(string $method, string $path, string $expectedMethod, string $pattern, ?array &$matches = null): bool
{
    if ($method !== $expectedMethod) {
        return false;
    }
    return preg_match($pattern, $path, $matches) === 1;
}
