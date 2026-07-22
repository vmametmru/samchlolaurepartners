<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\LodgifyClient;
use App\Mailer;
use PDO;
use Throwable;

final class ReservationsController extends Controller
{
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
        $client = new LodgifyClient();

        // Every property has a maximum occupancy (Lodgify's max_guests); a
        // reservation request must never exceed it, otherwise the property
        // could be booked for more people than it can actually host.
        $property = null;
        try {
            $property = $client->getProperty($propertyId);
        } catch (Throwable $e) {
            error_log('Lodgify: failed to fetch property ' . $propertyId . ': ' . $e->getMessage());
        }
        $maxGuests = (int) ($property['max_guests'] ?? 0);
        if ($maxGuests > 0 && $totalGuests > $maxGuests) {
            self::json([
                'error' => 'Bad Request',
                'message' => "Ce logement peut accueillir au maximum {$maxGuests} personne(s) (adultes + enfants).",
            ], 400);
        }

        try {
            $rates = PageController::publicRates($client, $propertyId, $checkin, $checkoutDate->modify('-1 day')->format('Y-m-d'));
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Service Unavailable', 'message' => 'Tarifs indisponibles pour le moment'], 503);
        }
        $currency = $rates[0]['currency'] ?? 'EUR';
        $roomTotal = 0.0;
        foreach ($rates as $rate) {
            $roomTotal += (float) $rate['price_per_night'];
        }

        $pdo = Database::connection();

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
        $cleaningTotal = round($cleaningRate * $totalGuests * $nights, 2);

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
            // conservative estimate assuming all guests may be liable.
            $qualifyingGuests = $appliesToChildren ? $totalGuests : $adults;
        }
        $touristTaxTotal = round($taxRate * $qualifyingGuests * $nights, 2);

        $grandTotal = round($roomTotal + $cleaningTotal + $touristTaxTotal, 2);
        $totalWithoutTax = round($roomTotal + $cleaningTotal, 2);

        self::json(['data' => [
            'nights' => $nights,
            'currency' => $currency,
            'room_total' => round($roomTotal, 2),
            'cleaning_total' => $cleaningTotal,
            'tourist_tax_total' => $touristTaxTotal,
            'tourist_tax_rate' => $taxRate,
            'total_without_tax' => $totalWithoutTax,
            'grand_total' => $grandTotal,
        ]]);
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
        // property can actually accommodate.
        if ($propertyId > 0) {
            $totalGuests = $adults + $childrenUnder3 + $children3to12;
            try {
                $property = (new LodgifyClient())->getProperty($propertyId);
                $maxGuests = (int) ($property['max_guests'] ?? 0);
                if ($maxGuests > 0 && $totalGuests > $maxGuests) {
                    self::json([
                        'error' => 'Bad Request',
                        'message' => "Ce logement peut accueillir au maximum {$maxGuests} personne(s) (adultes + enfants).",
                    ], 400);
                }
            } catch (Throwable $e) {
                error_log('Lodgify: failed to fetch property ' . $propertyId . ' for capacity check: ' . $e->getMessage());
            }
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
        $columns[] = 'guests';
        $columns[] = 'message';
        $params[] = json_encode($input['guests'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $params[] = self::nullableString($input['message'] ?? null);

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
            self::sendRequestEmails($partner, $input + [
                'children_under3' => $childrenUnder3,
                'children_3to12' => $children3to12,
            ]);
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

            $normalizedItems[] = [
                'property_id' => $propertyId,
                'property_name' => $propertyName,
                'checkin_date' => $checkinDate->format('Y-m-d'),
                'checkout_date' => $checkoutDate->format('Y-m-d'),
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
                $babyCapacity = count($activePropertyIds) * 2;
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
            $columns[] = 'guests';
            $columns[] = 'message';
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
                $params[] = $guestsJson;
                $params[] = $message;
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
        foreach ($normalizedItems as $item) {
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
                ]);
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

    private static function sendRequestEmails(array $partner, array $input): void
    {
        $photo = self::propertyPhotoTag(
            (int) ($input['property_id'] ?? 0),
            (string) ($input['property_name'] ?? '')
        );
        $checkin = (string) ($input['checkin_date'] ?? '');
        $checkout = (string) ($input['checkout_date'] ?? '');
        $variables = [
            'nom_client' => (string) ($input['client_name'] ?? ''),
            'email_client' => (string) ($input['client_email'] ?? ''),
            'telephone_client' => (string) ($input['client_phone'] ?? ''),
            'adultes' => (string) ($input['adults'] ?? 0),
            'hebergement' => (string) ($input['property_name'] ?? ''),
            'message' => (string) ($input['message'] ?? ''),
            'partenaire' => (string) ($partner['name'] ?? ''),
            'photo_bien' => $photo['html'],
        ];
        $childBreakdown = self::childBreakdownValues($input);
        $variables += self::stayVariables($checkin, $checkout, $childBreakdown['under3'], $childBreakdown['from3to12']);
        $signature = self::signatureVariables((int) ($partner['id'] ?? 0));
        $variables += $signature['variables'];
        $embeds = $photo['embed'] !== null ? [$photo['embed']] : [];
        if ($signature['embed'] !== null) {
            $embeds[] = $signature['embed'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');

        // Each recipient is sent in its own try/catch: previously a failure
        // sending to the partner (bad SMTP credentials, unreachable host,
        // invalid partner email, ...) threw an exception that aborted this
        // whole method, silently skipping the client email below too. Now a
        // partner-side failure can never prevent the client from being
        // notified (and vice versa).
        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_PARTNER']);
        $partnerTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        try {
            if ($partnerTemplate) {
                Mailer::sendTemplatedEmail($partner, $partnerTemplate, (string) $partner['email'], $variables, $embeds);
            } else {
                Mailer::sendRawEmail($partner, (string) $partner['email'], 'Nouvelle demande de réservation - ' . $variables['nom_client'], '<p>Nouvelle demande de ' . htmlspecialchars($variables['nom_client']) . ' (' . htmlspecialchars($variables['email_client']) . ') pour ' . htmlspecialchars($variables['hebergement'] !== '' ? $variables['hebergement'] : 'hébergement non spécifié') . ' du ' . htmlspecialchars($variables['date_arrivee']) . ' au ' . htmlspecialchars($variables['date_depart']) . '.</p>');
            }
        } catch (Throwable $e) {
            error_log('Failed to send REQUEST_RECEIVED_PARTNER email to partner #' . (int) ($partner['id'] ?? 0) . ' (' . (string) ($partner['email'] ?? '') . '): ' . $e);
        }

        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_CLIENT']);
        $clientTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        try {
            if ($clientTemplate) {
                Mailer::sendTemplatedEmail($partner, $clientTemplate, (string) $input['client_email'], $variables, $embeds);
            } else {
                Mailer::sendRawEmail($partner, (string) $input['client_email'], 'Confirmation de votre demande - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $input['client_name']) . ',</p><p>Nous avons bien reçu votre demande de réservation pour ' . htmlspecialchars((string) ($input['property_name'] ?? 'l\'hébergement')) . ' du ' . htmlspecialchars((string) $input['checkin_date']) . ' au ' . htmlspecialchars((string) $input['checkout_date']) . '. Nous vous contacterons très prochainement.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>');
            }
        } catch (Throwable $e) {
            error_log('Failed to send REQUEST_RECEIVED_CLIENT email to ' . (string) ($input['client_email'] ?? '') . ': ' . $e);
        }
    }

    private static function sendReservationStatusEmail(array $partner, array $request, string $type, ?string $notes): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');
        $stmt->execute([(int) $partner['id'], $type]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $photo = self::propertyPhotoTag(
            (int) ($request['property_id'] ?? 0),
            (string) $request['property_name']
        );
        $variables = [
            'nom_client' => (string) $request['client_name'],
            'email_client' => (string) $request['client_email'],
            'adultes' => (string) $request['adults'],
            'hebergement' => (string) $request['property_name'],
            'notes' => $notes ?? '',
            'partenaire' => (string) $partner['name'],
            'photo_bien' => $photo['html'],
        ];
        $childBreakdown = self::childBreakdownValues($request);
        $variables += self::stayVariables(
            (string) $request['checkin_date'],
            (string) $request['checkout_date'],
            $childBreakdown['under3'],
            $childBreakdown['from3to12']
        );
        $signature = self::signatureVariables((int) ($partner['id'] ?? 0));
        $variables += $signature['variables'];
        $embeds = $photo['embed'] !== null ? [$photo['embed']] : [];
        if ($signature['embed'] !== null) {
            $embeds[] = $signature['embed'];
        }

        if ($template) {
            Mailer::sendTemplatedEmail($partner, $template, (string) $request['client_email'], $variables, $embeds);
            return;
        }

        if ($type === 'RESERVATION_CONFIRMED') {
            Mailer::sendRawEmail($partner, (string) $request['client_email'], 'Votre réservation est confirmée - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $request['client_name']) . ',</p><p>Votre réservation pour ' . htmlspecialchars((string) $request['property_name']) . ' du ' . htmlspecialchars((string) $request['checkin_date']) . ' au ' . htmlspecialchars((string) $request['checkout_date']) . ' est confirmée.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>');
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
    private static function stayVariables(string $checkin, string $checkout, int $childrenUnder3, int $children3to12): array
    {
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
        ];
    }

    /**
     * Formats an ISO ("Y-m-d") date as "ddd dd mmm yyyy" in French (e.g.
     * "mer. 12 août 2026"), so reservation emails never show the raw
     * database date format. Falls back to the original (unformatted) value
     * when it isn't a valid date, rather than throwing.
     */
    private static function formatDateFr(string $isoDate): string
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
        $html = '<img src="cid:' . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($propertyName, ENT_QUOTES, 'UTF-8') . '" width="320" style="display:block;width:320px;max-width:320px;height:auto;">';

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
                'lien_partenaire' => self::partnerLink($partnerId),
                'telephone_partenaire' => $phone,
            ],
            'embed' => $photo['embed'],
        ];
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
                'html' => '<img src="cid:' . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="64" height="64" style="display:inline-block;width:64px;height:64px;border-radius:50%;object-fit:cover;">',
                'embed' => ['cid' => $cid, 'data' => $data, 'mime' => $mime],
            ];
        }

        $absoluteUrl = self::absoluteUrl($photoUrl);
        if ($absoluteUrl === '') {
            return $empty;
        }

        return [
            'html' => '<img src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="64" height="64" style="display:inline-block;width:64px;height:64px;border-radius:50%;object-fit:cover;">',
            'embed' => null,
        ];
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


    private static function decodeGuests(mixed $guests): array
    {
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
