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
        $childrenUnder5 = max(0, (int) ($input['children_under5'] ?? 0));
        $children5to12 = max(0, (int) ($input['children_5to12'] ?? 0));
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

        $totalGuests = $adults + $childrenUnder5 + $children5to12;
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
            $childrenUnder5 = max(0, (int) ($input['children_under5'] ?? 0));
            $children5to12 = max(0, (int) ($input['children_5to12'] ?? 0));
            $totalGuests = $adults + $childrenUnder5 + $children5to12;
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

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reservation_requests (partner_id, property_id, property_name, client_name, client_email, client_phone, checkin_date, checkout_date, adults, children, guests, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
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
                json_encode($input['guests'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::nullableString($input['message'] ?? null),
            ]);
            $id = (int) $pdo->lastInsertId();
            self::sendRequestEmails($partner, $input);
            self::json(['data' => ['id' => $id], 'message' => 'Reservation request submitted'], 201);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to submit request'], 500);
        }
    }

    /**
     * Lets a visitor request several properties in one go (built from the
     * "Calendrier" board where a date range can be picked per property row).
     * All items share the same party size and client info. Distinct
     * properties can be combined to reach the requested party size, so no
     * single item is rejected for having an individually insufficient
     * capacity; instead the *combined* max capacity of every distinct
     * selected property is checked once against the total party size before
     * anything is inserted, and the whole request is rejected only if that
     * combined capacity is still insufficient.
     */
    public static function requestMultiple(): never
    {
        $input = self::input();
        $clientName = trim((string) ($input['client_name'] ?? ''));
        $clientEmail = trim((string) ($input['client_email'] ?? ''));
        $adults = max(0, (int) ($input['adults'] ?? 0));
        $childrenUnder5 = max(0, (int) ($input['children_under5'] ?? 0));
        $children5to12 = max(0, (int) ($input['children_5to12'] ?? 0));
        $totalGuests = $adults + $childrenUnder5 + $children5to12;
        $children = $childrenUnder5 + $children5to12;

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
        // Distinct properties can be combined to reach the requested party
        // size (e.g. 8 guests split across two 4-person properties), so
        // capacity is no longer rejected per item; instead the *combined*
        // max capacity of every distinct selected property is checked once
        // all items have been read.
        $capacityByProperty = [];
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
        }

        // A property with an unknown/zero max_guests is treated as having no
        // capacity limit (consistent with the capacity_ok display logic), so
        // it never blocks the combined total below.
        $combinedCapacity = array_sum($capacityByProperty);
        $hasUnlimitedProperty = in_array(0, $capacityByProperty, true);
        if (!$hasUnlimitedProperty && $totalGuests > $combinedCapacity) {
            self::json([
                'error' => 'Bad Request',
                'message' => "La capacité maximum cumulée des biens sélectionnés ({$combinedCapacity} personne(s)) est insuffisante pour {$totalGuests} personne(s). Ajoutez un ou plusieurs biens supplémentaires à votre sélection.",
            ], 400);
        }

        $partner = self::requirePartnerContext();
        $pdo = Database::connection();
        $createdIds = [];
        $guestsJson = json_encode($input['guests'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $clientPhone = self::nullableString($input['client_phone'] ?? null);
        $message = self::nullableString($input['message'] ?? null);

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO reservation_requests (partner_id, property_id, property_name, client_name, client_email, client_phone, checkin_date, checkout_date, adults, children, guests, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalizedItems as $item) {
                $stmt->execute([
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
                    $guestsJson,
                    $message,
                ]);
                $createdIds[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to submit requests'], 500);
        }

        foreach ($normalizedItems as $item) {
            self::sendRequestEmails($partner, [
                'client_name' => $clientName,
                'client_email' => $clientEmail,
                'client_phone' => $clientPhone,
                'checkin_date' => $item['checkin_date'],
                'checkout_date' => $item['checkout_date'],
                'adults' => $adults,
                'children' => $children,
                'children_under5' => $childrenUnder5,
                'property_id' => $item['property_id'],
                'property_name' => $item['property_name'],
                'message' => $message,
            ]);
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
        $partnerId = $user['partner_id'] ?? null;
        $input = self::input();
        $notes = self::nullableString($input['notes'] ?? null);
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $partnerId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            self::json(['error' => 'Not Found', 'message' => 'Reservation request not found'], 404);
        }

        try {
            $pdo->prepare(
                'INSERT INTO reservations (request_id, partner_id, confirmed_at, notes)
                 VALUES (?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE confirmed_at = NOW(), cancelled_at = NULL, notes = VALUES(notes)'
            )->execute([$id, $partnerId, $notes]);
            $pdo->prepare("UPDATE reservation_requests SET status = 'confirmed', updated_at = NOW() WHERE id = ?")->execute([$id]);
            $partner = self::fetchPartner((int) $partnerId);
            self::sendReservationStatusEmail($partner, $request, 'RESERVATION_CONFIRMED', $notes);
            self::json(['data' => null, 'message' => 'Reservation confirmed']);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to confirm reservation'], 500);
        }
    }

    public static function cancel(int $id): never
    {
        $user = Auth::requireUser();
        $partnerId = $user['partner_id'] ?? null;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $partnerId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            self::json(['error' => 'Not Found', 'message' => 'Reservation request not found'], 404);
        }

        try {
            $pdo->prepare('UPDATE reservations SET cancelled_at = NOW() WHERE request_id = ?')->execute([$id]);
            $pdo->prepare("UPDATE reservation_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$id]);
            $partner = self::fetchPartner((int) $partnerId);
            self::sendReservationStatusEmail($partner, $request, 'RESERVATION_CANCELLED', null);
            self::json(['data' => null, 'message' => 'Reservation cancelled']);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to cancel reservation'], 500);
        }
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
        $partnerId = (int) $partner['id'];
        $contactUser = self::fetchPartnerContactUser($partnerId);
        $signatureName = trim((string) ($contactUser['first_name'] ?? '') . ' ' . (string) ($contactUser['last_name'] ?? ''));
        if ($signatureName === '') {
            $signatureName = (string) ($partner['name'] ?? '');
        }

        $inlineImages = [];
        $variables = [
            'nom_client' => (string) ($input['client_name'] ?? ''),
            'email_client' => (string) ($input['client_email'] ?? ''),
            'telephone_client' => (string) ($input['client_phone'] ?? ''),
            'dates' => (string) ($input['checkin_date'] ?? '') . ' → ' . (string) ($input['checkout_date'] ?? ''),
            'date_arrivee' => (string) ($input['checkin_date'] ?? ''),
            'date_depart' => (string) ($input['checkout_date'] ?? ''),
            'adultes' => (string) ($input['adults'] ?? 0),
            'enfants' => (string) ($input['children'] ?? 0),
            'bebes' => (string) ($input['children_under5'] ?? 0),
            'hebergement' => (string) ($input['property_name'] ?? ''),
            'message' => (string) ($input['message'] ?? ''),
            'partenaire' => (string) ($partner['name'] ?? ''),
            'photo_bien' => self::propertyPhotoTag($input['property_id'] ?? null, (string) ($input['property_name'] ?? ''), $inlineImages),
            'signature_nom' => $signatureName,
            'signature_photo' => self::signaturePhotoTag((string) ($contactUser['photo_url'] ?? ''), $signatureName, $inlineImages),
            'telephone_partenaire' => (string) ($contactUser['phone'] ?? ''),
            'lien_partenaire' => self::partnerLink($partner),
        ];

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');
        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_PARTNER']);
        $partnerTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($partnerTemplate) {
            Mailer::sendTemplatedEmail($partner, $partnerTemplate, (string) $partner['email'], $variables, $inlineImages);
        } else {
            Mailer::sendRawEmail($partner, (string) $partner['email'], 'Nouvelle demande de réservation - ' . $variables['nom_client'], '<p>Nouvelle demande de ' . htmlspecialchars($variables['nom_client']) . ' (' . htmlspecialchars($variables['email_client']) . ') pour ' . htmlspecialchars($variables['hebergement'] !== '' ? $variables['hebergement'] : 'hébergement non spécifié') . ' du ' . htmlspecialchars($variables['date_arrivee']) . ' au ' . htmlspecialchars($variables['date_depart']) . '.</p>');
        }

        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_CLIENT']);
        $clientTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($clientTemplate) {
            Mailer::sendTemplatedEmail($partner, $clientTemplate, (string) $input['client_email'], $variables, $inlineImages);
        } else {
            Mailer::sendRawEmail($partner, (string) $input['client_email'], 'Confirmation de votre demande - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $input['client_name']) . ',</p><p>Nous avons bien reçu votre demande de réservation pour ' . htmlspecialchars((string) ($input['property_name'] ?? 'l\'hébergement')) . ' du ' . htmlspecialchars((string) $input['checkin_date']) . ' au ' . htmlspecialchars((string) $input['checkout_date']) . '. Nous vous contacterons très prochainement.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>');
        }
    }

    private static function sendReservationStatusEmail(array $partner, array $request, string $type, ?string $notes): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');
        $stmt->execute([(int) $partner['id'], $type]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $variables = [
            'nom_client' => (string) $request['client_name'],
            'email_client' => (string) $request['client_email'],
            'dates' => (string) $request['checkin_date'] . ' → ' . (string) $request['checkout_date'],
            'date_arrivee' => (string) $request['checkin_date'],
            'date_depart' => (string) $request['checkout_date'],
            'adultes' => (string) $request['adults'],
            'enfants' => (string) $request['children'],
            'hebergement' => (string) $request['property_name'],
            'notes' => $notes ?? '',
            'partenaire' => (string) $partner['name'],
        ];

        if ($template) {
            Mailer::sendTemplatedEmail($partner, $template, (string) $request['client_email'], $variables);
            return;
        }

        if ($type === 'RESERVATION_CONFIRMED') {
            Mailer::sendRawEmail($partner, (string) $request['client_email'], 'Votre réservation est confirmée - ' . (string) $partner['name'], '<p>Bonjour ' . htmlspecialchars((string) $request['client_name']) . ',</p><p>Votre réservation pour ' . htmlspecialchars((string) $request['property_name']) . ' du ' . htmlspecialchars((string) $request['checkin_date']) . ' au ' . htmlspecialchars((string) $request['checkout_date']) . ' est confirmée.</p><p>Cordialement,<br>' . htmlspecialchars((string) $partner['name']) . '</p>');
        }
    }

    private static function fetchPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The primary partner-side contact (used to sign client emails with a name/photo)
     * is the oldest 'partner' role user attached to the partner account, since this
     * app does not track which staff member handled a given request.
     */
    private static function fetchPartnerContactUser(int $partnerId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT first_name, last_name, phone, photo_url FROM users WHERE partner_id = ? AND role = 'partner' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$partnerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Renders a small thumbnail of the requested property so the client
     * recognises which accommodation their request was about at a glance.
     * The image is embedded inline (multipart/related, referenced by "cid:")
     * so it renders even in mail clients that refuse to fetch remote/hotlinked
     * URLs; if the bytes can't be resolved it falls back to an absolute <img
     * src> URL, and finally to an empty string. Collected inline images are
     * appended to $inlineImages (passed by reference).
     *
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     */
    private static function propertyPhotoTag(mixed $propertyId, string $propertyName, array &$inlineImages = []): string
    {
        $propertyId = (int) $propertyId;
        if ($propertyId <= 0) {
            return '';
        }
        try {
            $property = (new LodgifyClient())->getProperty($propertyId);
        } catch (Throwable $e) {
            error_log('Lodgify: failed to fetch property ' . $propertyId . ' for email photo: ' . $e->getMessage());
            return '';
        }
        $url = $property['images'][0]['url'] ?? ($property['image_url'] ?? '');
        if (!is_string($url) || $url === '') {
            return '';
        }
        $alt = htmlspecialchars($propertyName !== '' ? $propertyName : 'Hébergement', ENT_QUOTES, 'UTF-8');
        $src = self::embeddableImageSrc($url, $inlineImages);
        if ($src === '') {
            return '';
        }
        return '<p><img src="' . $src . '" alt="' . $alt . '" width="160" style="max-width:100%;height:auto;border-radius:8px;display:block;margin:12px 0;"></p>';
    }

    /**
     * Renders the small round signature photo of the partner contact used at
     * the bottom of client emails, falling back to an empty string when the
     * contact has no profile photo set. Like propertyPhotoTag(), embeds the
     * photo inline (cid:) when possible so it survives strict mail clients.
     *
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     */
    private static function signaturePhotoTag(string $photoUrl, string $name, array &$inlineImages = []): string
    {
        if ($photoUrl === '') {
            return '';
        }
        $alt = htmlspecialchars($name !== '' ? $name : 'Partenaire', ENT_QUOTES, 'UTF-8');
        $src = self::embeddableImageSrc($photoUrl, $inlineImages);
        if ($src === '') {
            return '';
        }
        return '<img src="' . $src . '" alt="' . $alt . '" width="48" height="48" style="width:48px;height:48px;border-radius:50%;object-fit:cover;display:block;">';
    }

    /**
     * Turns an image URL (a local cached path like "/images/listings/..", or a
     * remote Lodgify CDN URL) into a value usable as an email <img src>. It
     * first tries to read the actual image bytes and register them as an inline
     * attachment, returning a "cid:.." reference — the only reliable way to make
     * images appear in every mail client, since many won't fetch remote URLs.
     * When the bytes can't be read it falls back to an absolute URL, and to an
     * empty string only if even that can't be built. The returned string is
     * safe to embed in HTML (attribute-escaped).
     *
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     */
    private static function embeddableImageSrc(string $url, array &$inlineImages): string
    {
        $resolved = self::resolveImageBytes($url);
        if ($resolved !== null) {
            $cid = bin2hex(random_bytes(16)) . '@grand-baie-maurice.com';
            $inlineImages[] = ['cid' => $cid, 'data' => $resolved['data'], 'mime' => $resolved['mime']];
            return htmlspecialchars('cid:' . $cid, ENT_QUOTES, 'UTF-8');
        }
        $absolute = self::absoluteUrl($url);
        return $absolute === '' ? '' : htmlspecialchars($absolute, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Reads the raw bytes and MIME type of an image referenced either by a
     * site-local path (served from the project root, e.g.
     * "/images/listings/12/ab.jpg") or by an http(s) URL. Returns null on any
     * failure so callers can fall back to a plain URL.
     *
     * @return array{data:string,mime:string}|null
     */
    private static function resolveImageBytes(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            // Site-relative path -> read the cached file straight off disk.
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                return null;
            }
            $localPath = BASE_PATH . '/' . ltrim($path, '/');
            $realBase = realpath(BASE_PATH);
            $realPath = realpath($localPath);
            // Guard against path traversal: only ever read files inside BASE_PATH.
            if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
                return null;
            }
            $data = @file_get_contents($realPath);
            if ($data === false || $data === '') {
                return null;
            }
            return ['data' => $data, 'mime' => self::detectMime($data)];
        }

        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        $data = self::downloadImage($url);
        if ($data === null || $data === '') {
            return null;
        }
        return ['data' => $data, 'mime' => self::detectMime($data)];
    }

    private static function detectMime(string $data): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $data);
                finfo_close($finfo);
                if (is_string($mime) && str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }
        return 'image/jpeg';
    }

    private static function downloadImage(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'grand-baie-maurice.com email image embedder',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || !is_string($body) || $body === '' || $error !== '' || $status >= 400) {
            return null;
        }
        return $body;
    }

    /**
     * Turns a locally-stored relative path (e.g. "/images/others/avatars/x.jpg")
     * into an absolute URL, since email clients have no notion of the site's
     * host and would otherwise render a broken image. Uses the scheme+host the
     * current visitor actually used (Auth::currentBaseUrl()) rather than the
     * "APP_URL" setting, which is only set once at install time and easily
     * goes stale. Already-absolute URLs (e.g. Lodgify's CDN photos) are
     * returned unchanged.
     */
    private static function absoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url) === 1) {
            return $url;
        }
        $baseUrl = Auth::currentBaseUrl();
        if ($baseUrl === '') {
            return $url;
        }
        return $baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * Builds the client-facing link back to the partner's booking site (home
     * page + "#code partenaire" fragment, the mechanism this app uses instead
     * of real subdomains, see Tenant::current()). Uses the scheme+host the
     * current visitor actually used rather than the "APP_URL" setting so the
     * link is never stuck on a stale install-time value (localhost, http://).
     */
    private static function partnerLink(array $partner): string
    {
        $baseUrl = Auth::currentBaseUrl();
        $subdomain = (string) ($partner['subdomain'] ?? '');
        if ($baseUrl === '' || $subdomain === '') {
            return '';
        }
        return $baseUrl . '/#' . $subdomain;
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
