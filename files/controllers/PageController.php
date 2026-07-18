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
            'preloadHeroVideo' => true,
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
        if (!empty($_GET['checkin']) && !empty($_GET['checkout'])) {
            $searched = true;
            $checkin = (string) $_GET['checkin'];
            $checkout = (string) $_GET['checkout'];
            $guests = max(1, (int) ($_GET['adults'] ?? 1)) + max(0, (int) ($_GET['children'] ?? 0));
            $partner = Tenant::current();
            try {
                $client = new LodgifyClient();
                $allProperties = self::filterVisibleProperties($client->getProperties(), $partner, true);
                $properties = array_values(array_filter(
                    $allProperties,
                    static function (array $property) use ($client, $checkin, $checkout, $guests): bool {
                        if ($property['max_guests'] > 0 && $property['max_guests'] < $guests) {
                            return false;
                        }
                        return $client->isAvailableForRange((int) $property['id'], $checkin, $checkout);
                    }
                ));
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
                if ($fromDate >= $start && $toDate > $fromDate) {
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
        // first tell us the party size (adults + children under 5 + children
        // 5-12). The properties table is only loaded/shown once at least one
        // adult is provided, so a property's capacity can be compared against
        // that party size before any date is even clickable.
        $adults = max(0, (int) ($_GET['adults'] ?? 0));
        $childrenUnder5 = max(0, (int) ($_GET['children_under5'] ?? 0));
        $children5to12 = max(0, (int) ($_GET['children_5to12'] ?? 0));
        $totalGuests = $adults + $childrenUnder5 + $children5to12;

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
                    'capacity_ok' => $maxGuests <= 0 || $maxGuests >= $totalGuests,
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
            'childrenUnder5' => $childrenUnder5,
            'children5to12' => $children5to12,
            'totalGuests' => $totalGuests,
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
        $partner = $id ? PartnersController::formData($id) : ['primary_color' => '#E61E4D', 'markup_percent' => 0, 'cleaning_fee_per_person_per_night' => 0, 'tourist_tax_per_person_per_night' => 0, 'active' => 1];
        View::render('pages/admin-partner-form', ['pageTitle' => $id ? 'Modifier partenaire' : 'Nouveau partenaire', 'partnerData' => $partner, 'editing' => $id !== null]);
    }

    public static function adminSavePartner(?int $id = null): never
    {
        self::requireAdminUser();
        if ($id === null) {
            Database::connection()->prepare('INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, cleaning_fee_per_person_per_night, tourist_tax_per_person_per_night, smtp_host, smtp_port, smtp_user, smtp_pass, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                trim((string) ($_POST['subdomain'] ?? '')),
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['logo_url'] ?? '')) ?: null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                (float) ($_POST['markup_percent'] ?? 0),
                (float) ($_POST['cleaning_fee_per_person_per_night'] ?? 0),
                (float) ($_POST['tourist_tax_per_person_per_night'] ?? 0),
                trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
                ($_POST['smtp_port'] ?? '') !== '' ? (int) $_POST['smtp_port'] : null,
                trim((string) ($_POST['smtp_user'] ?? '')) ?: null,
                trim((string) ($_POST['smtp_pass'] ?? '')) ?: null,
                isset($_POST['active']) ? 1 : 0,
            ]);
        } else {
            Database::connection()->prepare('UPDATE partners SET name = ?, logo_url = ?, primary_color = ?, email = ?, markup_percent = ?, cleaning_fee_per_person_per_night = ?, tourist_tax_per_person_per_night = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, active = ?, updated_at = NOW() WHERE id = ?')->execute([
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['logo_url'] ?? '')) ?: null,
                trim((string) ($_POST['primary_color'] ?? '#E61E4D')),
                trim((string) ($_POST['email'] ?? '')),
                (float) ($_POST['markup_percent'] ?? 0),
                (float) ($_POST['cleaning_fee_per_person_per_night'] ?? 0),
                (float) ($_POST['tourist_tax_per_person_per_night'] ?? 0),
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
        View::render('pages/admin-sync', [
            'pageTitle' => 'Synchronisation Lodgify',
            'lastSyncLabel' => self::formatLodgifyLastSync(),
        ]);
    }

    public static function adminRunSync(): never
    {
        self::requireAdminUser();
        // Refreshing every property's detail cache (photos included) can take
        // a while; avoid the request being killed by PHP's default execution
        // time limit before it finishes, same as the deployment script does
        // for its own long-running operations.
        @set_time_limit(0);
        $client = new LodgifyClient();
        $client->invalidate('lodgify:');
        $details = $client->refreshAllPropertyDetails();
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
        $rows = [];
        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            $priceSnapshot = $client->getPriceStatusSnapshot($propertyId);
            $cacheStatus = $client->getCacheStatus($propertyId);
            $rows[] = $property + $priceSnapshot + $cacheStatus;
        }
        View::render('pages/admin-lodgify-properties', [
            'pageTitle' => 'Biens Lodgify',
            'rows' => $rows,
        ]);
    }

    /**
     * Shows the raw Lodgify v1 payloads used to derive the sofa-bed count
     * for one property, so an admin can see exactly what Lodgify returns
     * when the "Canapé lit" column doesn't match what's configured in
     * Lodgify's own back-office.
     */
    public static function adminLodgifySofaBedDebug(int $propertyId): void
    {
        self::requireAdminUser();
        $client = new LodgifyClient();
        $debug = $client->getSofaBedDebug($propertyId);
        View::render('pages/admin-lodgify-sofa-bed-debug', [
            'pageTitle' => 'Débogage canapé-lit — bien #' . $propertyId,
            'debug' => $debug,
        ]);
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
