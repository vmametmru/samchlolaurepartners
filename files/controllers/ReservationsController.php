<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\I18n;
use App\LodgifyClient;
use App\Mailer;
use App\Settings;
use App\Tenant;
use App\View;
use PDO;
use Throwable;

final class ReservationsController extends Controller
{
    /**
     * A property's real max occupancy is Lodgify's max_guests *plus* up to 2
     * babies (children_under3) per property: babies never count against
     * max_guests (adults + children 3-12 only), but each property can still
     * only physically host a limited number of babies (cots/car seats),
     * capped at 2. Kept as a single constant so quote()/requestReservation()
     * (single property) and requestMultiple() (per active property, in its
     * date-by-date capacity loop) stay in sync.
     */
    private const MAX_BABIES_PER_PROPERTY = 2;

    /** @var array{under3: string, from3to12: string}|null */
    private static ?array $childrenBreakdownColumns = null;
    private static bool $childrenBreakdownColumnsResolved = false;

    /**
     * Resolves which reservation_requests child-breakdown columns are
     * available. The UI/API now uses the < 3 / 3-12 split, but production may
     * still have the legacy migration-018 column names
     * children_under5/children_5to12. Supporting both keeps new requests and
     * later status emails working without requiring an immediate schema rename.
     */
    private static function childrenBreakdownColumns(PDO $pdo): ?array
    {
        if (self::$childrenBreakdownColumnsResolved) {
            return self::$childrenBreakdownColumns;
        }
        self::$childrenBreakdownColumnsResolved = true;

        try {
            foreach (
                [
                    ['under3' => 'children_under3', 'from3to12' => 'children_3to12'],
                    ['under3' => 'children_under5', 'from3to12' => 'children_5to12'],
                ] as $candidate
            ) {
                if (
                    self::reservationRequestColumnExists($pdo, $candidate['under3'])
                    && self::reservationRequestColumnExists($pdo, $candidate['from3to12'])
                ) {
                    self::$childrenBreakdownColumns = $candidate;
                    break;
                }
            }
        } catch (Throwable $e) {
            self::$childrenBreakdownColumns = null;
        }

        return self::$childrenBreakdownColumns;
    }

    private static function reservationRequestColumnExists(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM reservation_requests LIKE " . $pdo->quote($column));
        return $stmt !== false && $stmt->fetch() !== false;
    }

    /**
     * @param array{room_total: float, partner_rate: float, vat_rate: float, commission_total: float, extra_person_total: float, cleaning_total: float, tourist_tax_total: float, total_traveler: float, vat_total: float, nights: int, currency: string} $breakdown
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private static function quoteInsertColumnsAndParams(PDO $pdo, array $breakdown): array
    {
        if (!self::hasQuoteColumns($pdo)) {
            return [[], []];
        }
        $columns = [
            'quote_currency', 'quote_nights', 'quote_room_total', 'quote_partner_rate',
            'quote_commission_total', 'quote_extra_person_total', 'quote_cleaning_total',
            'quote_tourist_tax_total', 'quote_total_traveler',
        ];
        $params = [
            $breakdown['currency'],
            $breakdown['nights'],
            $breakdown['room_total'],
            $breakdown['partner_rate'],
            $breakdown['commission_total'],
            $breakdown['extra_person_total'],
            $breakdown['cleaning_total'],
            $breakdown['tourist_tax_total'],
            $breakdown['total_traveler'],
        ];
        if (self::hasVatRateColumn($pdo)) {
            $columns[] = 'quote_vat_rate';
            $params[] = $breakdown['vat_rate'];
        }
        return [$columns, $params];
    }

    private static ?bool $languageColumnExists = null;

    /**
     * Whether reservation_requests already has the "language" column added
     * by migration 025. Guarded the same way as childrenBreakdownColumns():
     * Migrator::autoRun() applies pending migrations on every request, but
     * on shared hosting an ALTER can fail (privileges/timing) and leave the
     * migration unapplied — referencing the column unconditionally in an
     * INSERT would then turn every reservation submission into a 500.
     */
    private static function hasLanguageColumn(PDO $pdo): bool
    {
        if (self::$languageColumnExists === null) {
            try {
                self::$languageColumnExists = self::reservationRequestColumnExists($pdo, 'language');
            } catch (Throwable $e) {
                self::$languageColumnExists = false;
            }
        }
        return self::$languageColumnExists;
    }

    private static ?bool $quoteColumnsExist = null;

    /**
     * Whether reservation_requests already has the quote_* breakdown columns
     * added by migration 028 (quote_room_total, quote_partner_rate, ...).
     * Guarded the same way as hasLanguageColumn() so submissions never 500
     * if that migration hasn't applied yet on a given install.
     */
    private static function hasQuoteColumns(PDO $pdo): bool
    {
        if (self::$quoteColumnsExist === null) {
            try {
                self::$quoteColumnsExist = self::reservationRequestColumnExists($pdo, 'quote_room_total');
            } catch (Throwable $e) {
                self::$quoteColumnsExist = false;
            }
        }
        return self::$quoteColumnsExist;
    }

    private static ?bool $vatRateColumnExists = null;

    /**
     * Whether reservation_requests already has the quote_vat_rate column
     * added by migration 031. Guarded the same way as hasQuoteColumns() so
     * submissions never 500 if that migration hasn't applied yet on a given
     * install, and so installs upgraded before migration 031 keep working
     * (commission is simply computed as if vat_rate were 0 in that case).
     */
    private static function hasVatRateColumn(PDO $pdo): bool
    {
        if (self::$vatRateColumnExists === null) {
            try {
                self::$vatRateColumnExists = self::reservationRequestColumnExists($pdo, 'quote_vat_rate');
            } catch (Throwable $e) {
                self::$vatRateColumnExists = false;
            }
        }
        return self::$vatRateColumnExists;
    }


    private static function childCount(array $source, string $primaryField, string $legacyField, int $fallback = 0): int
    {
        return max(0, (int) ($source[$primaryField] ?? $source[$legacyField] ?? $fallback));
    }

    /**
     * @return array{under3: int, from3to12: int}
     */
    private static function childBreakdownValues(array $source): array
    {
        return [
            'under3' => self::childCount($source, 'children_under3', 'children_under5'),
            'from3to12' => self::childCount(
                $source,
                'children_3to12',
                'children_5to12',
                (int) ($source['children'] ?? 0)
            ),
        ];
    }

    /**
     * Builds the {{nationalites}} email variable: one "Adulte N : <nationalité>"
     * / "Enfant N : <nationalité>" line per guest (joined with <br>), so
     * templates can show each traveler's nationality individually instead of
     * only the aggregate adult/children counts. Guests without a nationality
     * set (e.g. requests submitted before per-guest detail existed) are
     * skipped rather than shown blank.
     *
     * @param array<int, array{type?: string, nationality?: string}> $guests
     */
    public static function guestNationalitiesText(array $guests): string
    {
        $adultIndex = 0;
        $childIndex = 0;
        $lines = [];
        foreach ($guests as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $nationality = trim((string) ($guest['nationality'] ?? ''));
            if ($nationality === '') {
                continue;
            }
            $type = (string) ($guest['type'] ?? 'adult');
            if ($type === 'adult') {
                $adultIndex++;
                $label = 'Adulte ' . $adultIndex;
            } else {
                $childIndex++;
                $label = 'Enfant ' . $childIndex;
            }
            $lines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' : ' . htmlspecialchars($nationality, ENT_QUOTES, 'UTF-8');
        }
        return implode('<br>', $lines);
    }

    /**
     * Computes a live price estimate (room total + cleaning fee + tourist
     * tax) for the given property/dates/guests so the visitor sees the full
     * cost before sending a reservation request. Used by the property
     * detail page while the visitor fills the booking form.
     */
    public static function quote(): never
    {
        $input = self::input();
        $propertyId = (int) ($input['property_id'] ?? 0);
        $checkin = trim((string) ($input['checkin_date'] ?? ''));
        $checkout = trim((string) ($input['checkout_date'] ?? ''));
        $adults = max(0, (int) ($input['adults'] ?? 0));
        $childBreakdown = self::childBreakdownValues($input);
        $childrenUnder3 = $childBreakdown['under3'];
        $children3to12 = $childBreakdown['from3to12'];
        $guests = is_array($input['guests'] ?? null) ? $input['guests'] : [];

        if ($propertyId <= 0 || $checkin === '' || $checkout === '' || $adults < 1) {
            self::json(['error' => 'Bad Request', 'message' => 'Required fields missing'], 400);
        }

        try {
            $checkinDate = new \DateTimeImmutable($checkin);
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (Throwable $e) {
            self::json(['error' => 'Bad Request', 'message' => 'Invalid dates'], 400);
        }
        $nights = (int) $checkinDate->diff($checkoutDate)->days;
        if ($checkoutDate <= $checkinDate || $nights < 1) {
            self::json(['error' => 'Bad Request', 'message' => 'checkout_date must be after checkin_date'], 400);
        }

        $totalGuests = $adults + $childrenUnder3 + $children3to12;
        // Persons counted for cleaning and extra-person fees: adults + children
        // 3+ years (children under 3 are not charged for these items).
        $countedGuests = $adults + $children3to12;

        // Every property has a maximum occupancy (Lodgify's max_guests); a
        // reservation request must never exceed it, otherwise the property
        // could be booked for more people than it can actually host.
        $property = null;
        try {
            $property = (new LodgifyClient())->getProperty($propertyId);
        } catch (Throwable $e) {
            error_log('Lodgify: failed to fetch property ' . $propertyId . ': ' . $e->getMessage());
        }
        // Babies (children_under3) don't count toward the property's max
        // occupancy — consistent with the front-end guest steppers
        // (initGuestSteppers()/initApiForms() in assets/js/app.js) and with
        // requestMultiple()'s capacity check below, which only compares
        // adults + children 3-12 against max_guests. Comparing $totalGuests
        // (which includes babies) here used to reject/500 requests the UI
        // had happily allowed, hiding the quote block as soon as a baby was
        // added alongside older children.
        $maxGuests = (int) ($property['max_guests'] ?? 0);
        if ($maxGuests > 0 && $countedGuests > $maxGuests) {
            self::json([
                'error' => 'Bad Request',
                'message' => "Ce logement peut accueillir au maximum {$maxGuests} personne(s) (adultes + enfants de 3 ans et plus).",
            ], 400);
        }
        // Each property can still only host a limited number of babies,
        // regardless of how much room remains under max_guests (adults +
        // 3-12 children can be at capacity and 2 babies must still fit).
        if ($childrenUnder3 > self::MAX_BABIES_PER_PROPERTY) {
            self::json([
                'error' => 'Bad Request',
                'message' => 'Ce logement peut accueillir au maximum ' . self::MAX_BABIES_PER_PROPERTY . ' bébé(s) (enfants de moins de 3 ans).',
            ], 400);
        }

        $quoteData = self::computeItemQuote($propertyId, $property, $checkin, $checkoutDate, $adults, $totalGuests, $countedGuests, $guests);
        if ($quoteData === null) {
            self::json(['error' => 'Service Unavailable', 'message' => 'Tarifs indisponibles pour le moment'], 503);
        }

        self::json(['data' => $quoteData + [
            'grand_total' => round($quoteData['total_without_tax'] + $quoteData['tourist_tax_total'], 2),
        ]]);
    }

    /**
     * Computes the room/cleaning/extra-person/tourist-tax price breakdown for
     * a single property and date range. Shared by the public quote() endpoint
     * (property-detail booking form, called once per property) and
     * requestMultiple() (the "Calendrier" multi-property cart, which cannot
     * rely on a single set of client-submitted quote_* fields since each
     * selected item is its own property/date range). Returns null when
     * Lodgify rates can't be fetched for this property/range; the caller
     * decides how to degrade (quote() surfaces a 503, requestMultiple() sends
     * the request with a zeroed-out quote rather than failing the whole
     * multi-property submission).
     *
     * @param array<int, array{type?: string, nationality?: string}> $guests
     * @return array{nights: int, currency: string, room_total: float, extra_person_total: float, extra_person_fee_rate: float, extra_persons_count: int, cleaning_total: float, tourist_tax_total: float, tourist_tax_rate: float, total_without_tax: float, vat_rate: float}|null
     */
    private static function computeItemQuote(
        int $propertyId,
        ?array $property,
        string $checkin,
        \DateTimeImmutable $checkoutDate,
        int $adults,
        int $totalGuests,
        int $countedGuests,
        array $guests
    ): ?array {
        $nights = (int) (new \DateTimeImmutable($checkin))->diff($checkoutDate)->days;
        $pdo = Database::connection();
        // Fetched up-front so vat_rate is available to PageController::
        // publicRates() below: VAT is not included in Lodgify's rate and
        // must be added on top for VAT-registered properties (vat_rate is
        // 0/null for properties not registered for VAT, leaving the price
        // unchanged). vat_rate was added by migration 030; guard against
        // installs where it hasn't applied yet so this never 500s with
        // "Unknown column 'vat_rate'".
        $hasVatRate = Database::columnExists('lodgify_property_manual_columns', 'vat_rate');
        $manualStmt = $pdo->prepare(
            'SELECT min_people, extra_person_fee' . ($hasVatRate ? ', vat_rate' : '') . ' FROM lodgify_property_manual_columns WHERE property_id = ? LIMIT 1'
        );
        $manualStmt->execute([$propertyId]);
        $manualRow = $manualStmt->fetch(\PDO::FETCH_ASSOC);
        $manualVatRate = $manualRow && ($manualRow['vat_rate'] ?? null) !== null ? (float) $manualRow['vat_rate'] : null;
        $lodgifyClient = new LodgifyClient();
        // A manual override always wins; when none has been saved, fall
        // back to the VAT rate best-effort read live from Lodgify.
        $vatRate = PageController::resolveVatRate($lodgifyClient, $propertyId, $manualVatRate);
        try {
            $rates = PageController::publicRates($lodgifyClient, $propertyId, $checkin, $checkoutDate->modify('-1 day')->format('Y-m-d'), $vatRate);
        } catch (Throwable $e) {
            error_log((string) $e);
            return null;
        }
        $currency = $rates[0]['currency'] ?? 'EUR';
        $roomTotal = 0.0;
        foreach ($rates as $rate) {
            $roomTotal += (float) $rate['price_per_night'];
        }

        // Lodgify exposes the real per-guest/per-night cleaning fee (shown on
        // the "Tarifs & Disponibilités" tab, e.g. "+ 2,00 EUR par invité /
        // nuit pour le ménage") via the property's rate settings. Use it as
        // the authoritative rate so the quote matches what is displayed on
        // the same page, falling back to the local cleaning_fees table only
        // when Lodgify doesn't return that fee.
        $cleaningRate = null;
        foreach (($property['fees'] ?? []) as $fee) {
            if (($fee['charge_type'] ?? '') === 'PerPerson' && $fee['amount'] !== null) {
                $cleaningRate = (float) $fee['amount'];
                break;
            }
        }

        if ($cleaningRate === null) {
            $cleaningStmt = $pdo->prepare(
                'SELECT per_person_per_night FROM cleaning_fees WHERE property_id = ? LIMIT 1'
            );
            $cleaningStmt->execute([(string) $propertyId]);
            $cleaningRate = $cleaningStmt->fetchColumn();
            if ($cleaningRate === false) {
                $defaultStmt = $pdo->prepare('SELECT per_person_per_night FROM cleaning_fees WHERE property_id IS NULL LIMIT 1');
                $defaultStmt->execute();
                $cleaningRate = $defaultStmt->fetchColumn();
            }
            $cleaningRate = $cleaningRate !== false ? (float) $cleaningRate : 0.0;
        }
        $cleaningTotal = round($cleaningRate * $countedGuests * $nights, 2);

        // Extra-person fee: applies when the number of counted guests (persons
        // > 3 years) exceeds the base-rate headcount (min_people). Both values
        // are stored locally in lodgify_property_manual_columns, set via the
        // admin "Biens Lodgify" table — no Lodgify API call is needed here.
        // The stored fee is a raw, un-marked-up rate, so — like the room rate
        // in PageController::publicRates() — the current partner's
        // markup_percent must be applied here too, otherwise the extra-person
        // fee is missing the partner's commission entirely. The property's
        // vat_rate (fetched above) must also be applied, same as the room
        // rate, for VAT-registered properties.
        $extraPersonTotal = 0.0;
        $extraPersonFeeRate = 0.0;
        $extraPersonsCount = 0;
        $partnerContext = Tenant::current();
        $markupPercent = $partnerContext ? (float) ($partnerContext['markup_percent'] ?? 0) : 0.0;
        if ($manualRow) {
            $minPeople = $manualRow['min_people'] !== null ? (int) $manualRow['min_people'] : null;
            $rawExtraPersonFeeRate = $manualRow['extra_person_fee'] !== null ? (float) $manualRow['extra_person_fee'] : 0.0;
            $extraPersonFeeRate = round($rawExtraPersonFeeRate * (1 + $markupPercent / 100) * (1 + $vatRate / 100), 2);
            if ($minPeople !== null && $countedGuests > $minPeople && $extraPersonFeeRate > 0) {
                $extraPersonsCount = $countedGuests - $minPeople;
                $extraPersonTotal = round($extraPersonFeeRate * $extraPersonsCount * $nights, 2);
            }
        }

        $taxRow = $pdo->query('SELECT * FROM tourist_tax LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [
            'per_person_per_night' => 0,
            'applies_to_foreigners_only' => 1,
            'applies_to_children' => 0,
        ];
        $taxRate = (float) $taxRow['per_person_per_night'];
        $foreignersOnly = (bool) $taxRow['applies_to_foreigners_only'];
        $appliesToChildren = (bool) $taxRow['applies_to_children'];

        $qualifyingGuests = 0;
        if (count($guests) > 0) {
            foreach ($guests as $guest) {
                $type = (string) ($guest['type'] ?? 'adult');
                $isChild = $type !== 'adult';
                if ($isChild && !$appliesToChildren) {
                    continue;
                }
                $nationality = trim((string) ($guest['nationality'] ?? ''));
                if ($foreignersOnly && strcasecmp($nationality, 'Mauricienne') === 0) {
                    continue;
                }
                $qualifyingGuests++;
            }
        } else {
            // No per-guest nationality detail provided: fall back to a
            // conservative estimate. Tourist tax applies to persons > 11 years
            // (adults), non-Mauriciens only.
            $qualifyingGuests = $appliesToChildren ? $totalGuests : $adults;
        }
        $touristTaxTotal = round($taxRate * $qualifyingGuests * $nights, 2);

        $totalWithoutTax = round($roomTotal + $extraPersonTotal + $cleaningTotal, 2);

        return [
            'nights' => $nights,
            'currency' => $currency,
            'room_total' => round($roomTotal, 2),
            'extra_person_total' => $extraPersonTotal,
            'extra_person_fee_rate' => $extraPersonFeeRate,
            'extra_persons_count' => $extraPersonsCount,
            'cleaning_total' => $cleaningTotal,
            'tourist_tax_total' => $touristTaxTotal,
            'tourist_tax_rate' => $taxRate,
            'total_without_tax' => $totalWithoutTax,
            'vat_rate' => $vatRate,
        ];
    }

    public static function requestReservation(): never
    {
        $input = self::input();
        $clientName = trim((string) ($input['client_name'] ?? ''));
        $clientEmail = trim((string) ($input['client_email'] ?? ''));
        $checkin = trim((string) ($input['checkin_date'] ?? ''));
        $checkout = trim((string) ($input['checkout_date'] ?? ''));
        $adults = (int) ($input['adults'] ?? 0);
        $propertyId = (int) ($input['property_id'] ?? 0);
        $childBreakdown = self::childBreakdownValues($input);
        $childrenUnder3 = $childBreakdown['under3'];
        $children3to12 = $childBreakdown['from3to12'];

        if ($clientName === '' || $clientEmail === '' || $checkin === '' || $checkout === '' || $adults === 0) {
            self::json(['error' => 'Bad Request', 'message' => 'Required fields missing'], 400);
        }
        if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
            self::json(['error' => 'Bad Request', 'message' => 'Invalid client_email'], 400);
        }

        // A property can only host a limited number of people (Lodgify's
        // max_guests): reject the request if the requested party size
        // exceeds it, so a visitor cannot book more guests than the
        // property can actually accommodate. Babies (children_under3) don't
        // count toward this limit — consistent with the front-end guest
        // steppers and requestMultiple()'s capacity check.
        $property = null;
        $countedGuests = $adults + $children3to12;
        $totalGuests = $adults + $childrenUnder3 + $children3to12;
        if ($propertyId > 0) {
            try {
                $property = (new LodgifyClient())->getProperty($propertyId);
                $maxGuests = (int) ($property['max_guests'] ?? 0);
                if ($maxGuests > 0 && $countedGuests > $maxGuests) {
                    self::json([
                        'error' => 'Bad Request',
                        'message' => "Ce logement peut accueillir au maximum {$maxGuests} personne(s) (adultes + enfants de 3 ans et plus).",
                    ], 400);
                }
            } catch (Throwable $e) {
                error_log('Lodgify: failed to fetch property ' . $propertyId . ' for capacity check: ' . $e->getMessage());
            }
        }
        // Each property can still only host a limited number of babies,
        // regardless of how much room remains under max_guests.
        if ($childrenUnder3 > self::MAX_BABIES_PER_PROPERTY) {
            self::json([
                'error' => 'Bad Request',
                'message' => 'Ce logement peut accueillir au maximum ' . self::MAX_BABIES_PER_PROPERTY . ' bébé(s) (enfants de moins de 3 ans).',
            ], 400);
        }

        $partner = self::requirePartnerContext();
        $pdo = Database::connection();

        $breakdownColumns = self::childrenBreakdownColumns($pdo);
        $columns = ['partner_id', 'property_id', 'property_name', 'client_name', 'client_email', 'client_phone', 'checkin_date', 'checkout_date', 'adults', 'children'];
        $params = [
            (int) $partner['id'],
            self::nullableString($input['property_id'] ?? null),
            (string) ($input['property_name'] ?? ''),
            $clientName,
            $clientEmail,
            self::nullableString($input['client_phone'] ?? null),
            $checkin,
            $checkout,
            $adults,
            (int) ($input['children'] ?? 0),
        ];
        if ($breakdownColumns !== null) {
            $columns[] = $breakdownColumns['under3'];
            $columns[] = $breakdownColumns['from3to12'];
            $params[] = $childrenUnder3;
            $params[] = $children3to12;
        }
        $requestLanguage = I18n::current();
        if (self::hasLanguageColumn($pdo)) {
            $columns[] = 'language';
            $params[] = $requestLanguage;
        }
        $columns[] = 'guests';
        $columns[] = 'message';
        $guests = is_array($input['guests'] ?? null) ? $input['guests'] : [];
        $params[] = json_encode($guests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $params[] = self::nullableString($input['message'] ?? null);
        // The client-submitted quote_* hidden fields (populated by the
        // property-detail booking form's live quote box) are only used as a
        // last-resort fallback: if the visitor's browser never finished
        // fetching a quote (slow/failed request, or the form was submitted
        // before fetchQuote()/renderQuote() populated the hidden fields),
        // those fields stay at their default "0"/empty value and every
        // {{tarif_*}}/{{commission_partenaire}}/{{paiement_a_samchlolaure}}
        // email variable would silently show 0,00 EUR. Recomputing the
        // breakdown server-side here (same authoritative logic as quote()
        // and requestMultiple()'s computeItemQuote()) keeps the persisted
        // and emailed amounts trustworthy regardless of what the client sent.
        $quoteInput = [
            'room_total' => $input['quote_room_total'] ?? 0,
            'extra_person_total' => $input['quote_extra_person_total'] ?? 0,
            'cleaning_total' => $input['quote_cleaning_total'] ?? 0,
            'tourist_tax_total' => $input['quote_tourist_tax_total'] ?? 0,
            'nights' => $input['quote_nights'] ?? 0,
            'currency' => $input['quote_currency'] ?? 'EUR',
            'vat_rate' => $input['quote_vat_rate'] ?? 0,
        ];
        if ($propertyId > 0 && $checkin !== '' && $checkout !== '') {
            try {
                $checkoutDate = new \DateTimeImmutable($checkout);
                $serverQuote = self::computeItemQuote($propertyId, $property, $checkin, $checkoutDate, $adults, $totalGuests, $countedGuests, $guests);
                if ($serverQuote !== null) {
                    $quoteInput = $serverQuote;
                }
            } catch (Throwable $e) {
                error_log('Failed to recompute server-side quote for property ' . $propertyId . ': ' . $e->getMessage());
            }
        }
        $quoteBreakdown = self::computeQuoteBreakdown($quoteInput, (float) ($partner['markup_percent'] ?? 0), (float) ($quoteInput['vat_rate'] ?? 0));
        [$quoteColumns, $quoteParams] = self::quoteInsertColumnsAndParams($pdo, $quoteBreakdown);
        $columns = [...$columns, ...$quoteColumns];
        $params = [...$params, ...$quoteParams];

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reservation_requests (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', array_fill(0, count($params), '?')) . ')'
            );
            $stmt->execute($params);
            $id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to submit request'], 500);
        }

        // The request is already persisted at this point: a notification-email
        // failure (SMTP down, invalid template, slow Lodgify lookup, ...) must
        // never turn an otherwise-successful submission into a 500 for the
        // visitor, who would then wrongly believe nothing was recorded.
        try {
            $emailInput = $input;
            $emailInput['children_under3'] = $childrenUnder3;
            $emailInput['children_3to12'] = $children3to12;
            $emailInput['language'] = $requestLanguage;
            // Use the server-recomputed/authoritative $quoteInput (the same
            // values just persisted to reservation_requests above), never
            // the client-submitted quote_* fields still sitting in $input:
            // those can be stale (debounced AJAX quote not yet resolved,
            // guest count changed after the last fetch, ...) and would make
            // the emailed price diverge from what was actually shown on the
            // site and stored in the database.
            $emailInput['quote_currency'] = $quoteInput['currency'] ?? 'EUR';
            $emailInput['quote_nights'] = $quoteInput['nights'] ?? 0;
            $emailInput['quote_room_total'] = $quoteInput['room_total'] ?? 0;
            $emailInput['quote_extra_person_total'] = $quoteInput['extra_person_total'] ?? 0;
            $emailInput['quote_cleaning_total'] = $quoteInput['cleaning_total'] ?? 0;
            $emailInput['quote_tourist_tax_total'] = $quoteInput['tourist_tax_total'] ?? 0;
            $emailInput['quote_vat_rate'] = $quoteInput['vat_rate'] ?? 0;
            self::sendRequestEmails($partner, $emailInput);
        } catch (Throwable $e) {
            error_log('Failed to send reservation request emails: ' . $e);
        }

        self::json(['data' => ['id' => $id], 'message' => 'Reservation request submitted'], 201);
    }

    /**
     * Lets a visitor request several properties in one go (built from the
     * "Calendrier" board where a date range can be picked per property row).
     * All items share the same party size and client info. Distinct
     * properties can be combined to reach the requested party size, so no
     * single item is rejected for having an individually insufficient
     * capacity; instead the full selection is re-checked night by night
     * before insert: adults + children 3-12 must fit within the active
     * properties' combined max_guests for that date, and babies must also
     * respect the max-2-per-property rule on every night.
     */
    public static function requestMultiple(): never
    {
        $input = self::input();
        $clientName = trim((string) ($input['client_name'] ?? ''));
        $clientEmail = trim((string) ($input['client_email'] ?? ''));
        $adults = max(0, (int) ($input['adults'] ?? 0));
        $childBreakdown = self::childBreakdownValues($input);
        $childrenUnder3 = $childBreakdown['under3'];
        $children3to12 = $childBreakdown['from3to12'];
        $countedGuests = $adults + $children3to12;
        $totalGuests = $adults + $childrenUnder3 + $children3to12;
        $children = $childrenUnder3 + $children3to12;

        $items = $input['items'] ?? [];
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($items)) {
            $items = [];
        }
        $guests = is_array($input['guests'] ?? null) ? $input['guests'] : [];

        if ($clientName === '' || $clientEmail === '' || $adults < 1 || $items === []) {
            self::json(['error' => 'Bad Request', 'message' => 'Required fields missing'], 400);
        }
        if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
            self::json(['error' => 'Bad Request', 'message' => 'Invalid client_email'], 400);
        }

        $client = new LodgifyClient();
        $normalizedItems = [];
        $capacityByProperty = [];
        $earliestCheckin = null;
        $latestCheckout = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                self::json(['error' => 'Bad Request', 'message' => 'Invalid item in selection'], 400);
            }
            $propertyId = (int) ($item['property_id'] ?? 0);
            $checkin = trim((string) ($item['checkin_date'] ?? ''));
            $checkout = trim((string) ($item['checkout_date'] ?? ''));
            $propertyName = trim((string) ($item['property_name'] ?? ''));

            if ($propertyId <= 0 || $checkin === '' || $checkout === '') {
                self::json(['error' => 'Bad Request', 'message' => 'Chaque bien sélectionné doit avoir un identifiant et des dates valides'], 400);
            }
            try {
                $checkinDate = new \DateTimeImmutable($checkin);
                $checkoutDate = new \DateTimeImmutable($checkout);
            } catch (Throwable $e) {
                self::json(['error' => 'Bad Request', 'message' => 'Dates invalides pour ' . ($propertyName !== '' ? $propertyName : 'un bien sélectionné')], 400);
            }
            if ($checkoutDate <= $checkinDate) {
                self::json(['error' => 'Bad Request', 'message' => "La date de départ doit être après la date d'arrivée pour " . ($propertyName !== '' ? $propertyName : 'un bien sélectionné')], 400);
            }

            $property = null;
            try {
                $property = $client->getProperty($propertyId);
            } catch (Throwable $e) {
                error_log('Lodgify: failed to fetch property ' . $propertyId . ' for multi-booking capacity check: ' . $e->getMessage());
            }
            if ($propertyName === '') {
                $propertyName = (string) ($property['name'] ?? ('Bien #' . $propertyId));
            }
            $maxGuests = (int) ($property['max_guests'] ?? 0);
            if (!isset($capacityByProperty[$propertyId])) {
                $capacityByProperty[$propertyId] = $maxGuests;
            }

            // Unlike the single-property booking form, this multi-property
            // cart never posts client-computed quote_* fields (there is no
            // single "the quote" — each selected property/date range has its
            // own price). Compute each item's own quote server-side here
            // (same room/cleaning/extra-person/tourist-tax logic as the
            // public /api/reservations/quote endpoint) so every confirmation
            // email actually shows real amounts instead of 0,00 EUR. A null
            // result (Lodgify rates unavailable) degrades to a zeroed quote
            // rather than failing the whole submission.
            $itemQuote = self::computeItemQuote($propertyId, $property, $checkinDate->format('Y-m-d'), $checkoutDate, $adults, $totalGuests, $countedGuests, $guests);

            $normalizedItems[] = [
                'property_id' => $propertyId,
                'property_name' => $propertyName,
                'checkin_date' => $checkinDate->format('Y-m-d'),
                'checkout_date' => $checkoutDate->format('Y-m-d'),
                'quote' => $itemQuote,
            ];
            if ($earliestCheckin === null || $checkinDate < $earliestCheckin) {
                $earliestCheckin = $checkinDate;
            }
            if ($latestCheckout === null || $checkoutDate > $latestCheckout) {
                $latestCheckout = $checkoutDate;
            }
        }

        if ($earliestCheckin !== null && $latestCheckout !== null) {
            for ($cursor = $earliestCheckin; $cursor < $latestCheckout; $cursor = $cursor->modify('+1 day')) {
                $day = $cursor->format('Y-m-d');
                $activePropertyIds = [];
                foreach ($normalizedItems as $item) {
                    if ($day >= $item['checkin_date'] && $day < $item['checkout_date']) {
                        $activePropertyIds[(int) $item['property_id']] = true;
                    }
                }

                $activeCapacity = 0;
                $hasUnlimitedProperty = false;
                foreach (array_keys($activePropertyIds) as $activePropertyId) {
                    $propertyCapacity = (int) ($capacityByProperty[$activePropertyId] ?? 0);
                    if ($propertyCapacity <= 0) {
                        $hasUnlimitedProperty = true;
                        continue;
                    }
                    $activeCapacity += $propertyCapacity;
                }

                $adultOk = $countedGuests <= 0 || $hasUnlimitedProperty || $activeCapacity >= $countedGuests;
                $babyCapacity = count($activePropertyIds) * self::MAX_BABIES_PER_PROPERTY;
                $babyOk = $childrenUnder3 <= 0 || $childrenUnder3 <= $babyCapacity;
                if ($adultOk && $babyOk) {
                    continue;
                }

                $message = "Capacité insuffisante pour {$countedGuests} Personnes >3ans";
                if ($childrenUnder3 > 0) {
                    $message .= " + {$childrenUnder3} bébé" . ($childrenUnder3 > 1 ? 's' : '');
                }
                $message .= ' sur une ou plusieurs dates : sélectionnez un ou plusieurs biens supplémentaires.';

                self::json([
                    'error' => 'Bad Request',
                    'message' => $message,
                ], 400);
            }
        }

        $partner = self::requirePartnerContext();
        $pdo = Database::connection();
        $createdIds = [];
        $guestsJson = json_encode($input['guests'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $clientPhone = self::nullableString($input['client_phone'] ?? null);
        $message = self::nullableString($input['message'] ?? null);

        try {
            $pdo->beginTransaction();
            $breakdownColumns = self::childrenBreakdownColumns($pdo);
            $columns = ['partner_id', 'property_id', 'property_name', 'client_name', 'client_email', 'client_phone', 'checkin_date', 'checkout_date', 'adults', 'children'];
            if ($breakdownColumns !== null) {
                $columns[] = $breakdownColumns['under3'];
                $columns[] = $breakdownColumns['from3to12'];
            }
            $requestLanguage = I18n::current();
            $hasLanguageColumn = self::hasLanguageColumn($pdo);
            if ($hasLanguageColumn) {
                $columns[] = 'language';
            }
            $columns[] = 'guests';
            $columns[] = 'message';
            $hasQuoteColumns = self::hasQuoteColumns($pdo);
            $hasVatRateColumn = self::hasVatRateColumn($pdo);
            $quoteColumnNames = [];
            if ($hasQuoteColumns) {
                $quoteColumnNames = [
                    'quote_currency', 'quote_nights', 'quote_room_total', 'quote_partner_rate',
                    'quote_commission_total', 'quote_extra_person_total', 'quote_cleaning_total',
                    'quote_tourist_tax_total', 'quote_total_traveler',
                ];
                if ($hasVatRateColumn) {
                    $quoteColumnNames[] = 'quote_vat_rate';
                }
                $columns = [...$columns, ...$quoteColumnNames];
            }
            $stmt = $pdo->prepare(
                'INSERT INTO reservation_requests (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            foreach ($normalizedItems as $item) {
                $params = [
                    (int) $partner['id'],
                    (string) $item['property_id'],
                    $item['property_name'],
                    $clientName,
                    $clientEmail,
                    $clientPhone,
                    $item['checkin_date'],
                    $item['checkout_date'],
                    $adults,
                    $children,
                ];
                if ($breakdownColumns !== null) {
                    $params[] = $childrenUnder3;
                    $params[] = $children3to12;
                }
                if ($hasLanguageColumn) {
                    $params[] = $requestLanguage;
                }
                $params[] = $guestsJson;
                $params[] = $message;
                if ($hasQuoteColumns) {
                    $itemBreakdown = self::computeQuoteBreakdown(
                        $item['quote'] ?? [],
                        (float) ($partner['markup_percent'] ?? 0),
                        (float) ($item['quote']['vat_rate'] ?? 0)
                    );
                    $params[] = $itemBreakdown['currency'];
                    $params[] = $itemBreakdown['nights'];
                    $params[] = $itemBreakdown['room_total'];
                    $params[] = $itemBreakdown['partner_rate'];
                    $params[] = $itemBreakdown['commission_total'];
                    $params[] = $itemBreakdown['extra_person_total'];
                    $params[] = $itemBreakdown['cleaning_total'];
                    $params[] = $itemBreakdown['tourist_tax_total'];
                    $params[] = $itemBreakdown['total_traveler'];
                    if ($hasVatRateColumn) {
                        $params[] = $itemBreakdown['vat_rate'];
                    }
                }
                $stmt->execute($params);
                $createdIds[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to submit requests'], 500);
        }

        // The requests are already persisted (and committed) at this point: a
        // notification-email failure must never turn an otherwise-successful
        // submission into a 500 for the visitor, who would then wrongly
        // believe nothing was recorded.
        $itemCount = count($normalizedItems);
        foreach ($normalizedItems as $item) {
            // computeItemQuote() returns null when Lodgify rates couldn't be
            // fetched for this item; degrade to a zeroed quote (via the ??
            // fallbacks below) instead of accessing array offsets on null.
            $quote = $item['quote'] ?? [];
            try {
                self::sendRequestEmails($partner, [
                    'property_id' => $item['property_id'],
                    'client_name' => $clientName,
                    'client_email' => $clientEmail,
                    'client_phone' => $clientPhone,
                    'checkin_date' => $item['checkin_date'],
                    'checkout_date' => $item['checkout_date'],
                    'adults' => $adults,
                    'children' => $children,
                    'children_under3' => $childrenUnder3,
                    'children_3to12' => $children3to12,
                    'property_name' => $item['property_name'],
                    'message' => $message,
                    'guests' => $input['guests'] ?? [],
                    'quote_currency' => $quote['currency'] ?? 'EUR',
                    'quote_nights' => $quote['nights'] ?? 0,
                    'quote_room_total' => $quote['room_total'] ?? 0,
                    'quote_extra_person_total' => $quote['extra_person_total'] ?? 0,
                    'quote_cleaning_total' => $quote['cleaning_total'] ?? 0,
                    'quote_total_without_tax' => $quote['total_without_tax'] ?? 0,
                    'quote_tourist_tax_total' => $quote['tourist_tax_total'] ?? 0,
                    'quote_vat_rate' => $quote['vat_rate'] ?? 0,
                    'language' => $requestLanguage,
                ], $itemCount);
            } catch (Throwable $e) {
                error_log('Failed to send reservation request emails: ' . $e);
            }
        }

        self::json(['data' => ['ids' => $createdIds], 'message' => 'Reservation requests submitted'], 201);
    }

    public static function index(): never
    {
        $user = Auth::requireUser();
        $partnerId = ($user['role'] ?? '') === 'admin'
            ? (isset($_GET['partner_id']) ? (string) $_GET['partner_id'] : null)
            : ($user['partner_id'] ?? null);

        $stmt = Database::connection()->prepare(
            'SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at
             FROM reservation_requests rr
             LEFT JOIN reservations r ON r.request_id = rr.id
             WHERE rr.partner_id = ?
             ORDER BY rr.created_at DESC'
        );
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['guests'] = self::decodeGuests($row['guests'] ?? null);
        }
        self::json(['data' => $rows]);
    }

    public static function show(int $id): never
    {
        $user = Auth::requireUser();
        $partnerId = ($user['role'] ?? '') === 'admin' && isset($_GET['partner_id']) ? (string) $_GET['partner_id'] : ($user['partner_id'] ?? null);
        $stmt = Database::connection()->prepare(
            'SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes
             FROM reservation_requests rr
             LEFT JOIN reservations r ON r.request_id = rr.id
             WHERE rr.id = ? AND rr.partner_id = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::json(['error' => 'Not Found', 'message' => 'Reservation not found'], 404);
        }
        $row['guests'] = self::decodeGuests($row['guests'] ?? null);
        self::json(['data' => $row]);
    }

    public static function confirm(int $id): never
    {
        $user = Auth::requireUser();
        $partnerId = (int) ($user['partner_id'] ?? 0);
        $input = self::input();
        $notes = self::nullableString($input['notes'] ?? null);

        $request = self::confirmForPartner($partnerId, $id, $notes);
        if (!$request) {
            self::json(['error' => 'Not Found', 'message' => 'Reservation request not found'], 404);
        }

        self::json(['data' => null, 'message' => 'Reservation confirmed']);
    }

    public static function cancel(int $id): never
    {
        $user = Auth::requireUser();
        $partnerId = (int) ($user['partner_id'] ?? 0);

        $request = self::cancelForPartner($partnerId, $id);
        if (!$request) {
            self::json(['error' => 'Not Found', 'message' => 'Reservation request not found'], 404);
        }

        self::json(['data' => null, 'message' => 'Reservation cancelled']);
    }

    /**
     * Persists the confirmation (reservations upsert + reservation_requests
     * status) and sends the RESERVATION_CONFIRMED notification email to the
     * client. Shared by the JSON API (self::confirm()) and the partner web
     * form (PageController::partnerConfirmReservation()) so both entry
     * points reliably notify the client instead of only one of them.
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found for this partner.
     */
    public static function confirmForPartner(int $partnerId, int $id, ?string $notes): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $partnerId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }

        try {
            $pdo->prepare(
                'INSERT INTO reservations (request_id, partner_id, confirmed_at, notes)
                 VALUES (?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE confirmed_at = NOW(), cancelled_at = NULL, notes = VALUES(notes)'
            )->execute([$id, $partnerId, $notes]);
            $pdo->prepare("UPDATE reservation_requests SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partnerId]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to confirm reservation'], 500);
        }

        // The confirmation is already persisted: a notification-email failure
        // must not turn an otherwise-successful confirmation into a 500.
        try {
            $partner = self::fetchPartner($partnerId);
            self::sendReservationStatusEmail($partner, $request, 'RESERVATION_CONFIRMED', $notes);
        } catch (Throwable $e) {
            error_log('Failed to send reservation confirmation email: ' . $e);
        }

        // If the stay's check-in is already less than a schedule's
        // days_before_arrival away (e.g. confirmed only 2 days before
        // arrival with a 5-day reminder), Scheduler::runOnce()'s daily cron
        // pass would never have caught the reminder's original date and
        // would otherwise only send it on its next run. Triggering it here,
        // scoped to this reservation, sends any due reminder immediately
        // instead of making the client/partner wait for the next cron tick.
        try {
            $reservationStmt = $pdo->prepare('SELECT id FROM reservations WHERE request_id = ? LIMIT 1');
            $reservationStmt->execute([$id]);
            $reservationId = (int) ($reservationStmt->fetchColumn() ?: 0);
            if ($reservationId > 0) {
                \App\Scheduler::runOnce($reservationId);
            }
        } catch (Throwable $e) {
            error_log('Failed to send immediate reminder check for reservation request ' . $id . ': ' . $e);
        }

        return $request;
    }

    /**
     * Persists the cancellation (reservations.cancelled_at + reservation_requests
     * status) and sends the RESERVATION_CANCELLED notification email to the
     * client. Shared by the JSON API (self::cancel()) and the partner web
     * form (PageController::partnerCancelReservation()) so both entry points
     * reliably notify the client instead of only one of them.
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found for this partner.
     */
    public static function cancelForPartner(int $partnerId, int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $partnerId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }

        try {
            $pdo->prepare('UPDATE reservations SET cancelled_at = NOW() WHERE request_id = ?')->execute([$id]);
            $pdo->prepare("UPDATE reservation_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partnerId]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to cancel reservation'], 500);
        }

        // The cancellation is already persisted: a notification-email failure
        // must not turn an otherwise-successful cancellation into a 500.
        try {
            $partner = self::fetchPartner($partnerId);
            self::sendReservationStatusEmail($partner, $request, 'RESERVATION_CANCELLED', null);
        } catch (Throwable $e) {
            error_log('Failed to send reservation cancellation email: ' . $e);
        }

        return $request;
    }

    /**
     * Persists reopening a confirmed/cancelled reservation back to "pending"
     * ("Ouverte") — e.g. the partner made a mistake or the booking needs to
     * be re-discussed with the client — and sends the RESERVATION_REOPENED
     * notification email. Shared by the JSON API and the partner web form
     * (PageController::partnerReopenReservation()), mirroring confirmForPartner()/
     * cancelForPartner().
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found for this partner.
     */
    public static function reopenForPartner(int $partnerId, int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $partnerId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }

        try {
            $pdo->prepare('UPDATE reservations SET confirmed_at = NULL, cancelled_at = NULL WHERE request_id = ?')->execute([$id]);
            $pdo->prepare("UPDATE reservation_requests SET status = 'pending', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partnerId]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to reopen reservation'], 500);
        }

        // The status change is already persisted: a notification-email
        // failure must not turn an otherwise-successful reopening into a 500.
        try {
            $partner = self::fetchPartner($partnerId);
            self::sendReservationStatusEmail($partner, $request, 'RESERVATION_REOPENED', null);
        } catch (Throwable $e) {
            error_log('Failed to send reservation reopened email: ' . $e);
        }

        return $request;
    }

    /**
     * Resolves the owning partner_id for a reservation request, regardless
     * of partner, so admin-only actions (confirm/cancel/reopen/delete) can
     * reuse the same partner-scoped logic (confirmForPartner()/cancelForPartner()/
     * reopenForPartner()) without duplicating it. Returns null if the
     * request doesn't exist.
     */
    private static function partnerIdForRequest(int $id): ?int
    {
        $stmt = Database::connection()->prepare('SELECT partner_id FROM reservation_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $partnerId = $stmt->fetchColumn();
        return $partnerId !== false ? (int) $partnerId : null;
    }

    /**
     * Admin-only equivalent of confirmForPartner() that isn't restricted to
     * a single partner: it looks up the request's own partner_id first, then
     * delegates so the client still receives the same confirmation email.
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found.
     */
    public static function confirmForAdmin(int $id, ?string $notes): ?array
    {
        $partnerId = self::partnerIdForRequest($id);
        return $partnerId !== null ? self::confirmForPartner($partnerId, $id, $notes) : null;
    }

    /**
     * Admin-only equivalent of cancelForPartner() (see confirmForAdmin()).
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found.
     */
    public static function cancelForAdmin(int $id): ?array
    {
        $partnerId = self::partnerIdForRequest($id);
        return $partnerId !== null ? self::cancelForPartner($partnerId, $id) : null;
    }

    /**
     * Admin-only equivalent of reopenForPartner() (see confirmForAdmin()).
     *
     * @return array<string, mixed>|null The reservation_requests row, or null if not found.
     */
    public static function reopenForAdmin(int $id): ?array
    {
        $partnerId = self::partnerIdForRequest($id);
        return $partnerId !== null ? self::reopenForPartner($partnerId, $id) : null;
    }

    /**
     * Admin-only: permanently deletes a reservation request (and its
     * confirmed reservation row, cascaded via reservations.request_id's
     * ON DELETE CASCADE FK) instead of merely cancelling it. Used by the
     * "Effacer" action on /admin/reservations, distinct from cancellation
     * which keeps the record but marks it as cancelled.
     */
    public static function deleteRequest(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM reservation_requests WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Admin-only batch variant of deleteRequest(), used by the "Effacer la
     * sélection" bulk action on /admin/reservations.
     *
     * @param int[] $ids
     * @return int Number of rows actually deleted.
     */
    public static function deleteRequests(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("DELETE FROM reservation_requests WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public static function listForPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes
             FROM reservation_requests rr
             LEFT JOIN reservations r ON r.request_id = rr.id
             WHERE rr.partner_id = ?
             ORDER BY rr.created_at DESC'
        );
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['guests'] = self::decodeGuests($row['guests'] ?? null);
        }
        return $rows;
    }

    /**
     * Admin-only: lists reservation requests across every partner, with
     * optional filtering by partner and/or status. Used by the admin
     * "Réservations" page so an admin can review demand across the whole
     * platform instead of one partner at a time.
     *
     * @param array{partner_id?: int, status?: string} $filters
     */
    public static function listAll(array $filters = []): array
    {
        $conditions = [];
        $params = [];
        if (!empty($filters['partner_id'])) {
            $conditions[] = 'rr.partner_id = ?';
            $params[] = (int) $filters['partner_id'];
        }
        if (!empty($filters['status'])) {
            $conditions[] = 'rr.status = ?';
            $params[] = (string) $filters['status'];
        }
        $where = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $stmt = Database::connection()->prepare(
            "SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes, p.name AS partner_name
             FROM reservation_requests rr
             LEFT JOIN reservations r ON r.request_id = rr.id
             LEFT JOIN partners p ON p.id = rr.partner_id
             {$where}
             ORDER BY rr.created_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['guests'] = self::decodeGuests($row['guests'] ?? null);
        }
        return $rows;
    }

    public static function findForPartner(int $partnerId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes
             FROM reservation_requests rr
             LEFT JOIN reservations r ON r.request_id = rr.id
             WHERE rr.id = ? AND rr.partner_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $row['guests'] = self::decodeGuests($row['guests'] ?? null);
        }
        return $row;
    }

    private static function sendRequestEmails(array $partner, array $input, int $itemCount = 1): void
    {
        $photo = self::propertyPhotoTag(
            (int) ($input['property_id'] ?? 0),
            (string) ($input['property_name'] ?? '')
        );
        $checkin = (string) ($input['checkin_date'] ?? '');
        $checkout = (string) ($input['checkout_date'] ?? '');
        $childBreakdown = self::childBreakdownValues($input);
        $guestLanguage = in_array((string) ($input['language'] ?? ''), I18n::SUPPORTED, true)
            ? (string) $input['language']
            : I18n::DEFAULT_LANGUAGE;
        $variables = [
            'nom_client' => (string) ($input['client_name'] ?? ''),
            'email_client' => (string) ($input['client_email'] ?? ''),
            'telephone_client' => (string) ($input['client_phone'] ?? ''),
            'adultes' => (string) ($input['adults'] ?? 0),
            'hebergement' => (string) ($input['property_name'] ?? ''),
            'message' => (string) ($input['message'] ?? ''),
            'partenaire' => (string) ($partner['name'] ?? ''),
            'nationalites' => self::guestNationalitiesText(is_array($input['guests'] ?? null) ? $input['guests'] : []),
            'photo_bien' => $photo['html'],
            'photo_bien_url' => self::propertyPhotoUrlValue((int) ($input['property_id'] ?? 0), 1),
            'photo1' => self::propertyPhotoVariable((int) ($input['property_id'] ?? 0), (string) ($input['property_name'] ?? ''), 1),
            'photo2' => self::propertyPhotoVariable((int) ($input['property_id'] ?? 0), (string) ($input['property_name'] ?? ''), 2),
            'photo3' => self::propertyPhotoVariable((int) ($input['property_id'] ?? 0), (string) ($input['property_name'] ?? ''), 3),
            'photo1_url' => self::propertyPhotoUrlValue((int) ($input['property_id'] ?? 0), 1),
            'photo2_url' => self::propertyPhotoUrlValue((int) ($input['property_id'] ?? 0), 2),
            'photo3_url' => self::propertyPhotoUrlValue((int) ($input['property_id'] ?? 0), 3),
            'email_partenaire' => (string) ($partner['email'] ?? ''),
            'logo_partenaire' => self::partnerLogoVariable(
                (string) ($partner['logo_url'] ?? ''),
                (string) ($partner['name'] ?? '')
            ),
            'logo_partenaire_url' => self::partnerLogoUrlValue((string) ($partner['logo_url'] ?? '')),
            'politique_reservation' => PageController::formatBookingPolicyHtml(PageController::bookingPolicyText()),
            'bouton_reservation' => self::bookingLinkButtonHtml(
                (int) ($input['property_id'] ?? 0),
                $checkin,
                $checkout,
                (int) ($input['adults'] ?? 0),
                $childBreakdown['from3to12']
            ),
            'bouton_verifier_disponibilites' => self::availabilityCheckButtonHtml(
                (int) ($input['property_id'] ?? 0),
                $checkin,
                $checkout,
                (int) ($input['adults'] ?? 0),
                $childBreakdown['from3to12']
            ),
            'useful_info' => self::usefulInfoButtonHtml((int) ($input['property_id'] ?? 0), $guestLanguage),
        ];
        $variables += self::stayVariables($checkin, $checkout, $childBreakdown['under3'], $childBreakdown['from3to12'], (int) ($input['adults'] ?? 0));
        $variables += self::requestQuoteVariables($input, $itemCount, (float) ($partner['markup_percent'] ?? 0));
        $signature = self::signatureVariables((int) ($partner['id'] ?? 0));
        $variables += $signature['variables'];
        $embeds = $photo['embed'] !== null ? [$photo['embed']] : [];
        if ($signature['embed'] !== null) {
            $embeds[] = $signature['embed'];
        }

        $pdo = Database::connection();

        // Each recipient is sent in its own try/catch: previously a failure
        // sending to the partner (bad SMTP credentials, unreachable host,
        // invalid partner email, ...) threw an exception that aborted this
        // whole method, silently skipping the client email below too. Now a
        // partner-side failure can never prevent the client from being
        // notified (and vice versa).
        // The partner/host-facing copy always stays in French: the visitor's
        // site language reflects the *guest's* language, not the partner's,
        // so {{useful_info}} is rebuilt in French for this copy too.
        $partnerTemplate = self::findEmailTemplate($pdo, (int) $partner['id'], 'REQUEST_RECEIVED_PARTNER', I18n::DEFAULT_LANGUAGE);
        $partnerVariables = $variables;
        if ($guestLanguage !== I18n::DEFAULT_LANGUAGE) {
            $partnerVariables['useful_info'] = self::usefulInfoButtonHtml((int) ($input['property_id'] ?? 0), I18n::DEFAULT_LANGUAGE);
        }
        // Reply-To the client's own address on the partner-facing copy, so a
        // partner hitting "Reply" in their mailbox writes straight back to
        // the guest instead of to the shared sending mailbox.
        $clientReplyTo = (string) ($input['client_email'] ?? '');
        try {
            if ($partnerTemplate) {
                Mailer::sendTemplatedEmail($partner, $partnerTemplate, (string) $partner['email'], $partnerVariables, $embeds, $clientReplyTo);
            } else {
                Mailer::sendRawEmail($partner, (string) $partner['email'], 'Nouvelle demande de réservation - ' . $variables['nom_client'], '<p>Nouvelle demande de ' . htmlspecialchars($variables['nom_client']) . ' (' . htmlspecialchars($variables['email_client']) . ') pour ' . htmlspecialchars($variables['hebergement'] !== '' ? $variables['hebergement'] : 'hébergement non spécifié') . ' du ' . htmlspecialchars($variables['date_arrivee']) . ' au ' . htmlspecialchars($variables['date_depart']) . '.</p>' . $variables['tarif_bloc'], [], $clientReplyTo);
            }
        } catch (Throwable $e) {
            error_log('Failed to send REQUEST_RECEIVED_PARTNER email to partner #' . (int) ($partner['id'] ?? 0) . ' (' . (string) ($partner['email'] ?? '') . '): ' . $e);
        }

        // The guest-facing copy is sent in whatever language they browsed the
        // site in (I18n::current() at submission time), falling back to the
        // partner's French template if no translated variant exists yet.
        $clientTemplate = self::findEmailTemplate($pdo, (int) $partner['id'], 'REQUEST_RECEIVED_CLIENT', $guestLanguage);
        // Partner-only variables (commission, amount owed to SamChloLaure)
        // must never reach the client, even if a partner mistakenly inserted
        // one into their client-facing template — see redactPartnerOnlyVariables().
        $clientVariables = self::redactPartnerOnlyVariables($variables);
        // Reply-To the partner's own address on the client-facing copy, so a
        // guest hitting "Reply" writes straight back to the partner instead
        // of to the shared sending mailbox.
        $partnerReplyTo = (string) ($partner['email'] ?? '');
        try {
            if ($clientTemplate) {
                Mailer::sendTemplatedEmail($partner, $clientTemplate, (string) $input['client_email'], $clientVariables, $embeds, $partnerReplyTo);
            } else {
                Mailer::sendRawEmail($partner, (string) $input['client_email'], 'Confirmation de votre demande - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $input['client_name']) . ',</p><p>Nous avons bien reçu votre demande de réservation pour ' . htmlspecialchars((string) ($input['property_name'] ?? 'l\'hébergement')) . ' du ' . htmlspecialchars((string) $input['checkin_date']) . ' au ' . htmlspecialchars((string) $input['checkout_date']) . '.</p>' . $variables['tarif_bloc'] . '<p>Nous vous contacterons très prochainement.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>', [], $partnerReplyTo);
            }
        } catch (Throwable $e) {
            error_log('Failed to send REQUEST_RECEIVED_CLIENT email to ' . (string) ($input['client_email'] ?? '') . ': ' . $e);
        }
    }

    /**
     * Strips partner-only/confidential variables (commission_partenaire,
     * paiement_a_samchlolaure — see View::emailTemplateVariableCatalog())
     * from a variable set before it is rendered into a client-facing email.
     * This is a defense-in-depth safety net: even if a partner's
     * client-facing template mistakenly references one of these variables
     * (they are documented but not meant to be used there), the actual
     * commission/payout figures must never leak to the client.
     */
    public static function redactPartnerOnlyVariables(array $variables): array
    {
        foreach (View::emailTemplateVariableCatalog() as $definition) {
            if (!empty($definition['partnerOnly']) && array_key_exists($definition['key'], $variables)) {
                $variables[$definition['key']] = '';
            }
        }
        return $variables;
    }

    /**
     * Fetches the email_templates row for the given partner/type/language,
     * falling back (in order) to: the partner's French template (the
     * always-present default language) when no translated variant exists
     * for $language yet, then to the admin-managed "default" template (see
     * findDefaultEmailTemplate()) if the partner has no template at all for
     * this type — so a partner who never customized a given template type
     * still has guest-facing emails sent using the site-wide default rather
     * than falling back to the bare-bones hardcoded HTML.
     */
    public static function findEmailTemplate(PDO $pdo, int $partnerId, string $type, string $language): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? AND language = ? LIMIT 1');
        $stmt->execute([$partnerId, $type, $language]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($template !== null) {
            return $template;
        }
        if ($language !== I18n::DEFAULT_LANGUAGE) {
            $stmt->execute([$partnerId, $type, I18n::DEFAULT_LANGUAGE]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($template !== null) {
                return $template;
            }
        }
        return self::findDefaultEmailTemplate($pdo, $type, $language);
    }

    /**
     * Fetches the admin-managed default_email_templates row for $type,
     * used by findEmailTemplate() whenever a partner has no template of
     * their own for that type (in any language) — falling back to the
     * default's French variant when no translated default exists either.
     * Returns null when neither exists (e.g. the admin never created a
     * default template for this type), leaving the caller's own
     * hardcoded-HTML fallback as the last resort. Also self-heals the
     * default_email_templates table if a request race the fresh-deploy
     * migration throttle (see PageController::ensureDefaultEmailTemplatesTable()).
     */
    private static function findDefaultEmailTemplate(PDO $pdo, string $type, string $language): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM default_email_templates WHERE type = ? AND language = ? LIMIT 1');
            $stmt->execute([$type, $language]);
        } catch (\PDOException $e) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS default_email_templates (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  type ENUM(
                    'REQUEST_RECEIVED_PARTNER',
                    'REQUEST_RECEIVED_CLIENT',
                    'RESERVATION_CONFIRMED',
                    'RESERVATION_CANCELLED',
                    'REMINDER',
                    'REMINDER_CLIENT',
                    'REMINDER_PARTNER'
                  ) NOT NULL,
                  language VARCHAR(5) NOT NULL DEFAULT 'fr',
                  subject VARCHAR(500) NOT NULL,
                  body_html MEDIUMTEXT NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY unique_type_lang (type, language)
                )"
            );
            $stmt = $pdo->prepare('SELECT * FROM default_email_templates WHERE type = ? AND language = ? LIMIT 1');
            $stmt->execute([$type, $language]);
        }
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($template !== null || $language === I18n::DEFAULT_LANGUAGE) {
            return $template;
        }
        $stmt->execute([$type, I18n::DEFAULT_LANGUAGE]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function sendReservationStatusEmail(array $partner, array $request, string $type, ?string $notes): void
    {
        $guestLanguage = in_array((string) ($request['language'] ?? ''), I18n::SUPPORTED, true)
            ? (string) $request['language']
            : I18n::DEFAULT_LANGUAGE;
        $template = self::findEmailTemplate(Database::connection(), (int) $partner['id'], $type, $guestLanguage);
        $photo = self::propertyPhotoTag(
            (int) ($request['property_id'] ?? 0),
            (string) $request['property_name']
        );
        $childBreakdown = self::childBreakdownValues($request);
        $variables = [
            'nom_client' => (string) $request['client_name'],
            'email_client' => (string) $request['client_email'],
            'telephone_client' => (string) ($request['client_phone'] ?? ''),
            'adultes' => (string) $request['adults'],
            'hebergement' => (string) $request['property_name'],
            'notes' => $notes ?? '',
            'partenaire' => (string) $partner['name'],
            'nationalites' => self::guestNationalitiesText(self::decodeGuests($request['guests'] ?? null)),
            'photo_bien' => $photo['html'],
            'photo_bien_url' => self::propertyPhotoUrlValue((int) ($request['property_id'] ?? 0), 1),
            'photo1' => self::propertyPhotoVariable((int) ($request['property_id'] ?? 0), (string) $request['property_name'], 1),
            'photo2' => self::propertyPhotoVariable((int) ($request['property_id'] ?? 0), (string) $request['property_name'], 2),
            'photo3' => self::propertyPhotoVariable((int) ($request['property_id'] ?? 0), (string) $request['property_name'], 3),
            'photo1_url' => self::propertyPhotoUrlValue((int) ($request['property_id'] ?? 0), 1),
            'photo2_url' => self::propertyPhotoUrlValue((int) ($request['property_id'] ?? 0), 2),
            'photo3_url' => self::propertyPhotoUrlValue((int) ($request['property_id'] ?? 0), 3),
            'email_partenaire' => (string) ($partner['email'] ?? ''),
            'logo_partenaire' => self::partnerLogoVariable(
                (string) ($partner['logo_url'] ?? ''),
                (string) ($partner['name'] ?? '')
            ),
            'logo_partenaire_url' => self::partnerLogoUrlValue((string) ($partner['logo_url'] ?? '')),
            'politique_reservation' => PageController::formatBookingPolicyHtml(PageController::bookingPolicyText()),
            'bouton_reservation' => self::bookingLinkButtonHtml(
                (int) ($request['property_id'] ?? 0),
                (string) $request['checkin_date'],
                (string) $request['checkout_date'],
                (int) $request['adults'],
                $childBreakdown['from3to12']
            ),
            'bouton_verifier_disponibilites' => self::availabilityCheckButtonHtml(
                (int) ($request['property_id'] ?? 0),
                (string) $request['checkin_date'],
                (string) $request['checkout_date'],
                (int) $request['adults'],
                $childBreakdown['from3to12']
            ),
            'useful_info' => self::usefulInfoButtonHtml((int) ($request['property_id'] ?? 0), $guestLanguage),
        ];
        $variables += self::stayVariables(
            (string) $request['checkin_date'],
            (string) $request['checkout_date'],
            $childBreakdown['under3'],
            $childBreakdown['from3to12'],
            (int) $request['adults']
        );
        // The quote breakdown persisted on the request row at submission time
        // (quote_room_total, quote_partner_rate, ...) lets confirmation/
        // cancellation emails reuse the exact same {{tarif_*}}/{{total_voyageur}}
        // variables as the initial request email, without a live (and
        // possibly since-changed) Lodgify rate re-fetch.
        if (($request['quote_room_total'] ?? null) !== null) {
            $variables += self::buildQuoteVariables(self::computeQuoteBreakdown([
                'room_total' => $request['quote_room_total'] ?? 0,
                'extra_person_total' => $request['quote_extra_person_total'] ?? 0,
                'cleaning_total' => $request['quote_cleaning_total'] ?? 0,
                'tourist_tax_total' => $request['quote_tourist_tax_total'] ?? 0,
                'nights' => $request['quote_nights'] ?? 0,
                'currency' => $request['quote_currency'] ?? 'EUR',
            ], (float) ($request['quote_partner_rate'] ?? ($partner['markup_percent'] ?? 0)), (float) ($request['quote_vat_rate'] ?? 0)));
        }
        $signature = self::signatureVariables((int) ($partner['id'] ?? 0));
        $variables += $signature['variables'];
        $embeds = $photo['embed'] !== null ? [$photo['embed']] : [];
        if ($signature['embed'] !== null) {
            $embeds[] = $signature['embed'];
        }

        // This method only ever emails the client (confirmation/cancellation/
        // reminder), so partner-only variables (commission, amount owed to
        // SamChloLaure) are always stripped before rendering.
        $variables = self::redactPartnerOnlyVariables($variables);

        // Reply-To the partner's own address, so a guest hitting "Reply"
        // writes straight back to the partner instead of to the shared
        // sending mailbox.
        $partnerReplyTo = (string) ($partner['email'] ?? '');

        if ($template) {
            Mailer::sendTemplatedEmail($partner, $template, (string) $request['client_email'], $variables, $embeds, $partnerReplyTo);
            return;
        }

        if ($type === 'RESERVATION_CONFIRMED') {
            Mailer::sendRawEmail($partner, (string) $request['client_email'], 'Votre réservation est confirmée - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $request['client_name']) . ',</p><p>Votre réservation pour ' . htmlspecialchars((string) $request['property_name']) . ' du ' . htmlspecialchars((string) $request['checkin_date']) . ' au ' . htmlspecialchars((string) $request['checkout_date']) . ' est confirmée.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>', [], $partnerReplyTo);
        }
    }

    /**
     * Builds the stay-related email variables shared by every reservation
     * email: {{dates}}, {{date_arrivee}}, {{date_depart}} (formatted as
     * "ddd dd mmm yyyy", e.g. "mer. 12 août 2026"), {{nuits}} (number of
     * nights) and {{enfants}}/{{bebes}} (3-12 years / under 3 years),
     * always defaulting numeric values to 0 instead of leaving the
     * placeholder unresolved when a field is empty.
     */
    public static function stayVariables(string $checkin, string $checkout, int $childrenUnder3, int $children3to12, int $adults = 0): array
    {
        $adults = max(0, $adults);
        $childrenUnder3 = max(0, $childrenUnder3);
        $children3to12 = max(0, $children3to12);
        $formattedCheckin = self::formatDateFr($checkin);
        $formattedCheckout = self::formatDateFr($checkout);
        $nights = self::nightsBetween($checkin, $checkout);

        return [
            'dates' => 'Du ' . $formattedCheckin . ' -> ' . $formattedCheckout,
            'date_arrivee' => $formattedCheckin,
            'date_depart' => $formattedCheckout,
            'nuits' => (string) $nights,
            'enfants' => (string) $children3to12,
            'bebes' => (string) $childrenUnder3,
            'total_personnes' => (string) ($adults + $children3to12 + $childrenUnder3),
        ];
    }

    /**
     * Formats an ISO ("Y-m-d") date as "ddd dd mmm yyyy" in French (e.g.
     * "mer. 12 août 2026"), so reservation emails never show the raw
     * database date format. Falls back to the original (unformatted) value
     * when it isn't a valid date, rather than throwing.
     */
    public static function formatDateFr(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($isoDate);
        } catch (Throwable $e) {
            return $isoDate;
        }

        $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        $months = [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
            7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ];

        $dayName = $days[(int) $date->format('w')];
        $monthName = $months[(int) $date->format('n')];

        return $dayName . ' ' . $date->format('d') . ' ' . $monthName . ' ' . $date->format('Y');
    }

    /**
     * Number of nights between two ISO dates, defaulting to 0 (rather than a
     * negative number or an exception) whenever the dates are missing or
     * invalid, so {{nuits}} always shows a sane value in emails.
     */
    private static function nightsBetween(string $checkin, string $checkout): int
    {
        if ($checkin === '' || $checkout === '') {
            return 0;
        }
        try {
            $checkinDate = new \DateTimeImmutable($checkin);
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (Throwable $e) {
            return 0;
        }

        $nights = (int) $checkinDate->diff($checkoutDate)->days;
        return max(0, $nights);
    }

    /**
     * Builds {{tarif_*}} variables from the quote values posted by the booking
     * form so request emails include the same itemized amount as shown before
     * submission (Tarif, Personnes supplémentaires, Nettoyage, Total, and the
     * optional tourist-tax note).
     *
     * $itemCount is the number of properties combined in the same multi-
     * property submission (the "Calendrier" cart lets a visitor request
     * several properties at once, which sends one separate confirmation
     * email per property). When >1, {{tarif_bloc}} gets an extra note
     * clarifying that the shown amount only covers this one property (the
     * other properties' prices are in their own emails), and a new
     * {{multi_biens_note}} variable is populated so partner templates can
     * mention it near "Vos Voyageurs" (e.g. "Pour les 2 biens sélectionnés").
     */
    /**
     * @param array{room_total: float, extra_person_total: float, cleaning_total: float, tourist_tax_total: float, nights: int, currency: string, vat_rate?: float} $quote
     * @return array{room_total: float, partner_rate: float, vat_rate: float, commission_total: float, extra_person_total: float, cleaning_total: float, tourist_tax_total: float, total_traveler: float, vat_total: float, nights: int, currency: string}
     */
    public static function computeQuoteBreakdown(array $quote, float $markupPercent, float $vatRate = 0.0): array
    {
        $roomTotal = self::toMoneyValue($quote['room_total'] ?? 0);
        $extraPersonTotal = self::toMoneyValue($quote['extra_person_total'] ?? 0);
        $cleaningTotal = self::toMoneyValue($quote['cleaning_total'] ?? 0);
        $touristTaxTotal = self::toMoneyValue($quote['tourist_tax_total'] ?? 0);
        // $roomTotal here is the same already-marked-up per-night price shown
        // to the guest on the property page (PageController::publicRates()
        // bakes the partner's markup_percent into it before this method ever
        // sees it), i.e. it's already what the traveler is billed for the
        // accommodation itself. "Commissions Partenaire" is only an
        // informational estimate of the partner's margin already embedded in
        // that price (never a surcharge added on top of it), so it must NOT
        // be added again into the traveler-facing total below — doing so
        // used to double-apply the markup and made emailed totals higher
        // than the price actually shown on the site.
        // Commission is calculated on {{tarif_client}} + {{tarif_personnes_
        // supplementaires}} (room + extra-person fees) only, never on the
        // cleaning fee. $roomTotal/$extraPersonTotal are already-marked-up
        // amounts (base Lodgify rate + commission — see the note above), so
        // the commission itself — "(Tarif Lodgify avant commission +
        // personne(s) supplémentaire(s) avant commission) x taux de
        // commission" — must be extracted back out of the marked-up total
        // rather than applied on top of it a second time: if
        // markedUp = base * (1 + rate/100), then
        // commission = base * rate/100 = markedUp * rate/(100 + rate).
        // Dividing by 100 instead of (100 + rate) overstated the commission
        // by also taxing the commission portion of the marked-up price.
        // For VAT-registered properties, $roomTotal/$extraPersonTotal also
        // have the property's vat_rate baked in on top of the markup (see
        // PageController::publicRates()/ReservationsController::
        // computeItemQuote()). The channel manager (Lodgify) pays out the
        // partner's commission on the full VAT-inclusive room + extra-person
        // total — it does NOT strip VAT out first — so the commission here
        // must be extracted from $combinedTotal directly (VAT included), not
        // from a VAT-stripped base. Previously stripping VAT first
        // understated the commission (e.g. 16,00€ instead of 18,40€ for a
        // 202,42€ combined total at a 10% commission rate and 15% VAT),
        // which made the site's "Montant à payer à Sam Chlo Laure Ltd."
        // total (D - E) higher than the amount actually shown on the
        // channel manager.
        $combinedTotal = $roomTotal + $extraPersonTotal;
        $commissionTotal = $markupPercent > -100
            ? round($combinedTotal * $markupPercent / (100 + $markupPercent), 2)
            : 0.0;
        // Amount of VAT actually charged on the room + extra-person total
        // (0 for properties not registered for VAT, i.e. vat_rate 0/null):
        // the difference between the VAT-inclusive amount ($combinedTotal)
        // and the VAT-exclusive amount ($combinedBeforeVat). Exposed as
        // {{tva_totale}} so a partner can show it separately without having
        // to reverse-engineer it from {{tarif_ht}}/{{tarif_ttc}}. This is
        // purely informational and is not used for the commission
        // calculation above.
        $combinedBeforeVat = $vatRate > -100 && $vatRate != 0.0
            ? round($combinedTotal / (1 + $vatRate / 100), 2)
            : $combinedTotal;
        $vatTotal = round($combinedTotal - $combinedBeforeVat, 2);
        // {{total_voyageur}}/{{paiement_a_samchlolaure}} must NOT include the
        // tourist tax: the channel manager doesn't handle it correctly, so
        // it's excluded here (same as {{tarif_total}}/tarif_bloc's "Total"
        // line — see buildQuoteVariables()).
        $totalTraveler = round($roomTotal + $extraPersonTotal + $cleaningTotal, 2);

        return [
            'room_total' => $roomTotal,
            'partner_rate' => round($markupPercent, 2),
            'vat_rate' => round($vatRate, 2),
            'commission_total' => $commissionTotal,
            'extra_person_total' => $extraPersonTotal,
            'cleaning_total' => $cleaningTotal,
            'tourist_tax_total' => $touristTaxTotal,
            'total_traveler' => $totalTraveler,
            'vat_total' => $vatTotal,
            'nights' => max(0, (int) ($quote['nights'] ?? 0)),
            'currency' => trim((string) ($quote['currency'] ?? 'EUR')) ?: 'EUR',
        ];
    }

    private static function requestQuoteVariables(array $input, int $itemCount = 1, float $markupPercent = 0.0): array
    {
        $breakdown = self::computeQuoteBreakdown([
            'room_total' => $input['quote_room_total'] ?? 0,
            'extra_person_total' => $input['quote_extra_person_total'] ?? 0,
            'cleaning_total' => $input['quote_cleaning_total'] ?? 0,
            'tourist_tax_total' => $input['quote_tourist_tax_total'] ?? 0,
            'nights' => $input['quote_nights'] ?? 0,
            'currency' => $input['quote_currency'] ?? 'EUR',
        ], $markupPercent, (float) ($input['quote_vat_rate'] ?? 0));

        return self::buildQuoteVariables($breakdown, $itemCount);
    }

    /**
     * Builds the {{tarif_*}}/{{tarif_bloc}} plus the individually insertable
     * {{tarif_normal}}/{{commission_partenaire}}/{{personnes_additionnelles}}/
     * {{nettoyage}}/{{total_voyageur}} email variables from an already
     * computed price breakdown. Shared by requestQuoteVariables() (live quote
     * at submission time) and sendReservationStatusEmail() (the breakdown
     * persisted on the reservation_requests row), so both sources of
     * variables stay perfectly consistent.
     *
     * @param array{room_total: float, partner_rate: float, commission_total: float, extra_person_total: float, cleaning_total: float, tourist_tax_total: float, total_traveler: float, vat_total: float, nights: int, currency: string} $breakdown
     */
    public static function buildQuoteVariables(array $breakdown, int $itemCount = 1): array
    {
        $currency = $breakdown['currency'];
        $roomTotal = $breakdown['room_total'];
        $extraPersonTotal = $breakdown['extra_person_total'];
        $cleaningTotal = $breakdown['cleaning_total'];
        $touristTaxTotal = $breakdown['tourist_tax_total'];
        $nights = $breakdown['nights'];
        // $roomTotal is already the marked-up price shown to the guest on
        // the property page (see computeQuoteBreakdown()), so it must not be
        // added to the partner's commission again here — doing so used to
        // make the emailed total higher than the price shown on the site for
        // the exact same stay. Tarif Normal + Ménage + Personnes
        // Additionnelles, never the tourist tax.
        $totalWithoutTax = round($roomTotal + $extraPersonTotal + $cleaningTotal, 2);
        $itemCount = max(1, $itemCount);

        $tarifBloc = '<div style="padding:12px 24px 16px;">'
            . '<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Résumé Tarifaire :</p>';
        if ($itemCount > 1) {
            $otherBiensText = $itemCount === 2 ? "l'autre email pour le tarif de l'autre bien" : 'les autres emails pour le tarif des autres biens';
            $tarifBloc .= '<p style="margin:0 0 10px;font-size:13px;color:#6b7280;">(Tarif uniquement pour ce bien. Voir ' . $otherBiensText . '.)</p>';
        }
        $tarifBloc .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
            . '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Tarif</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . self::formatMoneyFr($roomTotal, $currency) . '</td></tr>';
        if ($extraPersonTotal > 0) {
            $tarifBloc .= '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Personne(s) supplémentaire(s)</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . self::formatMoneyFr($extraPersonTotal, $currency) . '</td></tr>';
        }
        $tarifBloc .= '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Nettoyage</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">' . self::formatMoneyFr($cleaningTotal, $currency) . '</td></tr>'
            . '<tr><td style="padding:8px 0;font-weight:bold;color:#111827;">Total</td>'
            . '<td style="padding:8px 0;font-weight:bold;text-align:right;color:#111827;">' . self::formatMoneyFr($totalWithoutTax, $currency) . '</td></tr>'
            . '</table>';
        if ($touristTaxTotal > 0) {
            $tarifBloc .= '<div style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px 14px;">'
                . '<table style="width:100%;border-collapse:collapse;"><tr>'
                . '<td style="width:28px;vertical-align:top;font-size:18px;padding-right:8px;">&#9888;&#xFE0F;</td>'
                . '<td style="font-size:13px;color:#92400e;vertical-align:top;">'
                . '<strong>Attention</strong><br>Taxe touristique de '
                . number_format($touristTaxTotal, 2, ',', ' ')
                . ' Euros à régler à l\'arrivée<br>(Non comprise dans le total)'
                . '</td></tr></table></div>';
        }
        $tarifBloc .= '</div>';

        return [
            'tarif_nuits' => (string) $nights,
            'tarif_hebergement' => self::formatMoneyFr($roomTotal, $currency),
            'tarif_personnes_supplementaires' => self::formatMoneyFr($extraPersonTotal, $currency),
            'tarif_nettoyage' => self::formatMoneyFr($cleaningTotal, $currency),
            'tarif_total' => self::formatMoneyFr($totalWithoutTax, $currency),
            'taxe_touristique' => self::formatMoneyFr($touristTaxTotal, 'EUR'),
            'tarif_bloc' => $tarifBloc,
            'multi_biens_note' => $itemCount > 1 ? "Pour les {$itemCount} biens sélectionnés" : '',
            // Individually insertable variables for the partner-facing quote
            // breakdown (Tarif Normal / Commissions Partenaire / Personnes
            // Additionnels / Nettoyage / Total Voyageur). Commission is never
            // referenced by tarif_bloc so it never leaks into client-facing
            // emails unless the partner explicitly inserts it themselves.
            'tarif_normal' => self::formatMoneyFr($roomTotal, $currency),
            'commission_partenaire' => self::formatMoneyFr($breakdown['commission_total'], $currency),
            // $roomTotal already includes the partner's commission (see
            // computeQuoteBreakdown()), so {{tarif_client}} is simply the
            // room total — volontairement sans nettoyage et sans taxe
            // touristique — distinct de {{total_voyageur}} qui inclut en
            // plus le ménage et les personnes supplémentaires (mais pas la
            // taxe touristique, voir computeQuoteBreakdown()).
            'tarif_client' => self::formatMoneyFr($roomTotal, $currency),
            'personnes_additionnelles' => self::formatMoneyFr($extraPersonTotal, $currency),
            'nettoyage' => self::formatMoneyFr($cleaningTotal, $currency),
            // {{total_voyageur}} deliberately excludes the tourist tax (the
            // channel manager doesn't handle it correctly), same as
            // {{tarif_total}} above.
            'total_voyageur' => self::formatMoneyFr($breakdown['total_traveler'], $currency),
            // Amount actually due to SamChloLaure once the partner's
            // commission (already included in "Total Voyageur") is deducted:
            // Total à payer par le client (hors taxe touristique) -
            // Commissions Partenaire.
            'paiement_a_samchlolaure' => self::formatMoneyFr($breakdown['total_traveler'] - $breakdown['commission_total'], $currency),
            // VAT breakdown, appended last so existing templates/positions
            // built before these variables existed are unaffected.
            // {{tva_totale}}: amount of VAT actually charged on the room +
            // extra-person total (0,00 EUR — never blank — for properties
            // not registered for VAT, see computeQuoteBreakdown()).
            'tva_totale' => self::formatMoneyFr($breakdown['vat_total'] ?? 0, $currency),
            // {{tarif_ttc}}: identical to {{total_voyageur}}/{{tarif_total}}
            // (VAT already included, tourist tax excluded) — provided under
            // an explicit "TTC" name for partners used to that terminology.
            'tarif_ttc' => self::formatMoneyFr($totalWithoutTax, $currency),
            // {{tarif_ht}}: same total with the VAT amount above removed
            // (cleaning/extra fees never carry VAT themselves, only the
            // room + extra-person portion does).
            'tarif_ht' => self::formatMoneyFr($totalWithoutTax - ($breakdown['vat_total'] ?? 0), $currency),
        ];
    }

    private static function toMoneyValue(mixed $value): float
    {
        return round((float) $value, 2);
    }

    public static function formatMoneyFr(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * Amount of VAT actually charged on a persisted reservation's room +
     * extra-person total, for display (e.g. partner-reservation-detail.php).
     * Same formula as the $vatTotal computed inline in
     * computeQuoteBreakdown(), reusable here since only the raw stored
     * quote_room_total/quote_extra_person_total/quote_vat_rate columns are
     * available at this point (not the full breakdown array). Returns 0.0
     * for properties not registered for VAT (vat_rate 0/null), never null.
     */
    public static function vatTotalFromStoredQuote(float $roomTotal, float $extraPersonTotal, float $vatRate): float
    {
        if ($vatRate <= -100 || $vatRate == 0.0) {
            return 0.0;
        }
        $combinedTotal = $roomTotal + $extraPersonTotal;
        $combinedBeforeVat = round($combinedTotal / (1 + $vatRate / 100), 2);
        return round($combinedTotal - $combinedBeforeVat, 2);
    }

    private static function fetchPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Builds the {{photo_bien}} <img> tag plus (if a local thumbnail exists)
     * the corresponding embed to attach to the outgoing message.
     *
     * @return array{html: string, embed: ?array{cid: string, data: string, mime: string}}
     */
    private static function propertyPhotoTag(int $propertyId, string $propertyName): array
    {
        $empty = ['html' => '', 'embed' => null];
        if ($propertyId <= 0) {
            return $empty;
        }

        // Never call Lodgify here: the photo is only ever the locally-synced
        // 320px thumbnail produced by the manual admin sync (see
        // LodgifyClient::getPropertyPhotoThumbnailPath()/ImageCache::cache()).
        // This keeps reservation emails fast and immune to Lodgify hiccups.
        // The thumbnail is embedded inline via Content-ID rather than
        // hotlinked, since some webmail clients refuse to load external
        // images and show a broken-image placeholder instead.
        $thumbnailPath = (new LodgifyClient())->getPropertyPhotoThumbnailPath($propertyId);
        if ($thumbnailPath === null) {
            return $empty;
        }

        $data = @file_get_contents($thumbnailPath);
        if ($data === false || $data === '') {
            return $empty;
        }

        $cid = 'property-photo-' . $propertyId . '-' . bin2hex(random_bytes(4)) . '@local';
        // width:100% previously made the 320px thumbnail stretch to fill the
        // surrounding email container in most mail clients (inline style
        // wins over the width attribute), defeating the point of a fixed
        // 320px thumbnail. Use a fixed width instead.
        $html = '<img src="cid:' . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($propertyName, ENT_QUOTES, 'UTF-8') . '" width="320" style="display:block;width:320px;max-width:100%;height:auto;">';

        return [
            'html' => $html,
            'embed' => ['cid' => $cid, 'data' => $data, 'mime' => 'image/jpeg'],
        ];
    }

    /**
     * Builds the {{signature_photo}}/{{signature_nom}}/{{lien_partenaire}}/
     * {{telephone_partenaire}} email variables from the partner's own account
     * (the "partner" role user tied to that partner_id, i.e. whoever set
     * their name/phone/photo from "Mon compte"), so partners can sign their
     * outgoing reservation emails.
     *
     * @return array{variables: array<string, string>, embed: array{cid: string, data: string, mime: string}|null}
     *         "embed" (when not null) must be merged into the $embeds array
     *         passed to Mailer::send*(), alongside the property photo embed,
     *         so the signature photo is inlined via Content-ID instead of
     *         hotlinked — many webmail clients (e.g. iCloud Mail) block
     *         external images by default, which made the signature photo
     *         show as broken until the recipient explicitly allowed it.
     */
    public static function signatureVariables(int $partnerId): array
    {
        $user = $partnerId > 0 ? self::fetchPartnerUser($partnerId) : null;

        $fullName = trim(trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? '')));
        $photoUrl = trim((string) ($user['photo_url'] ?? ''));
        $phone = trim((string) ($user['phone'] ?? ''));
        $photo = self::signaturePhotoTag($photoUrl, $fullName !== '' ? $fullName : 'Photo');

        return [
            'variables' => [
                'signature_nom' => $fullName,
                'signature_photo' => $photo['html'],
                'signature_photo_url' => self::signaturePhotoUrlValue($photoUrl),
                'lien_partenaire' => self::partnerLink($partnerId),
                'telephone_partenaire' => $phone,
            ],
            'embed' => $photo['embed'],
        ];
    }

    public static function propertyPhotoVariable(int $propertyId, string $propertyName, int $photoIndex): callable
    {
        return static fn (?int $size = null): string => self::propertyPhotoHtml($propertyId, $propertyName, $photoIndex, $size);
    }

    public static function propertyPhotoUrlValue(int $propertyId, int $photoIndex): string
    {
        $photoUrl = (new LodgifyClient())->getPropertyPhotoUrlByIndex($propertyId, $photoIndex);
        return $photoUrl !== '' ? self::absoluteUrl($photoUrl) : '';
    }

    private static function propertyPhotoHtml(int $propertyId, string $propertyName, int $photoIndex, ?int $size): string
    {
        $photoUrl = self::propertyPhotoUrlValue($propertyId, $photoIndex);
        if ($photoUrl === '') {
            return '';
        }
        $width = self::normalizeImageWidth($size, 320);
        return '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($propertyName, ENT_QUOTES, 'UTF-8') . '" width="' . $width . '" style="display:block;width:' . $width . 'px;max-width:100%;height:auto;">';
    }

    public static function partnerLogoUrlValue(string $logoUrl): string
    {
        return $logoUrl !== '' ? self::absoluteUrl($logoUrl) : '';
    }

    public static function signaturePhotoUrlValue(string $photoUrl): string
    {
        return $photoUrl !== '' ? self::absoluteUrl($photoUrl) : '';
    }

    private static function normalizeImageWidth(?int $size, int $default): int
    {
        $width = $size ?? $default;
        return max(24, min(1200, $width));
    }

    private static function fetchPartnerUser(int $partnerId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM users WHERE partner_id = ? AND role = 'partner' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$partnerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array{html: string, embed: array{cid: string, data: string, mime: string}|null}
     */
    private static function signaturePhotoTag(string $photoUrl, string $alt): array
    {
        $empty = ['html' => '', 'embed' => null];
        if ($photoUrl === '') {
            return $empty;
        }

        // Locally-uploaded photos (see AccountController::storeUploadedPhoto(),
        // stored under images/others/avatars/...) are read straight off disk
        // and embedded via Content-ID, same as the property thumbnail. Only
        // fall back to hotlinking for an already-absolute external URL that
        // doesn't resolve to a local file.
        $localPath = BASE_PATH . '/' . ltrim($photoUrl, '/');
        $data = !preg_match('#^https?://#i', $photoUrl) && is_file($localPath) ? @file_get_contents($localPath) : false;

        if ($data !== false && $data !== '') {
            $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
            $cid = 'signature-photo-' . bin2hex(random_bytes(4)) . '@local';
            return [
                'html' => '<img src="cid:' . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="64" height="64" style="display:inline-block;width:64px;height:64px;max-width:100%;border-radius:50%;object-fit:cover;">',
                'embed' => ['cid' => $cid, 'data' => $data, 'mime' => $mime],
            ];
        }

        // Fall back to the resizable hotlink renderer shared with
        // signaturePhotoVariable() (absolutizes the URL and applies the
        // {{signature_photo:NN}} size suffix) for genuinely external URLs,
        // or when the local file couldn't be read — this photo just won't
        // be CID-embedded/inlined in that case.
        $html = self::signaturePhotoHtml($photoUrl, $alt, null);
        return $html !== '' ? ['html' => $html, 'embed' => null] : $empty;
    }

    private static function signaturePhotoVariable(string $photoUrl, string $alt): callable
    {
        return static fn (?int $size = null): string => self::signaturePhotoHtml($photoUrl, $alt, $size);
    }

    private static function signaturePhotoHtml(string $photoUrl, string $alt, ?int $size): string
    {
        if ($photoUrl === '') {
            return '';
        }

        $photoUrl = self::absoluteUrl($photoUrl);
        if ($photoUrl === '') {
            return '';
        }

        $width = self::normalizeImageWidth($size, 64);
        return '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="' . $width . '" height="' . $width . '" style="display:inline-block;width:' . $width . 'px;height:' . $width . 'px;max-width:100%;border-radius:50%;object-fit:cover;">';
    }

    private static function partnerLogoTag(string $logoUrl, string $alt): string
    {
        return self::partnerLogoHtml($logoUrl, $alt, null);
    }

    public static function partnerLogoVariable(string $logoUrl, string $alt): callable
    {
        return static fn (?int $size = null): string => self::partnerLogoHtml($logoUrl, $alt, $size);
    }

    private static function partnerLogoHtml(string $logoUrl, string $alt, ?int $size): string
    {
        if ($logoUrl === '') {
            return '';
        }
        $logoUrl = self::absoluteUrl($logoUrl);
        if ($logoUrl === '') {
            return '';
        }
        $width = self::normalizeImageWidth($size, 80);
        return '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="' . $width . '" style="display:block;margin:0 auto;width:' . $width . 'px;max-width:100%;height:auto;">';
    }

    /**
     * Builds the {{bouton_reservation}} email variable: a ready-made HTML
     * button that first has our own site re-check this property's live
     * Lodgify availability for the requested dates/party size at the exact
     * moment it is clicked (an email can go unopened for days, so the
     * availability at send-time can no longer be trusted), then either:
     * - redirects straight to the Lodgify checkout page
     *   (checkout.lodgify.com/.../reservation) to confirm the booking, if
     *   still available, or
     * - sends the visitor back to the property page on our own site with an
     *   "indisponible" notice, if not.
     * See PageController::bookingRedirect() (route
     * "/properties/{id}/reservation-directe") for that live check. Insertable
     * anywhere in a template body — unlike {{tarif_bloc}}, it is not
     * referenced by any other variable, so partners are free to place it
     * wherever they want (e.g. right after the stay summary, or in the
     * signature block) or to omit it entirely.
     */
    public static function bookingLinkButtonHtml(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12
    ): string {
        $url = self::bookingRedirectUrl($propertyId, $checkin, $checkout, $adults, $children3to12);
        if ($url === '') {
            return '';
        }

        return '<div style="text-align:center;margin:20px 0;">'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;background:#3b82f6;color:#ffffff;text-decoration:none;'
            . 'font-weight:bold;font-size:14px;padding:12px 28px;border-radius:6px;">Réserver maintenant</a>'
            . '</div>';
    }

    /**
     * Builds the {{useful_info}} email button: a styled button (same look
     * as {{bouton_reservation}}) linking to the property's own check-in
     * info URL (checkin_info_url_fr/checkin_info_url_en manual columns on
     * "lodgify_property_manual_columns", configured per-property in the
     * admin "Biens Lodgify" table), picking the URL matching the email's
     * own language — English when $language is "en", French otherwise —
     * so an English-language email always links to the English page even
     * if only the French page is filled in for a French guest, and vice
     * versa. Falls back to the other language's URL when the one for
     * $language is empty, and returns '' (button omitted entirely) when
     * neither is configured for this property.
     */
    public static function usefulInfoButtonHtml(int $propertyId, string $language): string
    {
        $manual = PageController::manualLodgifyColumnsByPropertyId([$propertyId])[$propertyId] ?? null;
        $urlFr = trim((string) ($manual['checkin_info_url_fr'] ?? ''));
        $urlEn = trim((string) ($manual['checkin_info_url_en'] ?? ''));
        $isEnglish = $language === 'en';
        $url = $isEnglish ? ($urlEn !== '' ? $urlEn : $urlFr) : ($urlFr !== '' ? $urlFr : $urlEn);
        if ($url === '') {
            return '';
        }
        $label = $isEnglish ? 'Useful check-in informations' : 'Renseignements utiles à l\'enregistrement';
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<div style="text-align:center;margin:20px 0;">'
            . '<a href="' . $safeUrl . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;background:#3b82f6;color:#ffffff;text-decoration:none;'
            . 'font-weight:bold;font-size:14px;padding:12px 28px;border-radius:6px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>'
            . '</div>';
    }

    /**
     * Builds the {{bouton_verifier_disponibilites}} email variable: a second
     * button, next to {{bouton_reservation}}, that sends the visitor to a
     * confirmation page on our own site (PageController::availabilityCheck(),
     * route "/properties/{id}/verifier-disponibilites") which re-checks this
     * property's live Lodgify availability for these dates/party size and
     * displays the result, with its own "Réserver" button linking to the
     * Lodgify checkout page once availability is confirmed.
     */
    public static function availabilityCheckButtonHtml(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12
    ): string {
        $url = self::availabilityCheckUrl($propertyId, $checkin, $checkout, $adults, $children3to12);
        if ($url === '') {
            return '';
        }

        return '<div style="text-align:center;margin:20px 0;">'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;background:#ffffff;color:#3b82f6;text-decoration:none;'
            . 'font-weight:bold;font-size:14px;padding:11px 27px;border-radius:6px;border:2px solid #3b82f6;">Vérifier les disponibilités</a>'
            . '</div>';
    }

    /**
     * URL of the "book now" live-availability-check redirect, on our own
     * site (see bookingLinkButtonHtml() above).
     */
    public static function bookingRedirectUrl(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12
    ): string {
        return self::propertyActionUrl('reservation-directe', $propertyId, $checkin, $checkout, $adults, $children3to12);
    }

    /**
     * URL of the "check availability" confirmation page, on our own site
     * (see availabilityCheckButtonHtml() above).
     */
    public static function availabilityCheckUrl(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12
    ): string {
        return self::propertyActionUrl('verifier-disponibilites', $propertyId, $checkin, $checkout, $adults, $children3to12);
    }

    private static function propertyActionUrl(
        string $action,
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12
    ): string {
        if ($propertyId <= 0) {
            return '';
        }
        $baseUrl = Auth::currentBaseUrl();
        if ($baseUrl === '') {
            return '';
        }
        $params = [
            'arrival' => $checkin,
            'departure' => $checkout,
            'adults' => $adults,
            'children' => $children3to12,
        ];
        return $baseUrl . '/properties/' . $propertyId . '/' . $action . '?' . http_build_query($params);
    }

    /**
     * Direct deep-link to the Lodgify checkout page for this property/dates,
     * e.g. https://checkout.lodgify.com/fr/sam-chlo/494936/reservation?
     * currency=EUR&arrival=2026-10-14&departure=2026-10-16&adults=2&
     * slug=sam-chlo&propertyId=494936 — only ever used *after* a live
     * availability check confirms the property is bookable for those dates
     * (see PageController::bookingRedirect()/availabilityCheck()), never
     * linked to directly from an unchecked email button.
     */
    public static function lodgifyCheckoutUrl(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $adults,
        string $lang = 'fr'
    ): string {
        $slug = trim((string) (Settings::get('LODGIFY_CHECKOUT_SLUG') ?? '')) ?: 'sam-chlo';
        $currency = trim((string) (Settings::get('LODGIFY_CHECKOUT_CURRENCY') ?? '')) ?: 'EUR';
        $checkoutBase = rtrim(
            trim((string) (Settings::get('LODGIFY_CHECKOUT_BASE_URL') ?? '')) ?: 'https://checkout.lodgify.com',
            '/'
        );
        $lang = $lang !== '' ? $lang : 'fr';
        $params = [
            'currency' => $currency,
            'arrival' => $checkin,
            'departure' => $checkout,
            'adults' => max(1, $adults),
            'slug' => $slug,
            'propertyId' => $propertyId,
        ];
        return $checkoutBase . '/' . $lang . '/' . $slug . '/' . $propertyId . '/reservation?' . http_build_query($params);
    }

    /**
     * Deep-link back to this partner's own site (see assets/js/app.js
     * initPartnerCodeFromHash() and PageController::submitPartnerCode()),
     * e.g. https://example.com/#scl, so clicking it from the signature opens
     * the partner's branded site directly without retyping their code.
     */
    private static function partnerLink(int $partnerId): string
    {
        if ($partnerId <= 0) {
            return '';
        }
        $stmt = Database::connection()->prepare('SELECT subdomain FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        $subdomain = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($subdomain === '') {
            return '';
        }
        $baseUrl = Auth::currentBaseUrl();
        return $baseUrl === '' ? '' : $baseUrl . '/#' . $subdomain;
    }

    /**
     * Converts a locally-uploaded relative path (e.g. "/images/others/...")
     * into an absolute URL suitable for embedding in outgoing email HTML,
     * using the actual request host (see Auth::currentBaseUrl()) rather than
     * a possibly-stale "APP_URL" setting. Already-absolute URLs are returned
     * unchanged.
     */
    private static function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $baseUrl = Auth::currentBaseUrl();
        if ($baseUrl === '') {
            return '';
        }
        return $baseUrl . '/' . ltrim($url, '/');
    }


    public static function decodeGuests(mixed $guests): array
    {
        if (is_array($guests)) {
            return $guests;
        }
        if (!is_string($guests) || $guests === '') {
            return [];
        }
        $decoded = json_decode($guests, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
