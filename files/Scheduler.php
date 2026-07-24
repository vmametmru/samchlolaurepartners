<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Scheduler
{
    public static function runOnce(): array
    {
        $pdo = Database::connection();
        // This cron entry point (bin/run-scheduler.php) only requires
        // bootstrap.php, not index.php, so Migrator::autoRun() would
        // otherwise never run here — unlike every web request, which
        // guarantees pending migrations (e.g. reservation_requests.language,
        // added by migration 025 and read below) are applied before this
        // query runs.
        try {
            Migrator::autoRun();
        } catch (\Throwable $e) {
            error_log('[scheduler] migration check failed: ' . $e->getMessage());
        }
        $sql = <<<'SQL'
SELECT
  es.id AS schedule_id,
  es.days_before_arrival,
  es.template_type,
  r.id AS reservation_id,
  rr.client_name,
  rr.client_email,
  rr.client_phone,
  rr.checkin_date,
  rr.checkout_date,
  rr.adults,
  rr.children,
  rr.property_id,
  rr.property_name,
  rr.guests,
  rr.language AS request_language,
  p.*
FROM email_schedules es
JOIN partners p ON p.id = es.partner_id
JOIN reservations r ON r.partner_id = p.id
JOIN reservation_requests rr ON rr.id = r.request_id
WHERE es.active = 1
  AND r.cancelled_at IS NULL
  AND DATE(rr.checkin_date) = DATE_ADD(CURDATE(), INTERVAL es.days_before_arrival DAY)
  AND NOT EXISTS (
    SELECT 1 FROM sent_schedule_emails sse
    WHERE sse.schedule_id = es.id AND sse.reservation_id = r.id
  )
SQL;

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        $errors = [];

        foreach ($rows as $row) {
            $requestLanguage = in_array((string) ($row['request_language'] ?? ''), I18n::SUPPORTED, true)
                ? (string) $row['request_language']
                : I18n::DEFAULT_LANGUAGE;
            $template = \App\controllers\ReservationsController::findEmailTemplate($pdo, (int) $row['partner_id'], (string) $row['template_type'], $requestLanguage);
            if (!$template) {
                continue;
            }

            $variables = [
                'nom_client' => (string) $row['client_name'],
                'email_client' => (string) $row['client_email'],
                'telephone_client' => (string) ($row['client_phone'] ?? ''),
                'adultes' => (string) $row['adults'],
                'enfants' => (string) $row['children'],
                'hebergement' => (string) $row['property_name'],
                'partenaire' => (string) $row['name'],
                'nationalites' => \App\controllers\ReservationsController::guestNationalitiesText(
                    \App\controllers\ReservationsController::decodeGuests($row['guests'] ?? null)
                ),
                'photo_bien' => \App\controllers\ReservationsController::propertyPhotoVariable((int) ($row['property_id'] ?? 0), (string) $row['property_name'], 1),
                'photo_bien_url' => \App\controllers\ReservationsController::propertyPhotoUrlValue((int) ($row['property_id'] ?? 0), 1),
                'photo1' => \App\controllers\ReservationsController::propertyPhotoVariable((int) ($row['property_id'] ?? 0), (string) $row['property_name'], 1),
                'photo2' => \App\controllers\ReservationsController::propertyPhotoVariable((int) ($row['property_id'] ?? 0), (string) $row['property_name'], 2),
                'photo3' => \App\controllers\ReservationsController::propertyPhotoVariable((int) ($row['property_id'] ?? 0), (string) $row['property_name'], 3),
                'photo1_url' => \App\controllers\ReservationsController::propertyPhotoUrlValue((int) ($row['property_id'] ?? 0), 1),
                'photo2_url' => \App\controllers\ReservationsController::propertyPhotoUrlValue((int) ($row['property_id'] ?? 0), 2),
                'photo3_url' => \App\controllers\ReservationsController::propertyPhotoUrlValue((int) ($row['property_id'] ?? 0), 3),
                'logo_partenaire' => \App\controllers\ReservationsController::partnerLogoVariable((string) ($row['logo_url'] ?? ''), (string) $row['name']),
                'logo_partenaire_url' => \App\controllers\ReservationsController::partnerLogoUrlValue((string) ($row['logo_url'] ?? '')),
                'email_partenaire' => (string) ($row['email'] ?? ''),
                'politique_reservation' => \App\controllers\PageController::formatBookingPolicyHtml(\App\controllers\PageController::bookingPolicyText()),
                'bouton_reservation' => \App\controllers\ReservationsController::bookingLinkButtonHtml(
                    (int) ($row['property_id'] ?? 0),
                    (string) $row['checkin_date'],
                    (string) $row['checkout_date'],
                    (int) $row['adults'],
                    (int) ($row['children'] ?? 0)
                ),
            ];
            $variables += \App\controllers\ReservationsController::stayVariables(
                (string) $row['checkin_date'],
                (string) $row['checkout_date'],
                0,
                (int) ($row['children'] ?? 0)
            );
            $signature = \App\controllers\ReservationsController::signatureVariables((int) $row['id']);
            $variables += $signature['variables'];
            $embeds = $signature['embed'] !== null ? [$signature['embed']] : [];

            try {
                Mailer::sendTemplatedEmail($row, $template, (string) $row['client_email'], $variables, $embeds, (string) ($row['email'] ?? ''));
                $markStmt = $pdo->prepare('INSERT IGNORE INTO sent_schedule_emails (schedule_id, reservation_id) VALUES (?, ?)');
                $markStmt->execute([(int) $row['schedule_id'], (int) $row['reservation_id']]);
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = 'Reservation ' . $row['reservation_id'] . ': ' . $e->getMessage();
            }
        }

        return ['checked' => count($rows), 'sent' => $sent, 'errors' => $errors];
    }

    /**
     * Refreshes the local Lodgify properties cache (name, description,
     * photo gallery, capacity, amenities, ...). This is manual-only: it is no
     * longer invoked from the cron job, only from the "Synchroniser
     * maintenant" admin action (PageController::adminSync()). Property fiche
     * data has no automatic refresh at all anymore — it never expires on its
     * own (see LodgifyClient::FICHE_TTL) — so photos stay stable/normalized
     * (photo1.jpg, photo2.jpg, ...) on the server until an admin explicitly
     * re-syncs. Prices/availability are unaffected: they are always fetched
     * live at search time, never cached.
     *
     * getProperties() alone only refreshes the compact property list (cards),
     * which Lodgify limits to a single image per property, so this also calls
     * refreshAllPropertyDetails() to reload every property's full detail data
     * — including its complete photo gallery — and keep the local image cache
     * (images/listings/) up to date.
     */
    public static function syncLodgify(): array
    {
        try {
            $client = new LodgifyClient();
            $client->invalidate('lodgify:');
            $properties = $client->getProperties();
            $details = $client->refreshAllPropertyDetails();
            return ['synced' => count($properties), 'error' => null, 'photo_errors' => $details['photo_errors']];
        } catch (\Throwable $e) {
            return ['synced' => 0, 'error' => $e->getMessage(), 'photo_errors' => []];
        }
    }
}
