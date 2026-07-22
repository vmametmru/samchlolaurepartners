<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Settings;
use App\Flash;
use App\HttpException;
use App\LodgifyApiException;
use App\LodgifyClient;
use App\PartnerPropertyVisibility;
use App\Scheduler;
use App\Tenant;
use App\View;
use PDO;
use Throwable;

final class PageController extends Controller
{
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The root URL is hardcoded to always show the "enter your partner code"
     * gate, regardless of whether a partner_code cookie is already active:
     * the search-form home page now lives at /accueil (see self::accueil()).
     */
    public static function home(): void
    {
        View::render('pages/enter-code', [
            'pageTitle' => 'Bienvenue',
            'suppressPartner' => true,
        ]);
    }

    /**
     * The search-form home page, formerly served at "/". Requires a valid
     * partner context (partner_code cookie); visitors without one are sent
     * back to "/" to enter their code.
     */
    public static function accueil(): void
    {
        if (Tenant::current() === null) {
            self::redirect('/');
        }

        $properties = [];
        $searched = false;
        $capacityExceeded = false;
        if (!empty($_GET['checkin']) && !empty($_GET['checkout'])) {
            $searched = true;
            $checkin = (string) $_GET['checkin'];
            $checkout = (string) $_GET['checkout'];
            $childrenUnder3 = max(0, (int) ($_GET['children_under3'] ?? 0));
            $children3to12 = max(0, (int) ($_GET['children_3to12'] ?? 0));
            // Babies (children under 3) do not count toward property capacity;
            // only adults and children 3-12 are compared against max_guests.
            $guests = max(1, (int) ($_GET['adults'] ?? 1)) + $children3to12;
            // Max 2 babies per property: if more than 2 are requested, no single
            // property can accommodate them → force the multi-property calendar.
            $babiesExceeded = $childrenUnder3 > 2;
            $partner = Tenant::current();
            try {
                $client = new LodgifyClient();
                $allProperties = self::filterVisibleProperties($client->getProperties(), $partner, true);
                if ($babiesExceeded) {
                    // No single property can host more than 2 babies.
                    $properties = [];
                    $capacityExceeded = true;
                } else {
                    $capacityFiltered = array_values(array_filter(
                        $allProperties,
                        static function (array $property) use ($guests): bool {
                            return !($property['max_guests'] > 0 && $property['max_guests'] < $guests);
                        }
                    ));
                    $properties = array_values(array_filter(
                        $capacityFiltered,
                        static function (array $property) use ($client, $checkin, $checkout): bool {
                            return $client->isAvailableForRange((int) $property['id'], $checkin, $checkout);
                        }
                    ));
                    if ($properties === [] && count($capacityFiltered) < count($allProperties)) {
                        $capacityExceeded = true;
                    }
                }
            } catch (Throwable $e) {
                Flash::set('Impossible de charger les hébergements pour le moment.', 'error');
            }
        }
        View::render('pages/home', [
            'pageTitle' => 'Accueil',
            'properties' => $properties,
            'searched' => $searched,
            'capacityExceeded' => $capacityExceeded,
            'babiesExceeded' => $babiesExceeded ?? false,
            'search' => [
                'checkin' => $_GET['checkin'] ?? '',
                'checkout' => $_GET['checkout'] ?? '',
                'adults' => $_GET['adults'] ?? 2,
                'children_under3' => $_GET['children_under3'] ?? 0,
                'children_3to12' => $_GET['children_3to12'] ?? 0,
                'totalGuests' => isset($_GET['checkin']) ? max(1, (int) ($_GET['adults'] ?? 1)) + max(0, (int) ($_GET['children_under3'] ?? 0)) + max(0, (int) ($_GET['children_3to12'] ?? 0)) : 0,
                'nationality' => $_GET['nationality'] ?? '',
            ],
        ]);
    }

    public static function properties(): void
    {
        $properties = [];
        $query = trim((string) ($_GET['q'] ?? ''));
        try {
            $properties = self::filterVisibleProperties((new LodgifyClient())->getProperties(), Tenant::current());
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

    /**
     * Removes properties the active partner isn't allowed to see ("none")
     * from a Lodgify property list. When $excludePartial is true, "partial"
     * properties are dropped too — used for date-based availability search
     * results (home search), which would otherwise leak exactly the
     * rates/availability information a "partial" restriction is meant to hide.
     *
     * @param array<int, array> $properties
     * @return array<int, array>
     */
    private static function filterVisibleProperties(array $properties, ?array $partner, bool $excludePartial = false): array
    {
        if (!$partner) {
            return $properties;
        }
        $visibilityMap = PartnerPropertyVisibility::allForPartner((int) $partner['id']);
        if ($visibilityMap === []) {
            return $properties;
        }
        return array_values(array_filter($properties, static function (array $property) use ($visibilityMap, $excludePartial): bool {
            $visibility = $visibilityMap[(string) ($property['id'] ?? '')] ?? PartnerPropertyVisibility::FULL;
            if ($visibility === PartnerPropertyVisibility::NONE) {
                return false;
            }
            if ($excludePartial && $visibility === PartnerPropertyVisibility::PARTIAL) {
                return false;
            }
            return true;
        }));
    }

    public static function propertyDetail(int $id): void
    {
        $partner = Tenant::current();
        $visibility = PartnerPropertyVisibility::visibilityFor($partner, $id);
        if ($visibility === PartnerPropertyVisibility::NONE) {
            throw new HttpException(404, 'Not Found', 'Hébergement introuvable');
        }
        $client = new LodgifyClient();
        $today = date('Y-m-d');
        // The calendar always shows the next 12 months (no tab switching, no
        // AJAX refresh needed), rendered once with the full page.
        $calendarMonths = 12;
        [$rangeStart, $rangeEnd] = self::calendarRange($calendarMonths);
        $availability = [];
        $rates = [];
        try {
            $property = $client->getProperty($id);
            // "partial" properties never load/display rates & availability:
            // the "Tarifs & Disponibilités" tab shows a contact message
            // instead (see the view), so there is no need to hit Lodgify for it.
            if ($visibility !== PartnerPropertyVisibility::PARTIAL) {
                $availability = $client->getAvailability($id, $rangeStart, $rangeEnd);
                $rates = self::publicRates($client, $id, $rangeStart, $rangeEnd);
            }
        } catch (Throwable $e) {
            error_log('Property detail load failed for id ' . $id . ': ' . $e->getMessage());
            // Only a genuine 404 from Lodgify means the property truly doesn't
            // exist. Any other failure (timeout, 5xx, rate limiting, auth
            // error, ...) is transient and must not be disguised as "Not Found",
            // otherwise real outages look like broken/removed listings.
            if ($e instanceof LodgifyApiException && $e->statusCode === 404) {
                throw new HttpException(404, 'Not Found', 'Hébergement introuvable');
            }
            throw new HttpException(503, 'Service Unavailable', 'Le service de réservation est temporairement indisponible. Veuillez réessayer dans quelques instants.');
        }
        // The nightly price shown in the "Tarifs & Disponibilités" calendar
        // must include the cleaning fee configured for the active partner
        // (partners.cleaning_fee_per_person_per_night), just like the
        // /calendrier board. The booking form defaults to 2 adults, so that
        // is the guest count used for the initial render; the price note is
        // then kept in sync client-side as the visitor adjusts guest counts.
        $cleaningFeePerPerson = $partner ? (float) ($partner['cleaning_fee_per_person_per_night'] ?? 0) : 0.0;
        View::render('pages/property-detail', [
            'pageTitle' => (string) $property['name'],
            'property' => $property,
            'availability' => $availability,
            'rates' => $rates,
            'today' => $today,
            'calendarMonths' => $calendarMonths,
            'calendarStart' => $rangeStart,
            'cleaningFeePerPerson' => $cleaningFeePerPerson,
            'calendarGuests' => 2,
            'ratesRestricted' => $visibility === PartnerPropertyVisibility::PARTIAL,
        ]);
    }

    public static function calendar(): void
    {
        // Standalone "Calendrier" overview: one row per property, showing the
        // same availability/price colouring as the detail-page calendars, but
        // laid out horizontally so every property can be scanned day by day.
        // By default only the next 30 days are loaded; a date-range picker
        // (date_from/date_to) lets the visitor pick a specific period to load
        // and display instead. Only ~31 days are visible at once and the
        // dates scroll when the mouse approaches the left/right edge.
        $client = new LodgifyClient();
        $start = new \DateTimeImmutable('today');
        $today = $start->format('Y-m-d');

        // Read the date-range filter from the query string (?date_from=&date_to=),
        // keep it only if both are valid dates, date_from is not before today,
        // and date_to is strictly after date_from.
        $dateFrom = '';
        $dateTo = '';
        $requestedFrom = $_GET['date_from'] ?? '';
        $requestedTo = $_GET['date_to'] ?? '';
        if (is_string($requestedFrom) && is_string($requestedTo) && $requestedFrom !== '' && $requestedTo !== '') {
            try {
                $fromDate = (new \DateTimeImmutable($requestedFrom))->setTime(0, 0);
                $toDate = (new \DateTimeImmutable($requestedTo))->setTime(0, 0);
                if ($toDate > $fromDate) {
                    $dateFrom = $fromDate->format('Y-m-d');
                    $dateTo = $toDate->format('Y-m-d');
                }
            } catch (\Exception $e) {
                // Invalid date strings: ignore and fall back to the default range below.
            }
        }

        // Build the ordered list of dates to display.
        $dates = [];
        if ($dateFrom === '' || $dateTo === '') {
            // Default view: only the next 30 days.
            for ($i = 0; $i < 30; $i++) {
                $dates[] = $start->modify('+' . $i . ' days');
            }
        } else {
            $rangeCursor = new \DateTimeImmutable($dateFrom);
            $rangeEndDate = new \DateTimeImmutable($dateTo);
            while ($rangeCursor <= $rangeEndDate) {
                $dates[] = $rangeCursor;
                $rangeCursor = $rangeCursor->modify('+1 day');
            }
        }

        $rangeStart = $dates[0]->format('Y-m-d');
        // The rates/availability windows are inclusive of the last day shown, so
        // the end of the request window is one day after the final visible date.
        $rangeEnd = end($dates)->modify('+1 day')->format('Y-m-d');
        reset($dates);

        // To reserve several properties in a few clicks, the visitor must
        // first tell us the party size (adults + children under 3 + children
        // 3-12). The properties table is only loaded/shown once at least one
        // adult is provided, so a property's capacity can be compared against
        // that party size before any date is even clickable.
        $adults = max(0, (int) ($_GET['adults'] ?? 0));
        $childrenUnder3 = max(0, (int) ($_GET['children_under3'] ?? 0));
        $children3to12 = max(0, (int) ($_GET['children_3to12'] ?? 0));
        $totalGuests = $adults + $childrenUnder3 + $children3to12;
        // Babies do not count toward property capacity; only adults and
        // children 3-12 are compared against each property's max_guests.
        $countedGuests = $adults + $children3to12;

        // The nightly price shown must include the cleaning fee configured for
        // the active partner (partners.cleaning_fee_per_person_per_night),
        // multiplied by the number of guests entered above the table.
        $partner = Tenant::current();
        $cleaningFeePerPerson = $partner ? (float) ($partner['cleaning_fee_per_person_per_night'] ?? 0) : 0.0;
        $cleaningFeePerNight = $cleaningFeePerPerson * $totalGuests;

        $rows = [];
        if ($totalGuests > 0) {
            $properties = [];
            try {
                $properties = self::filterVisibleProperties($client->getProperties(), $partner);
            } catch (Throwable $e) {
                Flash::set('Impossible de charger les hébergements pour le moment.', 'error');
            }

            foreach ($properties as $property) {
                $id = (int) ($property['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $restricted = PartnerPropertyVisibility::visibilityFor($partner, $id) === PartnerPropertyVisibility::PARTIAL;
                $availabilityMap = [];
                $singleNightMap = [];
                $rateMap = [];
                $loadFailed = false;
                // "partial" rows never load rates/availability: the table
                // shows the "contactez votre agence" message in place of the
                // date cells instead (see the view).
                if (!$restricted) {
                    try {
                        foreach ($client->getAvailability($id, $rangeStart, $rangeEnd) as $day) {
                            $availabilityMap[$day['date']] = $day['available'];
                            $singleNightMap[$day['date']] = !empty($day['single_night']);
                        }
                        foreach (self::publicRates($client, $id, $rangeStart, $rangeEnd) as $rate) {
                            if ($cleaningFeePerNight > 0) {
                                $rate['price_per_night'] = round($rate['price_per_night'] + $cleaningFeePerNight, 2);
                            }
                            $rateMap[$rate['date_from']] = $rate;
                        }
                    } catch (Throwable $e) {
                        error_log('Calendar board load failed for property ' . $id . ': ' . $e->getMessage());
                        // Surface the failure in the row itself (instead of a
                        // silent, unexplained wall of grey/unclickable cells)
                        // only when nothing at all could be loaded for this
                        // property, so the visitor understands why no colours
                        // show up rather than assuming the page is broken.
                        $loadFailed = $availabilityMap === [];
                    }
                }
                $maxGuests = (int) ($property['max_guests'] ?? 0);
                $rows[] = [
                    'property' => $property,
                    'availability' => $availabilityMap,
                    'single_night' => $singleNightMap,
                    'rates' => $rateMap,
                    'capacity_ok' => $maxGuests <= 0 || $maxGuests >= $countedGuests,
                    'load_failed' => $loadFailed,
                    'restricted' => $restricted,
                ];
            }
        }

        View::render('pages/calendar', [
            'pageTitle' => 'Calendrier',
            'rows' => $rows,
            'dates' => $dates,
            'visibleDays' => min(count($dates), 31),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'adults' => $adults,
            'childrenUnder3' => $childrenUnder3,
            'children3to12' => $children3to12,
            'totalGuests' => $totalGuests,
            'countedGuests' => $countedGuests,
            'today' => $today,
        ]);
    }

    /**
     * @return array{0: string, 1: string} [rangeStart, rangeEnd] in Y-m-d format
     */
    private static function calendarRange(int $months): array
    {
        $rangeStart = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $rangeEnd = (new \DateTimeImmutable('first day of this month'))
            ->modify('+' . $months . ' months')
            ->modify('-1 day')
            ->format('Y-m-d');
        return [$rangeStart, $rangeEnd];
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

    public static function submitPartnerCode(): never
    {
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            self::redirect('/', 'Merci de saisir un code partenaire.', 'error');
        }

        $partner = Tenant::resolveByCode($code);
        if (!$partner) {
            self::redirect('/', 'Code partenaire invalide.', 'error');
        }

        Tenant::setCodeCookie((string) $partner['subdomain']);

        // Supports deep links like "/#code/calendrier" (see
        // initPartnerCodeFromHash() in assets/js/app.js): the hash suffix is
        // posted as "next" and, if it matches an allowed public page, the
        // visitor lands there directly instead of always on /accueil.
        $allowedNext = ['/accueil', '/calendrier', '/contact', '/properties'];
        $next = (string) ($_POST['next'] ?? '');
        $target = in_array($next, $allowedNext, true) ? $next : '/accueil';
        self::redirect($target);
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
        $notes = trim((string) ($_POST['notes'] ?? ''));
        // Delegates to ReservationsController::confirmForPartner() so the
        // client confirmation email is actually sent (previously this method
        // only updated the database and redirected, without ever notifying
        // the client).
        if (!ReservationsController::confirmForPartner($partnerId, $id, $notes !== '' ? $notes : null)) {
            throw new HttpException(404, 'Not Found', 'Réservation introuvable');
        }
        self::redirect('/partner/reservations/' . $id, 'Réservation confirmée.');
    }

    public static function partnerCancelReservation(int $id): never
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        // Delegates to ReservationsController::cancelForPartner() so the
        // client cancellation email is actually sent (see partnerConfirmReservation()).
        if (!ReservationsController::cancelForPartner($partnerId, $id)) {
            throw new HttpException(404, 'Not Found', 'Réservation introuvable');
        }
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
        $smtpDefaults = [
            'smtp_host' => Settings::get('SMTP_HOST', 'mail.grand-baie-maurice.com'),
            'smtp_port' => Settings::get('SMTP_PORT', '465'),
            'smtp_user' => Settings::get('SMTP_USER', 'infos@grand-baie-maurice.com'),
            'smtp_pass' => Settings::get('SMTP_PASS', ''),
            'smtp_security' => Settings::get('SMTP_SECURITY', 'ssl'),
            'smtp_from_email' => Settings::get('SMTP_FROM_EMAIL', 'infos@grand-baie-maurice.com'),
        ];
        View::render('pages/partner-settings', [
            'pageTitle' => 'Paramètres partenaire',
            'partnerData' => $partner,
            'smtpDefaults' => $smtpDefaults,
        ]);
    }

    public static function partnerSaveSettings(): never
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        $existing = PartnersController::formData($partnerId);
        $logoUrl = (string) ($existing['logo_url'] ?? '');
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            self::deleteLocalAsset($logoUrl, '/images/logo/');
            $logoUrl = '';
        }
        if (!empty($_FILES['logo']['name'])) {
            self::deleteLocalAsset($logoUrl, '/images/logo/');
            $logoUrl = self::storePartnerLogo($partnerId) ?? '';
        }

        Database::connection()->prepare('UPDATE partners SET name = ?, email = ?, phone = ?, facebook_url = ?, tiktok_url = ?, instagram_url = ?, logo_url = ?, primary_color = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, updated_at = NOW() WHERE id = ?')->execute([
            trim((string) ($_POST['name'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            trim((string) ($_POST['phone'] ?? '')) ?: null,
            trim((string) ($_POST['facebook_url'] ?? '')) ?: null,
            trim((string) ($_POST['tiktok_url'] ?? '')) ?: null,
            trim((string) ($_POST['instagram_url'] ?? '')) ?: null,
            $logoUrl !== '' ? $logoUrl : null,
            trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
            trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
            ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
            trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
            trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
            $partnerId,
        ]);
        self::redirect('/partner/settings', 'Paramètres sauvegardés.');
    }

    public static function adminPartners(): void
    {
        self::requireAdminUser();
        $partners = Database::connection()->query('SELECT * FROM partners ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $globalTouristTax = (float) (Database::connection()->query('SELECT per_person_per_night FROM tourist_tax LIMIT 1')->fetchColumn() ?: 0);
        $properties = [];
        try {
            $properties = (new LodgifyClient())->getProperties();
        } catch (Throwable $e) {
            Flash::set('Impossible de charger la liste des biens Lodgify pour les associer aux partenaires.', 'error');
        }
        $visibilityByPartner = [];
        $usersByPartner = [];
        foreach ($partners as $partnerRow) {
            $partnerId = (int) $partnerRow['id'];
            $visibilityByPartner[$partnerId] = PartnerPropertyVisibility::allForPartner($partnerId);
            $usersByPartner[$partnerId] = self::usersForPartner($partnerId);
        }
        View::render('pages/admin-partners', [
            'pageTitle' => 'Partenaires',
            'partners' => $partners,
            'globalTouristTax' => $globalTouristTax,
            'properties' => $properties,
            'visibilityByPartner' => $visibilityByPartner,
            'usersByPartner' => $usersByPartner,
        ]);
    }

    public static function adminSavePartnerProperties(int $id): never
    {
        self::requireAdminUser();
        $visibility = is_array($_POST['visibility'] ?? null) ? $_POST['visibility'] : [];
        PartnerPropertyVisibility::save($id, $visibility);
        self::redirect('/admin/partners', 'Biens associés mis à jour.');
    }

    /**
     * Partner-scoped user accounts, listed in the "Utilisateurs" column of
     * /admin/partners: a partner can be granted several such logins (via
     * the "Ajouter des utilisateurs" modal) so more than one person can
     * manage that partner's requests, all restricted to their own
     * partner_id exactly like the primary partner account.
     */
    private static function usersForPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, email, first_name, last_name FROM users WHERE partner_id = ? AND role = 'partner' ORDER BY email"
        );
        $stmt->execute([$partnerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function adminCreatePartnerUser(int $partnerId): never
    {
        self::requireAdminUser();
        $partner = PartnersController::formData($partnerId);
        if ($partner === []) {
            throw new HttpException(404, 'Not Found', 'Partenaire introuvable.');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $firstName = trim((string) ($_POST['first_name'] ?? '')) ?: null;
        $lastName = trim((string) ($_POST['last_name'] ?? '')) ?: null;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::redirect('/admin/partners', 'Adresse email invalide pour le nouvel utilisateur.', 'error');
        }
        if (strlen($password) < 8) {
            self::redirect('/admin/partners', 'Le mot de passe doit contenir au moins 8 caractères.', 'error');
        }

        try {
            Database::connection()->prepare(
                'INSERT INTO users (partner_id, email, password_hash, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, "partner")'
            )->execute([
                $partnerId,
                $email,
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                $firstName,
                $lastName,
            ]);
        } catch (\PDOException $e) {
            self::redirect('/admin/partners', 'Impossible de créer cet utilisateur (email déjà utilisé ?).', 'error');
        }

        self::redirect('/admin/partners', 'Utilisateur ajouté pour ce partenaire.');
    }

    public static function adminDeletePartnerUser(int $partnerId, int $userId): never
    {
        self::requireAdminUser();
        Database::connection()
            ->prepare("DELETE FROM users WHERE id = ? AND partner_id = ? AND role = 'partner'")
            ->execute([$userId, $partnerId]);
        self::redirect('/admin/partners', 'Utilisateur supprimé.');
    }

    public static function adminPartnerForm(?int $id = null): void
    {
        self::requireAdminUser();
        $partner = $id ? PartnersController::formData($id) : [
            'primary_color' => '#E61E4D',
            'markup_percent' => 0,
            'cleaning_fee_per_person_per_night' => 0,
            'active' => 1,
            'phone' => '',
            'facebook_url' => '',
            'tiktok_url' => '',
            'instagram_url' => '',
        ];
        View::render('pages/admin-partner-form', ['pageTitle' => $id ? 'Modifier partenaire' : 'Nouveau partenaire', 'partnerData' => $partner, 'editing' => $id !== null]);
    }

    public static function adminSavePartner(?int $id = null): never
    {
        self::requireAdminUser();

        // Resolve logo: upload new file, remove existing, or keep as-is.
        $existingLogoUrl = $id !== null ? (string) (PartnersController::formData($id)['logo_url'] ?? '') : '';
        $logoUrl = $existingLogoUrl;
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            self::deleteLocalAsset($logoUrl, '/images/logo/');
            $logoUrl = '';
        }
        if (!empty($_FILES['logo']['name'])) {
            self::deleteLocalAsset($logoUrl, '/images/logo/');
            $uploadedId = $id ?? 0;
            $logoUrl = self::storePartnerLogo($uploadedId) ?? '';
        }

        if ($id === null) {
            Database::connection()->prepare('INSERT INTO partners (subdomain, name, logo_url, primary_color, email, phone, facebook_url, tiktok_url, instagram_url, markup_percent, cleaning_fee_per_person_per_night, smtp_host, smtp_port, smtp_user, smtp_pass, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                trim((string) ($_POST['subdomain'] ?? '')),
                trim((string) ($_POST['name'] ?? '')),
                $logoUrl !== '' ? $logoUrl : null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['facebook_url'] ?? '')) ?: null,
                trim((string) ($_POST['tiktok_url'] ?? '')) ?: null,
                trim((string) ($_POST['instagram_url'] ?? '')) ?: null,
                (float) ($_POST['markup_percent'] ?? 0),
                (float) ($_POST['cleaning_fee_per_person_per_night'] ?? 0),
                trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
                ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
                trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
                trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
                isset($_POST['active']) ? 1 : 0,
            ]);
        } else {
            Database::connection()->prepare('UPDATE partners SET name = ?, logo_url = ?, primary_color = ?, email = ?, phone = ?, facebook_url = ?, tiktok_url = ?, instagram_url = ?, markup_percent = ?, cleaning_fee_per_person_per_night = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, active = ?, updated_at = NOW() WHERE id = ?')->execute([
                trim((string) ($_POST['name'] ?? '')),
                $logoUrl !== '' ? $logoUrl : null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) ($_POST['facebook_url'] ?? '')) ?: null,
                trim((string) ($_POST['tiktok_url'] ?? '')) ?: null,
                trim((string) ($_POST['instagram_url'] ?? '')) ?: null,
                (float) ($_POST['markup_percent'] ?? 0),
                (float) ($_POST['cleaning_fee_per_person_per_night'] ?? 0),
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
        Database::connection()->prepare('DELETE FROM partners WHERE id = ?')->execute([$id]);
        self::redirect('/admin/partners', 'Partenaire supprimé.');
    }

    public static function adminFees(): void
    {
        self::requireAdminUser();
        $tax = Database::connection()->query('SELECT * FROM tourist_tax LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: ['per_person_per_night' => 0, 'applies_to_foreigners_only' => 1, 'applies_to_children' => 0];
        $cleaningFees = Database::connection()->query('SELECT * FROM cleaning_fees ORDER BY property_id')->fetchAll(PDO::FETCH_ASSOC);
        $defaultCleaningFee = 0.0;
        $propertyCleaningFees = [];
        foreach ($cleaningFees as $fee) {
            if (($fee['property_id'] ?? null) === null) {
                $defaultCleaningFee = (float) ($fee['per_person_per_night'] ?? 0);
                continue;
            }
            $propertyCleaningFees[] = $fee;
        }
        View::render('pages/admin-fees', ['pageTitle' => 'Frais & taxes', 'tax' => $tax, 'defaultCleaningFee' => $defaultCleaningFee, 'propertyCleaningFees' => $propertyCleaningFees]);
    }

    public static function adminSmtpSettings(): void
    {
        self::requireAdminUser();
        View::render('pages/admin-smtp-settings', [
            'pageTitle' => 'SMTP par défaut',
            'smtpDefaults' => [
                'SMTP_HOST' => Settings::get('SMTP_HOST', 'mail.grand-baie-maurice.com'),
                'SMTP_PORT' => Settings::get('SMTP_PORT', '465'),
                'SMTP_USER' => Settings::get('SMTP_USER', 'infos@grand-baie-maurice.com'),
                'SMTP_PASS' => Settings::get('SMTP_PASS', ''),
                'SMTP_FROM_EMAIL' => Settings::get('SMTP_FROM_EMAIL', 'infos@grand-baie-maurice.com'),
                'SMTP_FROM_NAME' => Settings::get('SMTP_FROM_NAME', 'Grand Baie Maurice'),
            ],
        ]);
    }

    public static function adminSaveSmtpSettings(): never
    {
        self::requireAdminUser();
        Settings::set('SMTP_HOST', trim((string) ($_POST['smtp_host'] ?? '')) ?: 'mail.grand-baie-maurice.com');
        Settings::set('SMTP_PORT', trim((string) ($_POST['smtp_port'] ?? '')) ?: '465');
        Settings::set('SMTP_USER', trim((string) ($_POST['smtp_user'] ?? '')) ?: 'infos@grand-baie-maurice.com');
        Settings::set('SMTP_PASS', (string) ($_POST['smtp_pass'] ?? ''));
        Settings::set('SMTP_FROM_EMAIL', trim((string) ($_POST['smtp_from_email'] ?? '')) ?: 'infos@grand-baie-maurice.com');
        Settings::set('SMTP_FROM_NAME', trim((string) ($_POST['smtp_from_name'] ?? '')) ?: 'Grand Baie Maurice');
        Settings::set('SMTP_SECURITY', 'ssl');
        Settings::reload();
        self::redirect('/admin/smtp-settings', 'SMTP par défaut sauvegardé.');
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

    public static function adminSaveDefaultCleaningFee(): never
    {
        self::requireAdminUser();
        $amount = (float) ($_POST['per_person_per_night'] ?? 0);
        $existingId = Database::connection()->query('SELECT id FROM cleaning_fees WHERE property_id IS NULL LIMIT 1')->fetchColumn();
        if ($existingId === false) {
            Database::connection()->prepare('INSERT INTO cleaning_fees (property_id, per_person_per_night) VALUES (NULL, ?)')->execute([$amount]);
        } else {
            Database::connection()->prepare('UPDATE cleaning_fees SET per_person_per_night = ?, updated_at = NOW() WHERE id = ?')->execute([$amount, (int) $existingId]);
        }
        self::redirect('/admin/fees', 'Frais de nettoyage par défaut sauvegardés.');
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
        View::render('pages/admin-sync', [
            'pageTitle' => 'Synchronisation Lodgify',
            'lastSyncLabel' => self::formatLodgifyLastSync(),
        ]);
    }

    /**
     * Legacy all-in-one sync kept as a fallback for non-JS clients: it still
     * works, but risks being killed by the web server/PHP-FPM's hard
     * execution timeout on shared hosting before every property's photos
     * have been downloaded (see refreshAllPropertyDetails()'s docblock). The
     * admin sync page itself now drives the one-by-one flow via
     * adminSyncStart()/adminSyncProperty()/adminSyncFinish() instead.
     */
    public static function adminRunSync(): never
    {
        self::requireAdminUser();
        @set_time_limit(0);
        $client = new LodgifyClient();
        try {
            $client->invalidate('lodgify:');
            $details = $client->refreshAllPropertyDetails();
        } catch (\Throwable $e) {
            error_log('Lodgify sync: aborted before refreshing any property: ' . $e->getMessage());
            self::redirect('/admin/sync', 'Synchronisation Lodgify échouée : ' . $e->getMessage() . ' (aucun bien n\'a pu être récupéré, aucun dossier images/listings/ n\'a donc été créé).', 'error');
        }
        if ($details['refreshed'] === 0 && $details['photo_errors'] === []) {
            // Lodgify returned zero properties (e.g. empty account, wrong API
            // key/scope): this used to look identical to a real success
            // ("Synchronisation Lodgify terminée.") even though nothing at
            // all was fetched or cached, leaving no clue why images/listings/
            // stayed empty.
            self::redirect('/admin/sync', 'Synchronisation Lodgify terminée, mais Lodgify n\'a retourné aucun bien : aucun dossier images/listings/ n\'a donc été créé.', 'error');
        }
        $photoErrors = $details['photo_errors'];
        if ($photoErrors === []) {
            self::redirect('/admin/sync', 'Synchronisation Lodgify terminée.');
        }
        // Photos failed to cache locally for at least one property (e.g.
        // images/listings/ not writable, curl/SSL blocked, disk full, ...):
        // surface the concrete reason instead of a misleading "terminée"
        // message that hides the fact nothing was actually saved to disk.
        $preview = array_slice($photoErrors, 0, 3);
        $message = 'Synchronisation terminée avec ' . count($photoErrors) . ' erreur(s) de mise en cache des photos : ' . implode(' | ', $preview);
        if (count($photoErrors) > count($preview)) {
            $message .= ' … (voir le journal des erreurs pour le détail complet)';
        }
        self::redirect('/admin/sync', $message, 'error');
    }

    /**
     * Step 1/3 of the one-by-one manual sync driven by the admin sync page's
     * JavaScript: clears the whole Lodgify cache and returns the plain list
     * of property ids/names (a single lightweight "/properties" call, no
     * per-property enrichment or photo download) so the browser can loop
     * over them one at a time via adminSyncProperty(), each in its own short
     * HTTP request. This avoids the previous single-request bulk sync being
     * killed mid-way by the web server/PHP-FPM's hard execution timeout on
     * shared hosting once the account has more than a handful of properties.
     */
    public static function adminSyncStart(): never
    {
        self::requireAdminUser();
        $client = new LodgifyClient();
        $mode = (string) ($_GET['mode'] ?? 'photos');
        try {
            if ($mode === 'photos') {
                $client->invalidate('lodgify:');
            } elseif ($mode === 'texts') {
                $client->invalidate('lodgify:v2:properties');
            } else {
                self::json(['error' => 'Bad Request', 'message' => 'Mode de synchronisation invalide.'], 400);
            }
            $properties = $client->getPropertyIdsForSync();
        } catch (Throwable $e) {
            error_log('Lodgify sync: failed to start (' . $e->getMessage() . ')');
            self::json(['error' => 'Internal Server Error', 'message' => $e->getMessage()], 500);
        }
        self::json(['data' => $properties]);
    }

    /**
     * Step 2/3: re-fetches and re-caches a single property (fiche, photos
     * renamed photo1.ext/photo2.ext/... under images/listings/{id}/, ...),
     * called once per property by the admin sync page's JavaScript loop.
     * Keeping this to one property per request means a single slow property
     * or Lodgify hiccup can never block, or exhaust the execution time
     * budget of, the rest of the sync.
     */
    public static function adminSyncProperty(int $propertyId): never
    {
        self::requireAdminUser();
        if ($propertyId <= 0) {
            self::json(['error' => 'Bad Request', 'message' => 'Identifiant de bien invalide.'], 400);
        }
        $mode = (string) ($_GET['mode'] ?? 'photos');
        if (!in_array($mode, ['photos', 'texts'], true)) {
            self::json(['error' => 'Bad Request', 'message' => 'Mode de synchronisation invalide.'], 400);
        }
        $client = new LodgifyClient();
        $result = $client->refreshPropertyDetail($propertyId, $mode === 'photos');
        self::json(['data' => $result]);
    }

    /**
     * Step 3/3: once every property has been re-cached individually, rebuild
     * the aggregate "/properties" list cache (used by search/listing pages)
     * and record the sync timestamp. This call is fast: every property's
     * "/properties/{id}/rooms" response is already warm in cache from step 2,
     * so getProperties()'s per-property capacity enrichment just reads the
     * cache instead of hitting Lodgify again.
     */
    public static function adminSyncFinish(): never
    {
        self::requireAdminUser();
        $client = new LodgifyClient();
        try {
            $client->getProperties();
        } catch (Throwable $e) {
            error_log('Lodgify sync: failed to rebuild the properties list (' . $e->getMessage() . ')');
            self::json(['error' => 'Internal Server Error', 'message' => $e->getMessage()], 500);
        }
        self::json(['data' => ['last_sync_label' => self::formatLodgifyLastSync()]]);
    }

    /**
     * "Biens Lodgify" settings page: lists every property (photo, titre,
     * capacité max, nb de lits, coordonnées GPS) alongside two freshness
     * indicators — "Statut de la fiche" (photos/description/capacité,
     * refreshed once a day) and "Statut Prix" (refreshed every 30 minutes).
     * Availability itself is never shown here since it's always re-queried
     * live at search time, not cached.
     */
    public static function adminLodgifyProperties(): void
    {
        self::requireAdminUser();
        @set_time_limit(0);
        $client = new LodgifyClient();
        $properties = $client->getProperties();
        $propertyIds = array_values(array_filter(array_map(static fn(array $property): int => (int) ($property['id'] ?? 0), $properties)));
        $manualOverrides = self::manualLodgifyColumnsByPropertyId($propertyIds);
        $rows = [];
        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            $priceSnapshot = $client->getPriceStatusSnapshot($propertyId);
            $cacheStatus = $client->getCacheStatus($propertyId);
            $rateSettings = $client->getPropertyRateSettings($propertyId);
            $manual = $manualOverrides[$propertyId] ?? ['sofa_bed_count' => null, 'min_people' => null, 'extra_person_fee' => null];
            $row = $property + $priceSnapshot + $cacheStatus + ['cleaning_fee' => $rateSettings['cleaning_fee']];
            // Manual columns override Lodgify values (use explicit assignment, not +, to guarantee override)
            $row['sofa_bed_count'] = $manual['sofa_bed_count'];
            $row['min_people'] = $manual['min_people'];
            $row['extra_person_fee'] = $manual['extra_person_fee'];
            $rows[] = $row;
        }
        View::render('pages/admin-lodgify-properties', [
            'pageTitle' => 'Biens Lodgify',
            'rows' => $rows,
        ]);
    }

    public static function adminSaveLodgifyPropertiesManual(): never
    {
        self::requireAdminUser();
        @set_time_limit(0);
        $client = new LodgifyClient();
        $properties = $client->getProperties();
        $manualInput = $_POST['manual'] ?? [];
        if (!is_array($manualInput)) {
            $manualInput = [];
        }
        $pdo = Database::connection();
        $save = $pdo->prepare(
            'INSERT INTO lodgify_property_manual_columns (property_id, sofa_bed_count, min_people, extra_person_fee)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sofa_bed_count = VALUES(sofa_bed_count), min_people = VALUES(min_people), extra_person_fee = VALUES(extra_person_fee), updated_at = NOW()'
        );
        $delete = $pdo->prepare('DELETE FROM lodgify_property_manual_columns WHERE property_id = ?');

        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }
            $raw = $manualInput[(string) $propertyId] ?? null;
            if (!is_array($raw)) {
                $delete->execute([$propertyId]);
                continue;
            }
            $sofa = self::parseNullableInt($raw['sofa_bed_count'] ?? null);
            $minPeople = self::parseNullableInt($raw['min_people'] ?? null);
            $extraPersonFee = self::parseNullableFloat($raw['extra_person_fee'] ?? null);
            if ($sofa === null && $minPeople === null && $extraPersonFee === null) {
                $delete->execute([$propertyId]);
                continue;
            }
            $save->execute([$propertyId, $sofa, $minPeople, $extraPersonFee]);
        }
        self::redirect('/admin/lodgify-properties', 'Colonnes manuelles sauvegardées.');
    }

    /**
     * @param array<int> $propertyIds
     * @return array<int, array{sofa_bed_count: ?int, min_people: ?int, extra_person_fee: ?float}>
     */
    private static function manualLodgifyColumnsByPropertyId(array $propertyIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $propertyIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT property_id, sofa_bed_count, min_people, extra_person_fee
             FROM lodgify_property_manual_columns
             WHERE property_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $propertyId = (int) ($row['property_id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }
            $result[$propertyId] = [
                'sofa_bed_count' => isset($row['sofa_bed_count']) ? (int) $row['sofa_bed_count'] : null,
                'min_people' => isset($row['min_people']) ? (int) $row['min_people'] : null,
                'extra_person_fee' => isset($row['extra_person_fee']) ? (float) $row['extra_person_fee'] : null,
            ];
        }
        return $result;
    }

    private static function parseNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        return max(0, (int) $raw);
    }

    private static function parseNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        return max(0, (float) str_replace(',', '.', $raw));
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

        // Mail log — always visible (not gated behind ?run=1) since it's the
        // one thing an admin needs when a partner/client reports "I never
        // received the email": on shared/cPanel hosting the PHP error_log()
        // destination is often inaccessible, but this file lives inside the
        // deployment package itself (see Mailer::logMail()). Each line is a
        // JSON object (ts/to/subject/status/transport/host/port/security/
        // embeds/embed_bytes/duration_ms/trace[]); older plain-text lines
        // (from before the structured trace was added) are still displayed,
        // just without the extra detail.
        $mailLogPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/files/storage/logs/mail.log';
        $mailLog = [];
        if (is_file($mailLogPath)) {
            $lines = @file($mailLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse(array_slice($lines, -200)) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && isset($decoded['ts'], $decoded['to'], $decoded['status'])) {
                    $mailLog[] = $decoded + ['trace' => $decoded['trace'] ?? []];
                } else {
                    $mailLog[] = ['raw' => $line];
                }
            }
        }

        // Live Lodgify query test — lets an admin pick real dates/guests and see
        // exactly what Lodgify returns for each property (name, description,
        // availability, price/night), to diagnose why a public search might show
        // "0 hébergements disponibles" even though availability exists upstream.
        $queryTest = null;
        $queryTestInput = [
            'checkin' => (string) ($_GET['test_checkin'] ?? ''),
            'checkout' => (string) ($_GET['test_checkout'] ?? ''),
            'adults' => (string) ($_GET['test_adults'] ?? '2'),
            'children' => (string) ($_GET['test_children'] ?? '0'),
        ];
        if (isset($_GET['test_query']) && $queryTestInput['checkin'] !== '' && $queryTestInput['checkout'] !== '') {
            $queryTest = self::runLodgifyQueryTest(
                $queryTestInput['checkin'],
                $queryTestInput['checkout'],
                max(1, (int) $queryTestInput['adults']),
                max(0, (int) $queryTestInput['children'])
            );
        }

        View::render('pages/admin-diagnostic', [
            'pageTitle' => 'Diagnostic',
            'diagnostic' => $data,
            'queryTestInput' => $queryTestInput,
            'queryTest' => $queryTest,
            'mailLog' => $mailLog,
        ]);
    }

    /**
     * Queries Lodgify in real time for the given dates/guests and returns raw
     * per-property results (name, description, availability, price/night) so an
     * admin can see exactly what the API returns, independent of the home-page
     * search filtering logic.
     *
     * @return array{ok:bool,error?:string,rows?:array<int,array<string,mixed>>}
     */
    private static function runLodgifyQueryTest(string $checkin, string $checkout, int $adults, int $children): array
    {
        try {
            $client = new LodgifyClient();
            // This test claims to query Lodgify "in real time" — clear any cached
            // properties/rooms/rates first so it never silently shows stale numbers
            // (e.g. capacity/min-stay cached before a mapping fix was deployed).
            $client->invalidate('lodgify:');
            $guests = $adults + $children;
            $properties = $client->getProperties();
            $rows = [];
            foreach ($properties as $property) {
                $propertyId = (int) $property['id'];
                $row = [
                    'id' => $propertyId,
                    'name' => (string) $property['name'],
                    'description' => (string) $property['description'],
                    'max_guests' => (int) $property['max_guests'],
                    'meets_capacity' => $property['max_guests'] <= 0 || $property['max_guests'] >= $guests,
                    'available' => false,
                    'min_stay' => null,
                    'price_per_night' => null,
                    'currency' => null,
                    'error' => null,
                ];
                // A "Capacité max" of 0 can mean either the room really has no
                // configured capacity in Lodgify, or the "/properties/{id}/rooms"
                // call (the only source of that number) failed and was silently
                // swallowed — surface which one it is instead of just showing 0.
                if ((int) $property['max_guests'] <= 0) {
                    $capacityDebug = $client->getRoomCapacityDebug($propertyId);
                    if ($capacityDebug['error'] !== null) {
                        $row['error'] = 'Capacité max = 0 : échec de /properties/{id}/rooms — ' . $capacityDebug['error'];
                    }
                }
                try {
                    $row['available'] = $client->isAvailableForRange($propertyId, $checkin, $checkout);
                } catch (Throwable $e) {
                    $row['error'] = $row['error'] !== null ? $row['error'] . ' / ' . $e->getMessage() : $e->getMessage();
                }
                try {
                    // Minimum-stay restrictions are only exposed by the Rates
                    // calendar (CalendarPrice.min_stay), never by the Availability
                    // endpoint, which is why this used to always read back 1.
                    $rates = $client->getRates($propertyId, $checkin, $checkout, $guests);
                    if ($rates !== []) {
                        $row['price_per_night'] = (float) $rates[0]['price_per_night'];
                        $row['currency'] = (string) $rates[0]['currency'];
                        $minStays = array_filter(array_map(
                            static fn(array $rate): ?int => isset($rate['min_stay']) ? (int) $rate['min_stay'] : null,
                            $rates
                        ), static fn(?int $v): bool => $v !== null);
                        if ($minStays !== []) {
                            $row['min_stay'] = min($minStays);
                        }
                    }
                } catch (Throwable $e) {
                    $row['error'] = $row['error'] !== null ? $row['error'] . ' / ' . $e->getMessage() : $e->getMessage();
                }
                $rows[] = $row;
            }
            return ['ok' => true, 'rows' => $rows];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function storePartnerLogo(int $partnerId): ?string
    {
        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            return null;
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_LOGO_EXTENSIONS, true)) {
            throw new HttpException(400, 'Bad Request', 'Format de logo non supporté (jpg, jpeg, png, gif, webp).');
        }
        if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new HttpException(400, 'Bad Request', 'Le logo ne doit pas dépasser 5 Mo.');
        }

        $dir = BASE_PATH . '/images/logo';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible de créer le dossier de stockage des logos.');
        }

        $filename = 'partner-' . $partnerId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible d\'enregistrer le logo.');
        }

        return '/images/logo/' . $filename;
    }

    private static function deleteLocalAsset(string $publicPath, string $allowedPrefix): void
    {
        $publicPath = trim($publicPath);
        if ($publicPath === '' || !str_starts_with($publicPath, $allowedPrefix)) {
            return;
        }
        $fullPath = BASE_PATH . $publicPath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    public static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not-found', ['pageTitle' => 'Introuvable']);
    }

    /**
     * Renders a distinct error page for non-404 failures (5xx, transient
     * upstream API errors, ...) so genuine "resource does not exist" (404)
     * is never confused with a temporary problem the user should just retry.
     */
    public static function errorPage(int $statusCode, string $message): void
    {
        http_response_code($statusCode);
        View::render('pages/error', ['pageTitle' => 'Erreur', 'message' => $message]);
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

    /**
     * Formats the last Lodgify properties sync timestamp for display in the
     * admin section, converted to GMT+4 (Île Maurice) regardless of the
     * server's own timezone.
     */
    private static function formatLodgifyLastSync(): ?string
    {
        $raw = Settings::get('LODGIFY_LAST_SYNC_AT');
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($raw);
            $date = $date->setTimezone(new \DateTimeZone('Etc/GMT-4'));
        } catch (\Throwable $e) {
            return null;
        }
        return 'Mis à jour le ' . $date->format('d/m/Y') . ' à ' . $date->format('H:i') . ' (GMT+4)';
    }

    public static function publicRates(LodgifyClient $client, int $propertyId, string $from, string $to): array
    {
        $rawRates = $client->getRates($propertyId, $from, $to, 2);
        // The public property page must show the tenant's marked-up price, not
        // the raw Lodgify price: markup_percent was previously hardcoded to 0
        // here, so the margin configured for the current partner (resolved from
        // the request subdomain) never showed up on the public detail page,
        // even though the same markup was already applied correctly in the
        // authenticated partner rates API (LodgifyController::rates).
        $partner = Tenant::current();
        $markup = $partner ? (float) ($partner['markup_percent'] ?? 0) : 0.0;
        return array_map(static function (array $rate) use ($markup): array {
            $markedUp = round(((float) $rate['price_per_night']) * (1 + $markup / 100), 2);
            return [
                'date_from' => $rate['date_from'],
                'date_to' => $rate['date_to'],
                'currency' => $rate['currency'],
                'price_per_night' => $markedUp,
                'price_per_night_with_markup' => $markedUp,
                'markup_percent' => $markup,
                'min_stay' => $rate['min_stay'] ?? null,
            ];
        }, $rawRates);
    }
}
