<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Flash;
use App\HttpException;
use App\LodgifyClient;
use App\Packages;
use App\Tenant;
use App\View;
use PDO;
use Throwable;

/**
 * "Offres Complètes" (packages): partner/admin management screens plus the
 * public offer pages.
 *
 * The whole feature is invisible until an admin turns on
 * partners.packages_visible for a partner: every entry point below either
 * 404s or redirects when the option is off, so a site where nobody enabled
 * it behaves exactly as it did before this feature existed.
 */
final class PackagesController extends Controller
{
    // ── Guards ────────────────────────────────────────────────────────────

    private static function requirePartnerUser(): array
    {
        $user = Auth::requireUser();
        if (($user['role'] ?? '') !== 'partner' || (int) ($user['partner_id'] ?? 0) <= 0) {
            throw new HttpException(403, 'Forbidden', 'Accès partenaire requis.');
        }
        if (!Packages::enabledForPartnerId((int) $user['partner_id'])) {
            throw new HttpException(404, 'Not Found', 'Page introuvable');
        }
        return $user;
    }

    private static function requireAdminUser(): array
    {
        $user = Auth::requireUser(true);
        if (!Packages::tablesReady()) {
            throw new HttpException(404, 'Not Found', 'Page introuvable');
        }
        return $user;
    }

    // ── Partner: management ───────────────────────────────────────────────

    public static function partnerIndex(): void
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        View::render('pages/packages-list', [
            'pageTitle' => 'Offres Complètes',
            'packages' => self::withUsage(Packages::listForPartner($partnerId)),
            'basePath' => '/partner/offres',
            'isAdmin' => false,
            'partners' => [],
            'selectedPartnerId' => $partnerId,
        ]);
    }

    public static function partnerForm(?int $id = null): void
    {
        $user = self::requirePartnerUser();
        $partnerId = (int) $user['partner_id'];
        $package = $id !== null ? Packages::findForPartner($partnerId, $id) : null;
        if ($id !== null && $package === null) {
            throw new HttpException(404, 'Not Found', 'Offre introuvable');
        }
        self::renderForm($package, $partnerId, '/partner/offres', false);
    }

    public static function partnerSave(): never
    {
        $user = self::requirePartnerUser();
        self::handleSave((int) $user['partner_id'], '/partner/offres');
    }

    public static function partnerDelete(int $id): never
    {
        $user = self::requirePartnerUser();
        Packages::delete((int) $user['partner_id'], $id);
        self::redirect('/partner/offres', 'Offre supprimée.');
    }

    // ── Admin: management (every partner's offers) ────────────────────────

    public static function adminIndex(): void
    {
        self::requireAdminUser();
        $partnerId = (int) ($_GET['partner_id'] ?? 0);
        View::render('pages/packages-list', [
            'pageTitle' => 'Offres Complètes',
            'packages' => self::withUsage(Packages::listAll($partnerId > 0 ? $partnerId : null)),
            'basePath' => '/admin/offres',
            'isAdmin' => true,
            'partners' => self::partnersWithPackagesEnabled(),
            'selectedPartnerId' => $partnerId,
        ]);
    }

    public static function adminForm(?int $id = null): void
    {
        self::requireAdminUser();
        $package = $id !== null ? Packages::find($id) : null;
        if ($id !== null && $package === null) {
            throw new HttpException(404, 'Not Found', 'Offre introuvable');
        }
        $partnerId = $package !== null
            ? (int) $package['partner_id']
            : (int) ($_GET['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            Flash::set('Choisissez d\'abord le partenaire concerné.', 'error');
            self::redirect('/admin/offres');
        }
        self::renderForm($package, $partnerId, '/admin/offres', true);
    }

    public static function adminSave(): never
    {
        self::requireAdminUser();
        $partnerId = (int) ($_POST['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            self::redirect('/admin/offres', 'Partenaire manquant.', 'error');
        }
        self::handleSave($partnerId, '/admin/offres');
    }

    public static function adminDelete(int $id): never
    {
        self::requireAdminUser();
        $package = Packages::find($id);
        if ($package !== null) {
            Packages::delete((int) $package['partner_id'], $id);
        }
        self::redirect('/admin/offres', 'Offre supprimée.');
    }

    /**
     * Admin-only switch enabling the whole feature for one partner
     * (mirrors the analytics toggle on the same form).
     */
    public static function adminTogglePartner(int $partnerId): never
    {
        Auth::requireUser(true);
        if (Database::columnExists('partners', 'packages_visible')) {
            $stmt = Database::connection()->prepare('SELECT packages_visible FROM partners WHERE id = ? LIMIT 1');
            $stmt->execute([$partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $newValue = $row && (int) $row['packages_visible'] === 1 ? 0 : 1;
            Database::connection()
                ->prepare('UPDATE partners SET packages_visible = ? WHERE id = ?')
                ->execute([$newValue, $partnerId]);
            Flash::set($newValue === 1 ? 'Offres Complètes activées pour ce partenaire.' : 'Offres Complètes désactivées pour ce partenaire.');
        }
        self::redirect('/admin/partners/' . $partnerId . '/edit');
    }

    // ── Public pages ──────────────────────────────────────────────────────

    public static function publicIndex(): void
    {
        $partner = self::requirePublicPartner();
        View::render('pages/packages-public', [
            'pageTitle' => 'Offres Complètes',
            'packages' => Packages::publicList((int) $partner['id']),
            'pricesHidden' => self::pricesHidden($partner),
        ]);
    }

    public static function publicDetail(int $id): void
    {
        $partner = self::requirePublicPartner();
        $package = Packages::findForPartner((int) $partner['id'], $id);
        if ($package === null) {
            throw new HttpException(404, 'Not Found', 'Offre introuvable');
        }
        // A draft/expired/sold-out offer stays reachable for the agency
        // itself (preview), never for a client.
        $bookable = Packages::isBookable($package);
        if (!$bookable && !Auth::isPartnerOrAdmin()) {
            throw new HttpException(404, 'Not Found', 'Offre introuvable');
        }
        View::render('pages/package-detail', [
            'pageTitle' => (string) $package['title'],
            'package' => $package,
            'bookable' => $bookable,
            'remainingStock' => Packages::remainingStock($package),
            'expiresLabel' => Packages::expiresAtLabel($package),
            'pricesHidden' => self::pricesHidden($partner),
            'requestsBlocked' => ReservationsController::agencyStrictModeBlocksClient(),
            'cacheUpdatedLabel' => PageController::calendarUpdatedAtLabel(self::pricesHidden($partner)),
        ]);
    }

    /**
     * JSON search for an offer: which of its accommodations are available
     * for the requested dates/party size, at what all-inclusive price, plus
     * close-by alternative dates when nothing matches. Reads the local cache
     * only — never the Lodgify API (see Packages::searchAccommodations()).
     */
    public static function publicSearch(int $id): never
    {
        $partner = Tenant::current();
        if (!Packages::enabledForPartner($partner)) {
            self::json(['error' => 'Not Found'], 404);
        }
        $package = Packages::findForPartner((int) $partner['id'], $id);
        if ($package === null || (!Packages::isBookable($package) && !Auth::isPartnerOrAdmin())) {
            self::json(['error' => 'Not Found'], 404);
        }

        $params = self::searchParams($_POST);
        if ($params === null) {
            self::json(['error' => 'Bad Request', 'message' => 'Dates ou nombre de personnes invalides.'], 400);
        }
        // Now that the requested party is known, re-check the stock for that
        // exact headcount (the check above only knows "at least one unit
        // left"): a persons-limited offer with a single place left must not
        // return matches — and a request button — for two travellers.
        if (!Auth::isPartnerOrAdmin()
            && !Packages::isBookable($package, $params['adults'] + $params['children_3to12'] + $params['children_under3'])
        ) {
            self::json([
                'error' => 'Conflict',
                'message' => 'Cette offre n\'est plus disponible pour ce nombre de personnes (expirée ou complète).',
            ], 409);
        }

        $extras = Packages::extrasSelection(
            $package,
            $params['flight_id'],
            $params['activity_ids'],
            $params['meal_ids'],
            $params['persons'],
            $params['transport_ids']
        );
        $search = Packages::searchAccommodations(
            $package,
            $partner,
            $params['checkin'],
            $params['checkout'],
            $params['adults'],
            $params['children_3to12'],
            $params['children_under3']
        );

        $pricesHidden = self::pricesHidden($partner);
        // What every accommodation price already contains, spelled out for
        // the client: the stay itself (nightly rate for the party, cleaning
        // fee, tourist tax) plus the offer's default options. Only labels are
        // listed — never the amount of a single line — so the offer keeps
        // being sold as a whole.
        $baseIncludes = static function (array $entry) use ($extras, $params): array {
            $labels = [
                'Hébergement : ' . (int) $entry['nights'] . ' nuit(s), tarif chambre pour '
                    . $params['persons'] . ' personne(s)',
            ];
            if ((float) ($entry['cleaning_total'] ?? 0) > 0) {
                $labels[] = 'Frais de ménage';
            }
            if ((float) ($entry['tourist_tax_total'] ?? 0) > 0) {
                $labels[] = 'Taxe de séjour';
            }
            if ($extras['flight'] !== null) {
                $labels[] = 'Vol : ' . (string) $extras['flight']['label'];
            }
            foreach (['transports' => 'Transport', 'activities' => 'Activité', 'meals' => 'Restauration'] as $block => $title) {
                foreach ($extras[$block] as $extra) {
                    if ((int) ($extra['is_mandatory'] ?? 0) === 1) {
                        $labels[] = $title . ' : ' . Packages::text($extra, 'label');
                    }
                }
            }
            return $labels;
        };
        // An offer is sold as a whole: the public page only ever shows
        // aggregated figures — the "Total Vol + Hébergement" of the first
        // step (accommodation + default options of the other steps) and the
        // all-inclusive running total of the whole offer. The per-line
        // amounts (each activity, meal, transport…) are deliberately not
        // exposed here, so no detailed price can leak to the client through
        // this endpoint.
        $decorate = static function (array $entry) use ($extras, $pricesHidden, $baseIncludes): array {
            return [
                'property_id' => (int) $entry['property_id'],
                'name' => (string) $entry['name'],
                'image_url' => $entry['image_url'] ?? null,
                'bedrooms' => (int) $entry['bedrooms'],
                'max_guests' => (int) $entry['max_guests'],
                'checkin' => (string) $entry['checkin'],
                'checkout' => (string) $entry['checkout'],
                'nights' => (int) $entry['nights'],
                'day_shift' => (int) $entry['day_shift'],
                'nights_lost' => (int) $entry['nights_lost'],
                'currency' => (string) $entry['currency'],
                'total_all_in' => $pricesHidden
                    ? null
                    : round((float) $entry['total_stay'] + (float) $extras['total'], 2),
                // Stay + everything the client already gets by default, i.e.
                // the figure shown on the accommodation card.
                'total_base' => $pricesHidden
                    ? null
                    : round((float) $entry['total_stay'] + (float) $extras['base_total'], 2),
                'includes' => $baseIncludes($entry),
            ];
        };

        $extraLabels = static fn (array $rows): array => array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => Packages::text($row, 'label'),
                'is_mandatory' => (int) ($row['is_mandatory'] ?? 0) === 1,
            ],
            $rows
        );

        // Party too big for any single accommodation: the offer proposes the
        // addresses where several of its properties can host everyone
        // together. Only the combined total of the properties the client has
        // actually ticked is priced — still one single all-inclusive figure.
        $selectedPropertyIds = [];
        foreach ((array) ($_POST['property_ids'] ?? []) as $selectedId) {
            $selectedId = (int) $selectedId;
            if ($selectedId > 0) {
                $selectedPropertyIds[] = $selectedId;
            }
        }
        $selection = null;
        if ($search['groups'] !== [] && count(array_unique($selectedPropertyIds)) >= 2) {
            $quotedSelection = Packages::quoteSelection(
                $package,
                $partner,
                $selectedPropertyIds,
                $params['checkin'],
                $params['checkout'],
                $params['adults'],
                $params['children_3to12'],
                $params['children_under3'],
                $search
            );
            if ($quotedSelection !== null) {
                $selection = [
                    'property_ids' => array_map(
                        static fn (array $item): int => (int) $item['property_id'],
                        $quotedSelection['items']
                    ),
                    'currency' => (string) $quotedSelection['currency'],
                    'total_all_in' => $pricesHidden
                        ? null
                        : round((float) $quotedSelection['total_stay'] + (float) $extras['total'], 2),
                    'total_base' => $pricesHidden
                        ? null
                        : round((float) $quotedSelection['total_stay'] + (float) $extras['base_total'], 2),
                    'includes' => $baseIncludes([
                        'nights' => (int) ($quotedSelection['items'][0]['nights'] ?? 0),
                        'cleaning_total' => array_sum(array_map(
                            static fn (array $item): float => (float) ($item['quote']['cleaning_total'] ?? 0),
                            $quotedSelection['items']
                        )),
                        'tourist_tax_total' => array_sum(array_map(
                            static fn (array $item): float => (float) ($item['quote']['tourist_tax_total'] ?? 0),
                            $quotedSelection['items']
                        )),
                    ]),
                ];
            }
        }

        self::json([
            'data' => [
                'nights' => $search['nights'],
                'persons' => $params['persons'],
                'currency' => $search['matches'][0]['currency'] ?? ($search['alternatives'][0]['currency'] ?? 'EUR'),
                'prices_hidden' => $pricesHidden,
                'extras' => [
                    'flight' => $extras['flight'] === null ? null : [
                        'id' => (int) $extras['flight']['id'],
                        'label' => (string) $extras['flight']['label'],
                    ],
                    'transports' => $extraLabels($extras['transports']),
                    'activities' => $extraLabels($extras['activities']),
                    'meals' => $extraLabels($extras['meals']),
                ],
                'matches' => array_map($decorate, $search['matches']),
                'alternatives' => array_map($decorate, $search['alternatives']),
                'groups' => $search['groups'],
                'selection' => $selection,
            ],
        ]);
    }

    /**
     * Creates the reservation request for an offer. The offer's own rules
     * (still bookable, enough stock, accommodation really available for
     * those dates) are enforced here, then the request itself is created by
     * the ordinary ReservationsController::requestReservation() flow, so it
     * ends up in /partner/reservations and /admin/reservations with the same
     * emails and the same /r/{token} client link as any other request.
     *
     * A party too big for a single accommodation can instead pick several
     * properties at one same address (property_ids[]): the whole selection is
     * re-validated and re-priced here, the party is spread over the chosen
     * properties, and one reservation request per property is created by
     * ReservationsController::requestMultiple().
     */
    public static function publicRequest(int $id): never
    {
        $partner = Tenant::current();
        if (!Packages::enabledForPartner($partner)) {
            self::json(['error' => 'Not Found'], 404);
        }
        $package = Packages::findForPartner((int) $partner['id'], $id);
        if ($package === null) {
            self::json(['error' => 'Not Found'], 404);
        }

        $params = self::searchParams($_POST);
        if ($params === null) {
            self::json(['error' => 'Bad Request', 'message' => 'Dates ou nombre de personnes invalides.'], 400);
        }
        // Re-checked at submission time, never only when the page was
        // rendered: the offer may have expired or run out of stock while the
        // client was filling the form.
        // A 'persons' stock is consumed by everyone persisted on the request
        // (adults + children of every age — see Packages::usedStock()), so
        // the very same headcount is checked here.
        if (!Packages::isBookable($package, $params['adults'] + $params['children_3to12'] + $params['children_under3'])) {
            self::json([
                'error' => 'Conflict',
                'message' => 'Cette offre n\'est plus disponible (expirée ou complète).',
            ], 409);
        }

        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $selectedPropertyIds = [];
        foreach ((array) ($_POST['property_ids'] ?? []) as $selectedId) {
            $selectedId = (int) $selectedId;
            if ($selectedId > 0) {
                $selectedPropertyIds[] = $selectedId;
            }
        }
        $selectedPropertyIds = array_values(array_unique($selectedPropertyIds));
        $search = Packages::searchAccommodations(
            $package,
            $partner,
            $params['checkin'],
            $params['checkout'],
            $params['adults'],
            $params['children_3to12'],
            $params['children_under3']
        );

        $extras = Packages::extrasSelection(
            $package,
            $params['flight_id'],
            $params['activity_ids'],
            $params['meal_ids'],
            $params['persons'],
            $params['transport_ids']
        );

        if (count($selectedPropertyIds) >= 2) {
            self::requestSameAddressSelection($package, $partner, $params, $extras, $search, $selectedPropertyIds);
        }

        $match = null;
        foreach ($search['matches'] as $candidate) {
            if ((int) $candidate['property_id'] === $propertyId) {
                $match = $candidate;
                break;
            }
        }
        if ($match === null) {
            self::json([
                'error' => 'Conflict',
                'message' => 'Cet hébergement n\'est plus disponible pour ces dates.',
            ], 409);
        }

        // Hand over to the standard reservation-request flow: it validates
        // the client fields, prices the stay, stores the request and sends
        // every existing email. $_POST is completed (never replaced) with
        // the offer's own context first.
        $_POST['property_id'] = (string) $propertyId;
        $_POST['property_name'] = (string) $match['name'];
        $_POST['checkin_date'] = $match['checkin'];
        $_POST['checkout_date'] = $match['checkout'];
        $_POST['adults'] = (string) $params['adults'];
        $_POST['children'] = (string) ($params['children_3to12'] + $params['children_under3']);
        $_POST['children_under3'] = (string) $params['children_under3'];
        $_POST['children_3to12'] = (string) $params['children_3to12'];
        $summary = self::summaryText(
            $package,
            $extras,
            [$match],
            (float) $match['total_stay'],
            (string) ($match['currency'] ?? 'EUR'),
            $params['persons']
        );
        $_POST['message'] = trim(
            (string) ($_POST['message'] ?? '') . "\n\n" . $summary
        );
        // The offer provenance itself is handed over out-of-band, never
        // through $_POST: only this endpoint has validated the offer, its
        // stock and the property/dates, so an ordinary reservation request
        // must not be able to claim a package_id of its own.
        unset($_POST['package_id'], $_POST['package_summary']);
        ReservationsController::setPackageContext((int) $package['id'], $summary);

        ReservationsController::requestReservation();
    }

    /**
     * Multi-property branch of publicRequest(): the client picked several
     * properties at the same address because no single one could host the
     * whole party. The selection is re-validated and re-priced server-side
     * (cache-only, like every offer lookup), the party is spread over the
     * properties, and one reservation request per property is created by the
     * ordinary multi-request flow.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $partner
     * @param array<string, mixed> $params searchParams()
     * @param array{flight: array<string, mixed>|null, flight_total: float, base_total: float, transports: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>, meals: array<int, array<string, mixed>>, total: float} $extras
     * @param array<string, mixed> $search searchAccommodations() for the same dates/party
     * @param array<int, int> $propertyIds
     */
    private static function requestSameAddressSelection(
        array $package,
        array $partner,
        array $params,
        array $extras,
        array $search,
        array $propertyIds
    ): never {
        $selection = Packages::quoteSelection(
            $package,
            $partner,
            $propertyIds,
            $params['checkin'],
            $params['checkout'],
            $params['adults'],
            $params['children_3to12'],
            $params['children_under3'],
            $search
        );
        if ($selection === null) {
            self::json([
                'error' => 'Conflict',
                'message' => 'Cette sélection de biens n\'est plus disponible pour ces dates.',
            ], 409);
        }

        $summary = self::summaryText(
            $package,
            $extras,
            $selection['items'],
            (float) $selection['total_stay'],
            (string) $selection['currency'],
            $params['persons']
        );
        $_POST['adults'] = (string) $params['adults'];
        $_POST['children'] = (string) ($params['children_3to12'] + $params['children_under3']);
        $_POST['children_under3'] = (string) $params['children_under3'];
        $_POST['children_3to12'] = (string) $params['children_3to12'];
        $_POST['message'] = trim(
            (string) ($_POST['message'] ?? '') . "\n\n" . $summary
        );
        unset($_POST['package_id'], $_POST['package_summary'], $_POST['items']);
        ReservationsController::setPackageContext((int) $package['id'], $summary);
        ReservationsController::setPackageItems(array_map(
            static fn (array $item): array => [
                'property_id' => (int) $item['property_id'],
                'property_name' => (string) $item['name'],
                'checkin_date' => (string) $item['checkin'],
                'checkout_date' => (string) $item['checkout'],
                'adults' => (int) $item['adults'],
                'children_3to12' => (int) $item['children_3to12'],
                'children_under3' => (int) $item['children_under3'],
                'quote' => $item['quote'],
            ],
            $selection['items']
        ));

        ReservationsController::requestMultiple();
    }

    /**
     * Presentation of one accommodation of an offer, for the "Voir le bien"
     * modal: photos, description, equipment and a plain availability
     * calendar. Deliberately price-free — an offer is sold as a whole, so
     * neither this payload nor its calendar ever exposes a nightly rate
     * (the calendar partial is rendered with no rates at all and with
     * $hidePricesForVisitor = true). Like every other offer endpoint it
     * reads the local cache only, never the Lodgify API.
     */
    public static function publicProperty(int $id, int $propertyId): never
    {
        $partner = Tenant::current();
        if (!Packages::enabledForPartner($partner)) {
            self::json(['error' => 'Not Found'], 404);
        }
        $package = Packages::findForPartner((int) $partner['id'], $id);
        if ($package === null || (!Packages::isBookable($package) && !Auth::isPartnerOrAdmin())) {
            self::json(['error' => 'Not Found'], 404);
        }
        // The property must both belong to the offer and be visible to the
        // active partner: the modal must never become a way to look at a
        // property the partner isn't allowed to see.
        if ((int) ($package['all_properties'] ?? 1) !== 1
            && !in_array($propertyId, array_map('intval', $package['property_ids'] ?? []), true)) {
            self::json(['error' => 'Not Found'], 404);
        }

        $client = new LodgifyClient();
        try {
            $visible = PageController::publicVisibleProperties($client->getPropertiesFromCache(), $partner);
        } catch (Throwable $e) {
            error_log('Packages: failed to read cached properties: ' . $e->getMessage());
            self::json(['error' => 'Service Unavailable'], 503);
        }
        $isVisible = false;
        foreach ($visible as $item) {
            if ((int) ($item['id'] ?? 0) === $propertyId) {
                $isVisible = true;
                break;
            }
        }
        if (!$isVisible) {
            self::json(['error' => 'Not Found'], 404);
        }

        $property = $client->getPropertyFromCache($propertyId);
        if ($property === null) {
            self::json(['error' => 'Not Found'], 404);
        }

        $images = [];
        foreach (($property['images'] ?? []) as $image) {
            $url = is_array($image) ? (string) ($image['url'] ?? '') : '';
            if ($url !== '') {
                $images[] = ['url' => $url, 'text' => is_array($image) ? ($image['text'] ?? null) : null];
            }
        }
        $amenities = [];
        foreach (($property['amenities_by_category'] ?? []) as $category => $names) {
            if (is_array($names) && $names !== []) {
                $amenities[(string) $category] = array_values(array_map('strval', $names));
            }
        }
        if ($amenities === []) {
            $flat = [];
            foreach (($property['amenities'] ?? []) as $amenity) {
                $name = is_array($amenity) ? (string) ($amenity['name'] ?? '') : (string) $amenity;
                if ($name !== '') {
                    $flat[] = $name;
                }
            }
            if ($flat !== []) {
                $amenities[''] = $flat;
            }
        }

        self::json([
            'data' => [
                'property_id' => $propertyId,
                'name' => View::localized($property, 'name'),
                'description' => View::localized($property, 'description'),
                'bedrooms' => (int) ($property['bedrooms'] ?? 0),
                'bathrooms' => (int) ($property['bathrooms'] ?? 0),
                'max_guests' => (int) ($property['max_guests'] ?? 0),
                'images' => $images,
                'amenities' => $amenities,
                'calendar_html' => self::availabilityCalendarHtml($client, $propertyId, isset($_GET['anchor']) ? (string) $_GET['anchor'] : null),
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Renders the shared calendar partial for the "Voir le bien" modal:
     * 4 months anchored on the requested arrival month, availability read
     * from the local cache only (never the Lodgify API) and no rates at all,
     * so not a single price can appear inside an offer page.
     */
    private static function availabilityCalendarHtml(LodgifyClient $client, int $propertyId, ?string $anchorDate): string
    {
        $calendarMonths = 4;
        try {
            $anchor = ($anchorDate !== null && $anchorDate !== '')
                ? new \DateTimeImmutable($anchorDate)
                : new \DateTimeImmutable('today');
        } catch (Throwable $e) {
            $anchor = new \DateTimeImmutable('today');
        }
        $monthStart = $anchor->modify('first day of this month');
        if ($monthStart < new \DateTimeImmutable('first day of this month')) {
            $monthStart = new \DateTimeImmutable('first day of this month');
        }
        $rangeStart = $monthStart->format('Y-m-d');
        $rangeEnd = $monthStart->modify('+' . $calendarMonths . ' months')->modify('-1 day')->format('Y-m-d');

        $availability = [];
        try {
            $availability = $client->getAvailabilityFromCache($propertyId, $rangeStart, $rangeEnd);
        } catch (Throwable $e) {
            error_log('Packages: failed to read cached availability for property ' . $propertyId . ': ' . $e->getMessage());
        }

        // Variables consumed by files/views/partials/calendar-body.php.
        $rates = [];
        $hidePricesForVisitor = true;
        $today = date('Y-m-d');
        $calendarStart = $rangeStart;
        ob_start();
        require BASE_PATH . '/files/views/partials/calendar-body.php';
        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private static function requirePublicPartner(): array
    {
        $partner = Tenant::current();
        if (!Packages::enabledForPartner($partner)) {
            throw new HttpException(404, 'Not Found', 'Page introuvable');
        }
        return $partner;
    }

    /**
     * "Mode Agence Strict": clients see the offer but not its prices
     * (identical rule to the property pages).
     */
    private static function pricesHidden(?array $partner): bool
    {
        return $partner !== null && !empty($partner['agency_strict_mode']) && !Auth::isPartnerOrAdmin();
    }

    /**
     * @param array<string, mixed> $source
     * @return array{checkin: string, checkout: string, adults: int, children_3to12: int, children_under3: int, persons: int, flight_id: ?int, transport_ids: array<int, int>, activity_ids: array<int, int>, meal_ids: array<int, int>}|null
     */
    private static function searchParams(array $source): ?array
    {
        $checkin = trim((string) ($source['checkin_date'] ?? ''));
        $checkout = trim((string) ($source['checkout_date'] ?? ''));
        $adults = max(0, (int) ($source['adults'] ?? 0));
        $children3to12 = max(0, (int) ($source['children_3to12'] ?? 0));
        $childrenUnder3 = max(0, (int) ($source['children_under3'] ?? 0));
        if ($checkin === '' || $checkout === '' || $adults < 1) {
            return null;
        }
        try {
            $checkinDate = new \DateTimeImmutable($checkin);
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (Throwable $e) {
            return null;
        }
        if ($checkoutDate <= $checkinDate) {
            return null;
        }
        $flightId = (int) ($source['flight_id'] ?? 0);
        $extraIds = ['transport_ids' => [], 'activity_ids' => [], 'meal_ids' => []];
        foreach ($extraIds as $field => $_unused) {
            foreach ((array) ($source[$field] ?? []) as $extraId) {
                $extraId = (int) $extraId;
                if ($extraId > 0) {
                    $extraIds[$field][] = $extraId;
                }
            }
        }

        return [
            'checkin' => $checkinDate->format('Y-m-d'),
            'checkout' => $checkoutDate->format('Y-m-d'),
            'adults' => $adults,
            'children_3to12' => $children3to12,
            'children_under3' => $childrenUnder3,
            // Babies are not charged as travellers in the offer's per-person
            // pricing, same rule as the accommodation capacity check.
            'persons' => $adults + $children3to12,
            'flight_id' => $flightId > 0 ? $flightId : null,
            'transport_ids' => $extraIds['transport_ids'],
            'activity_ids' => $extraIds['activity_ids'],
            'meal_ids' => $extraIds['meal_ids'],
        ];
    }

    /**
     * Plain-text recap of what the client selected, stored on the request
     * and appended to its message so the agency sees the whole offer.
     *
     * An offer is sold as a package: the recap lists what it contains but
     * only ever quotes one figure, the all-inclusive total (the same rule as
     * the public page). A party spread over several properties at the same
     * address lists one "Hébergement" line per property, still with a single
     * total.
     *
     * @param array<string, mixed> $package
     * @param array{flight: array<string, mixed>|null, flight_total: float, base_total: float, transports: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>, meals: array<int, array<string, mixed>>, total: float} $extras
     * @param array<int, array<string, mixed>> $stays
     */
    private static function summaryText(
        array $package,
        array $extras,
        array $stays,
        float $stayTotal,
        string $currency,
        int $persons
    ): string {
        $lines = ['Offre Complète : ' . (string) $package['title']];
        if ($extras['flight'] !== null) {
            $flight = $extras['flight'];
            $details = array_filter([
                (string) $flight['label'],
                (string) ($flight['airline'] ?? ''),
                (string) ($flight['cabin_class'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '');
            $lines[] = 'Vol : ' . implode(' — ', $details);
        }
        foreach ($stays as $stay) {
            $line = 'Hébergement : ' . (string) $stay['name']
                . ' du ' . (string) $stay['checkin'] . ' au ' . (string) $stay['checkout'];
            // Multi-property selection: say who stays where, the party having
            // been spread over the properties server-side.
            if (count($stays) > 1 && isset($stay['adults'])) {
                $line .= ' (' . (int) $stay['adults'] . ' adulte(s)'
                    . ', ' . (int) ($stay['children_3to12'] ?? 0) . ' enfant(s) 3-12 ans'
                    . ', ' . (int) ($stay['children_under3'] ?? 0) . ' bébé(s))';
            }
            $lines[] = $line;
        }
        foreach (['transports' => 'Transport', 'activities' => 'Activité', 'meals' => 'Restauration'] as $block => $label) {
            foreach ($extras[$block] as $extra) {
                $lines[] = $label . ' : ' . (string) $extra['label']
                    . ((int) ($extra['is_mandatory'] ?? 0) === 1 ? ' (incluse)' : '');
            }
        }
        $lines[] = 'Total de l\'offre tout compris : '
            . self::amount(round($stayTotal + (float) $extras['total'], 2), $currency)
            . ' pour ' . $persons . ' personne(s).';

        return implode("\n", $lines);
    }

    private static function amount(float $value, string $currency): string
    {
        return number_format($value, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * Adds the "how much stock is left" figures to a list of offers for the
     * management table.
     *
     * @param array<int, array<string, mixed>> $packages
     * @return array<int, array<string, mixed>>
     */
    private static function withUsage(array $packages): array
    {
        foreach ($packages as &$package) {
            $package['remaining_stock'] = Packages::remainingStock($package);
            $package['used_stock'] = Packages::usedStock((int) $package['id'], (string) $package['stock_mode']);
            $package['expires_label'] = Packages::expiresAtLabel($package);
            $package['is_expired'] = Packages::isExpired($package);
        }
        unset($package);
        return $packages;
    }

    /** @return array<int, array<string, mixed>> */
    private static function partnersWithPackagesEnabled(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, name, packages_visible FROM partners ORDER BY name ASC'
        );
        return $stmt === false ? [] : ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Shared create/update handler for both the partner and the admin form.
     */
    private static function handleSave(int $partnerId, string $basePath): never
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && Packages::findForPartner($partnerId, $id) === null) {
            throw new HttpException(404, 'Not Found', 'Offre introuvable');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            self::redirect($basePath, 'Le titre de l\'offre est obligatoire.', 'error');
        }
        $photoUrl = Packages::storeUploadedImage($_FILES['photo'] ?? null);
        try {
            $savedId = Packages::save($partnerId, $id > 0 ? $id : null, $_POST, $photoUrl);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('Packages: failed to save package: ' . $e);
            self::redirect($basePath, 'Impossible d\'enregistrer l\'offre.', 'error');
        }
        self::redirect($basePath . '/' . $savedId, 'Offre enregistrée.');
    }

    /**
     * @param array<string, mixed>|null $package
     */
    private static function renderForm(?array $package, int $partnerId, string $basePath, bool $isAdmin): void
    {
        $partner = PartnersController::formData($partnerId);
        View::render('pages/package-form', [
            'pageTitle' => $package === null ? 'Nouvelle offre' : 'Modifier l\'offre',
            'package' => $package,
            'partnerId' => $partnerId,
            'partnerName' => (string) ($partner['name'] ?? ''),
            'basePath' => $basePath,
            'isAdmin' => $isAdmin,
            'properties' => self::selectableProperties($partner),
            'expiresAtInput' => $package === null ? '' : Packages::expiresAtLocalInput($package),
            // The "Restauration" block only shows up once migration 065 has
            // been applied, so an install still on 064 keeps working. Same
            // rule for "Transport" (migration 066).
            'mealsEnabled' => Packages::mealsTableReady(),
            'transportsEnabled' => Packages::transportsTableReady(),
        ]);
    }

    /**
     * The accommodations an offer may include: the partner's visible
     * properties, read from the local cache only (the management form must
     * not depend on a live Lodgify call either).
     *
     * @param array<string, mixed> $partner
     * @return array<int, array{id: int, name: string}>
     */
    private static function selectableProperties(array $partner): array
    {
        try {
            $properties = PageController::publicVisibleProperties((new LodgifyClient())->getPropertiesFromCache(), $partner);
        } catch (Throwable $e) {
            error_log('Packages: failed to read cached properties for the offer form: ' . $e->getMessage());
            return [];
        }
        $result = [];
        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId > 0) {
                $result[] = ['id' => $propertyId, 'name' => View::localized($property, 'name')];
            }
        }
        usort($result, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $result;
    }
}
