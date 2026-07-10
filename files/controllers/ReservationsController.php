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

        if ($clientName === '' || $clientEmail === '' || $checkin === '' || $checkout === '' || $adults === 0) {
            self::json(['error' => 'Bad Request', 'message' => 'Required fields missing'], 400);
        }
        if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
            self::json(['error' => 'Bad Request', 'message' => 'Invalid client_email'], 400);
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
        $variables = [
            'nom_client' => (string) ($input['client_name'] ?? ''),
            'email_client' => (string) ($input['client_email'] ?? ''),
            'telephone_client' => (string) ($input['client_phone'] ?? ''),
            'dates' => (string) ($input['checkin_date'] ?? '') . ' → ' . (string) ($input['checkout_date'] ?? ''),
            'date_arrivee' => (string) ($input['checkin_date'] ?? ''),
            'date_depart' => (string) ($input['checkout_date'] ?? ''),
            'adultes' => (string) ($input['adults'] ?? 0),
            'enfants' => (string) ($input['children'] ?? 0),
            'hebergement' => (string) ($input['property_name'] ?? ''),
            'message' => (string) ($input['message'] ?? ''),
            'partenaire' => (string) ($partner['name'] ?? ''),
        ];

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');
        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_PARTNER']);
        $partnerTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($partnerTemplate) {
            Mailer::sendTemplatedEmail($partner, $partnerTemplate, (string) $partner['email'], $variables);
        } else {
            Mailer::sendRawEmail($partner, (string) $partner['email'], 'Nouvelle demande de réservation - ' . $variables['nom_client'], '<p>Nouvelle demande de ' . htmlspecialchars($variables['nom_client']) . ' (' . htmlspecialchars($variables['email_client']) . ') pour ' . htmlspecialchars($variables['hebergement'] !== '' ? $variables['hebergement'] : 'hébergement non spécifié') . ' du ' . htmlspecialchars($variables['date_arrivee']) . ' au ' . htmlspecialchars($variables['date_depart']) . '.</p>');
        }

        $stmt->execute([(int) $partner['id'], 'REQUEST_RECEIVED_CLIENT']);
        $clientTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($clientTemplate) {
            Mailer::sendTemplatedEmail($partner, $clientTemplate, (string) $input['client_email'], $variables);
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
