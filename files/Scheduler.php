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
        // es.partner_id is nullable: NULL means "Tous les partenaires" — the
        // schedule applies to every partner's confirmed reservations
        // instead of a single one — so reservations/partners are joined via
        // the reservation's own partner_id, only narrowed down to
        // es.partner_id when that column is set (a partner-specific
        // schedule).
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
  rr.quote_room_total,
  rr.quote_extra_person_total,
  rr.quote_cleaning_total,
  rr.quote_tourist_tax_total,
  rr.quote_nights,
  rr.quote_currency,
  rr.quote_partner_rate,
  rr.quote_vat_rate,
  p.*
FROM email_schedules es
JOIN reservations r ON (es.partner_id IS NULL OR es.partner_id = r.partner_id)
JOIN reservation_requests rr ON rr.id = r.request_id
JOIN partners p ON p.id = r.partner_id
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
            $templateType = (string) $row['template_type'];
            // REMINDER_CLIENT and REMINDER_PARTNER are two fully independent
            // schedule entries (see migration 034 / /admin/cron): each one
            // only ever sends to a single recipient (the guest, resp. the
            // partner) using its own dedicated template — unlike every
            // other schedule type below, which sends the client template to
            // the client with an identical courtesy copy to the partner.
            $isReminderClient = $templateType === 'REMINDER_CLIENT';
            $isReminderPartner = $templateType === 'REMINDER_PARTNER';
            $template = \App\controllers\ReservationsController::findEmailTemplate($pdo, (int) $row['partner_id'], $templateType, $requestLanguage);
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
                'useful_info' => \App\controllers\ReservationsController::usefulInfoButtonHtml($row, $requestLanguage),
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

            // Adds the {{tarif_*}}/{{commission_partenaire}}/
            // {{paiement_a_samchlolaure}} variables from the quote breakdown
            // persisted on the request at submission time (same source used
            // by sendReservationStatusEmail()), so the REMINDER_PARTNER
            // template can show the partner their commission/payout for
            // this stay. Skipped when no quote was ever recorded (e.g. old
            // requests created before quote persistence was added).
            if (($row['quote_room_total'] ?? null) !== null) {
                $variables += \App\controllers\ReservationsController::buildQuoteVariables(
                    \App\controllers\ReservationsController::computeQuoteBreakdown([
                        'room_total' => $row['quote_room_total'] ?? 0,
                        'extra_person_total' => $row['quote_extra_person_total'] ?? 0,
                        'cleaning_total' => $row['quote_cleaning_total'] ?? 0,
                        'tourist_tax_total' => $row['quote_tourist_tax_total'] ?? 0,
                        'nights' => $row['quote_nights'] ?? 0,
                        'currency' => $row['quote_currency'] ?? 'EUR',
                    ], (float) ($row['quote_partner_rate'] ?? ($row['markup_percent'] ?? 0)), (float) ($row['quote_vat_rate'] ?? 0))
                );
            }

            $partnerEmail = trim((string) ($row['email'] ?? ''));

            try {
                if ($isReminderPartner) {
                    // Partner-only entry: skip entirely (no client email,
                    // nothing to mark as sent) if the partner has no email
                    // configured.
                    if ($partnerEmail === '') {
                        continue;
                    }
                    // Partner-facing copies always stay in French (see
                    // ReservationsController::sendRequestEmails()), so
                    // {{useful_info}} is rebuilt in French here too.
                    $partnerVariables = $variables;
                    if ($requestLanguage !== \App\I18n::DEFAULT_LANGUAGE) {
                        $partnerVariables['useful_info'] = \App\controllers\ReservationsController::usefulInfoButtonHtml($row, \App\I18n::DEFAULT_LANGUAGE);
                    }
                    Mailer::sendTemplatedEmail($row, $template, $partnerEmail, $partnerVariables, $embeds, (string) $row['client_email']);
                } else {
                    // REMINDER_CLIENT never leaks partner-only variables
                    // (commission, amount owed) even if a partner mistakenly
                    // references one in their client-facing template.
                    $clientVariables = $isReminderClient
                        ? \App\controllers\ReservationsController::redactPartnerOnlyVariables($variables)
                        : $variables;
                    Mailer::sendTemplatedEmail($row, $template, (string) $row['client_email'], $clientVariables, $embeds, (string) ($row['email'] ?? ''));
                }
                $sent++;
                $markStmt = $pdo->prepare('INSERT IGNORE INTO sent_schedule_emails (schedule_id, reservation_id) VALUES (?, ?)');
                $markStmt->execute([(int) $row['schedule_id'], (int) $row['reservation_id']]);
            } catch (\Throwable $e) {
                $errors[] = 'Reservation ' . $row['reservation_id'] . ': ' . $e->getMessage();
                continue;
            }

            // Send the partner their own courtesy copy of the client
            // template (e.g. RESERVATION_CONFIRMED), so they stay aware of
            // upcoming/confirmed stays without checking the admin
            // dashboard. Not applicable to REMINDER_CLIENT/REMINDER_PARTNER,
            // which are already independent, single-recipient entries (see
            // above). Best-effort and isolated from the client send above:
            // a partner-copy failure (bad partner email, SMTP hiccup, ...)
            // must never be reported as a failed/unsent schedule entry,
            // since the client was already notified.
            if (!$isReminderClient && !$isReminderPartner && $partnerEmail !== '') {
                try {
                    Mailer::sendTemplatedEmail($row, $template, $partnerEmail, $variables, $embeds, (string) $row['client_email']);
                } catch (\Throwable $e) {
                    error_log('[scheduler] failed to send partner copy of ' . $row['template_type'] . ' email for reservation ' . $row['reservation_id'] . ': ' . $e->getMessage());
                }
            }
        }

        $result = ['checked' => count($rows), 'sent' => $sent, 'errors' => $errors];
        self::recordRun($result);

        return $result;
    }

    /**
     * Persists the outcome of the last run() (whether triggered by the real
     * cron job, bin/run-scheduler.php, or the "Exécuter maintenant" button
     * on /admin/cron) into the "settings" table, so the admin cron page can
     * display a "last run" status without needing a dedicated log table.
     *
     * @param array{checked:int, sent:int, errors:array<int,string>} $result
     */
    private static function recordRun(array $result): void
    {
        try {
            Settings::set('CRON_SCHEDULER_LAST_RUN_AT', (new \DateTimeImmutable('now'))->format('c'));
            Settings::set('CRON_SCHEDULER_LAST_CHECKED', (string) $result['checked']);
            Settings::set('CRON_SCHEDULER_LAST_SENT', (string) $result['sent']);
            Settings::set('CRON_SCHEDULER_LAST_ERRORS', json_encode(array_slice($result['errors'], 0, 20), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
            Settings::reload();
        } catch (\Throwable $e) {
            error_log('[scheduler] failed to record run status: ' . $e->getMessage());
        }
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
