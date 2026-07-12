<?php

declare(strict_types=1);

require __DIR__ . '/files/bootstrap.php';

use App\HttpException;
use App\Settings;
use App\controllers\AuthController;
use App\controllers\DiagnosticController;
use App\controllers\EmailSchedulesController;
use App\controllers\EmailTemplatesController;
use App\controllers\FeesController;
use App\controllers\LodgifyController;
use App\controllers\PageController;
use App\controllers\PartnersController;
use App\controllers\ReservationsController;
use App\controllers\VersionsController;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('UTC');

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
    echo json_encode(['status' => 'ok', 'timestamp' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
        case route($method, $path, 'GET', '#^/properties$#'):
            PageController::properties();
            break;
        case route($method, $path, 'GET', '#^/calendrier$#'):
            PageController::calendar();
            break;
        case route($method, $path, 'GET', '#^/properties/(\d+)$#', $matches):
            PageController::propertyDetail((int) $matches[1]);
            break;
        case route($method, $path, 'GET', '#^/contact$#'):
            PageController::contact();
            break;
        case route($method, $path, 'GET', '#^/login$#'):
            PageController::login();
            break;
        case route($method, $path, 'POST', '#^/login$#'):
            AuthController::pageLogin();
        case route($method, $path, 'GET', '#^/logout$#'):
            AuthController::logout();
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
        case route($method, $path, 'POST', '#^/partner/reservations/(\d+)/cancel$#', $matches):
            PageController::partnerCancelReservation((int) $matches[1]);
        case route($method, $path, 'GET', '#^/partner/templates$#'):
            PageController::partnerTemplates();
            break;
        case route($method, $path, 'POST', '#^/partner/templates/(\d+)$#', $matches):
            PageController::partnerSaveTemplate((int) $matches[1]);
        case route($method, $path, 'GET', '#^/partner/settings$#'):
            PageController::partnerSettings();
            break;
        case route($method, $path, 'POST', '#^/partner/settings$#'):
            PageController::partnerSaveSettings();
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
        case route($method, $path, 'GET', '#^/admin/fees$#'):
            PageController::adminFees();
            break;
        case route($method, $path, 'POST', '#^/admin/fees/tourist-tax$#'):
            PageController::adminSaveTax();
        case route($method, $path, 'GET', '#^/admin/versions$#'):
            PageController::adminVersions();
            break;
        case route($method, $path, 'POST', '#^/admin/versions/deploy$#'):
            PageController::adminDeployVersion();
        case route($method, $path, 'POST', '#^/admin/versions/rollback$#'):
            PageController::adminRollbackVersion();
        case route($method, $path, 'GET', '#^/admin/sync$#'):
            PageController::adminSync();
            break;
        case route($method, $path, 'POST', '#^/admin/sync$#'):
            PageController::adminRunSync();
        case route($method, $path, 'GET', '#^/admin/diagnostic$#'):
            PageController::adminDiagnostic();
            break;
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
        App\Flash::set($e->getMessage(), 'error');
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
