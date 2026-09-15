<?php

declare(strict_types=1);

namespace App;

use App\controllers\ReservationsController;
use PDO;

/**
 * Log of every "Offre Complète" (App\Packages) submission
 * (db/migrations/068_create_package_requests.sql). One row per client
 * submission, independent of how many reservation_requests rows it actually
 * created — a same-address multi-property submission spreads the party over
 * several properties, each becoming its own reservation_requests row linked
 * back here via package_request_id (see
 * PackagesController::publicRequest()/requestSameAddressSelection()).
 *
 * Statuses are deliberately separate from reservation_requests.status
 * ('pending'/'confirmed'/'cancelled'): this table tracks the offer
 * submission itself, not any of the accommodation requests it produced.
 */
final class PackageRequests
{
    public const STATUS_OPEN = 'open';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_VALIDATED, self::STATUS_CANCELLED];

    public static function tableReady(): bool
    {
        return Database::tableExists('package_requests');
    }

    /**
     * Logs a new offer submission and returns its id, or 0 when migration
     * 068 hasn't applied yet (never blocks the reservation request flow).
     *
     * $flightTotal/$transportTotal/$activityTotal/$mealTotal (migration 069)
     * are the offer's non-accommodation step totals as actually selected by
     * the client (Packages::extrasSelection()'s 'flight_total' plus the sum
     * of each block's 'line_total'), snapshotted here so the commission owed
     * on this specific request (see commissionVariables()) never drifts if
     * the offer's own prices are edited afterwards.
     *
     * $flightTitle/$flightPhotoUrl and $transports/$activities/$meals
     * (migration 070) are the title/photo of what was actually picked in
     * each step, for the {{offre_vol_titre}}/{{offre_vol_image}}/
     * {{offre_transport_titres}}/{{offre_transport_images}}/
     * {{offre_activites_titres}}/{{offre_activites_images}}/
     * {{offre_restauration_titres}}/{{offre_restauration_images}} email
     * variables (see selectionVariables()). $transports/$activities/$meals
     * are lists of ['title' => ..., 'photo_url' => ...].
     *
     * @param array<int, array{title: string, photo_url: string}> $transports
     * @param array<int, array{title: string, photo_url: string}> $activities
     * @param array<int, array{title: string, photo_url: string}> $meals
     */
    public static function log(
        int $packageId,
        int $partnerId,
        ?string $clientName,
        ?string $clientEmail,
        float $flightTotal = 0.0,
        float $transportTotal = 0.0,
        float $activityTotal = 0.0,
        float $mealTotal = 0.0,
        ?string $flightTitle = null,
        ?string $flightPhotoUrl = null,
        array $transports = [],
        array $activities = [],
        array $meals = []
    ): int {
        if ($packageId <= 0 || $partnerId <= 0 || !self::tableReady()) {
            return 0;
        }
        $hasStepTotals = Database::columnExists('package_requests', 'flight_total');
        $hasSelectionMedia = Database::columnExists('package_requests', 'flight_title');
        $columns = ['package_id', 'partner_id', 'status', 'client_name', 'client_email'];
        $params = [
            $packageId,
            $partnerId,
            self::STATUS_OPEN,
            $clientName !== null && trim($clientName) !== '' ? mb_substr(trim($clientName), 0, 190) : null,
            $clientEmail !== null && trim($clientEmail) !== '' ? mb_substr(trim($clientEmail), 0, 190) : null,
        ];
        if ($hasStepTotals) {
            $columns = [...$columns, 'flight_total', 'transport_total', 'activity_total', 'meal_total'];
            $params = [...$params, round($flightTotal, 2), round($transportTotal, 2), round($activityTotal, 2), round($mealTotal, 2)];
        }
        if ($hasSelectionMedia) {
            $columns = [...$columns, 'flight_title', 'flight_photo_url', 'transports_json', 'activities_json', 'meals_json'];
            $params = [
                ...$params,
                $flightTitle !== null && trim($flightTitle) !== '' ? mb_substr(trim($flightTitle), 0, 190) : null,
                $flightPhotoUrl !== null && trim($flightPhotoUrl) !== '' ? mb_substr(trim($flightPhotoUrl), 0, 500) : null,
                $transports !== [] ? json_encode(array_values($transports)) : null,
                $activities !== [] ? json_encode(array_values($activities)) : null,
                $meals !== [] ? json_encode(array_values($meals)) : null,
            ];
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO package_requests (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($params), '?')) . ')'
        );
        $stmt->execute($params);
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * "Commissions Offres Complète"/"Total à payer à SamChloLaure - Offres
     * Complètes" email variables (migration 069): the commission owed by
     * the partner on the offer's Vol/Transport/Activités/Restauration steps
     * (never on the accommodation itself, which already has its own
     * markup_percent-based commission — see
     * ReservationsController::buildQuoteVariables()'s commission_partenaire/
     * paiement_a_samchlolaure), plus the resulting total payable to
     * SamChloLaure once the accommodation payout is added.
     *
     * @param array<string, mixed> $partner
     * @param array<string, mixed>|null $packageRequest self::find()'s row, or null when this request wasn't made from an offer
     * @return array{commission_offres_completes: string, total_a_payer_samchlolaure_offres_completes: string}
     */
    public static function commissionVariables(
        array $partner,
        ?array $packageRequest,
        float $accommodationPayoutToSamChloLaure,
        string $currency
    ): array {
        if ($packageRequest === null) {
            return [
                'commission_offres_completes' => '',
                'total_a_payer_samchlolaure_offres_completes' => '',
            ];
        }
        $commission = ((float) ($packageRequest['flight_total'] ?? 0) * (float) ($partner['packages_commission_flight_percent'] ?? 0) / 100)
            + ((float) ($packageRequest['transport_total'] ?? 0) * (float) ($partner['packages_commission_transport_percent'] ?? 0) / 100)
            + ((float) ($packageRequest['activity_total'] ?? 0) * (float) ($partner['packages_commission_activity_percent'] ?? 0) / 100)
            + ((float) ($packageRequest['meal_total'] ?? 0) * (float) ($partner['packages_commission_meal_percent'] ?? 0) / 100);
        $total = $accommodationPayoutToSamChloLaure + $commission;
        return [
            'commission_offres_completes' => ReservationsController::formatMoneyFr(round($commission, 2), $currency),
            'total_a_payer_samchlolaure_offres_completes' => ReservationsController::formatMoneyFr(round($total, 2), $currency),
        ];
    }

    /**
     * {{offre_total_a_payer_client}}: the full "sold as a whole" price of
     * the Offre Complète actually payable by the client — accommodation
     * (VAT/markup included, tourist tax excluded, same rule as
     * {{total_voyageur}}/{{tarif_total}}) + Vol + Transport + Activités +
     * Restauration, all snapshotted at submission time so this never drifts
     * if the offer's own prices are edited afterwards. Unlike
     * commissionVariables() above (partner-only, what SamChloLaure is owed),
     * this is client-facing: it never reveals any per-line price, only the
     * one all-in total, matching the "offer sold as whole" rule (see
     * {{offre_recap_bloc}}).
     *
     * @param array<string, mixed>|null $packageRequest self::find()'s row, or null when this request wasn't made from an offer
     * @param float $accommodationTotalTraveler the same {{total_voyageur}} figure buildQuoteVariables() computes for the accommodation share (tourist tax excluded)
     */
    public static function clientTotalVariable(?array $packageRequest, float $accommodationTotalTraveler, string $currency): array
    {
        if ($packageRequest === null) {
            return ['offre_total_a_payer_client' => ''];
        }
        $total = $accommodationTotalTraveler
            + (float) ($packageRequest['flight_total'] ?? 0)
            + (float) ($packageRequest['transport_total'] ?? 0)
            + (float) ($packageRequest['activity_total'] ?? 0)
            + (float) ($packageRequest['meal_total'] ?? 0);
        return ['offre_total_a_payer_client' => ReservationsController::formatMoneyFr(round($total, 2), $currency)];
    }


    /**
     * Partner ids whose package_requests appear on $partnerId's
     * /partner/offres/demandes page: itself, plus every hierarchical
     * principal (parent) that opted into partners.packages_force_child_visibility
     * (PartnerLinks::principalPartnerIds()). Unlike analytics scoping this is
     * opt-in on the parent's side, not automatic.
     *
     * @return int[]
     */
    public static function scopeIdsForPartner(int $partnerId): array
    {
        $ids = [$partnerId];
        if ($partnerId <= 0 || !Database::columnExists('partners', 'packages_force_child_visibility')) {
            return $ids;
        }
        $principalIds = PartnerLinks::principalPartnerIds($partnerId);
        if ($principalIds === []) {
            return $ids;
        }
        $placeholders = implode(',', array_fill(0, count($principalIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT id FROM partners WHERE id IN ({$placeholders}) AND packages_force_child_visibility = 1"
        );
        $stmt->execute($principalIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $forcedId) {
            $ids[] = (int) $forcedId;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $partnerIds
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    public static function listForPartnerIds(array $partnerIds, array $statuses = []): array
    {
        if ($partnerIds === [] || !self::tableReady()) {
            return [];
        }
        $conditions = [];
        $params = [];
        $placeholders = implode(',', array_fill(0, count($partnerIds), '?'));
        $conditions[] = "pr.partner_id IN ({$placeholders})";
        array_push($params, ...$partnerIds);
        $statuses = array_values(array_intersect($statuses, self::STATUSES));
        if ($statuses !== []) {
            $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
            $conditions[] = "pr.status IN ({$statusPlaceholders})";
            array_push($params, ...$statuses);
        }
        $where = implode(' AND ', $conditions);
        $stmt = Database::connection()->prepare(
            "SELECT pr.*, pk.title AS package_title, pk.photo_url AS package_photo_url, pt.name AS partner_name,
                    (SELECT COUNT(*) FROM reservation_requests rr WHERE rr.package_request_id = pr.id) AS request_count
             FROM package_requests pr
             LEFT JOIN packages pk ON pk.id = pr.package_id
             INNER JOIN partners pt ON pt.id = pr.partner_id
             WHERE {$where}
             ORDER BY pr.created_at DESC, pr.id DESC"
        );
        $stmt->execute($params);
        return array_map([self::class, 'decorate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Admin view: every partner's offer requests, optionally filtered by
     * partner and/or status.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(?int $partnerId = null, array $statuses = []): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $conditions = [];
        $params = [];
        if ($partnerId !== null && $partnerId > 0) {
            $conditions[] = 'pr.partner_id = ?';
            $params[] = $partnerId;
        }
        $statuses = array_values(array_intersect($statuses, self::STATUSES));
        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $conditions[] = "pr.status IN ({$placeholders})";
            array_push($params, ...$statuses);
        }
        $where = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $stmt = Database::connection()->prepare(
            "SELECT pr.*, pk.title AS package_title, pk.photo_url AS package_photo_url, pt.name AS partner_name,
                    (SELECT COUNT(*) FROM reservation_requests rr WHERE rr.package_request_id = pr.id) AS request_count
             FROM package_requests pr
             LEFT JOIN packages pk ON pk.id = pr.package_id
             INNER JOIN partners pt ON pt.id = pr.partner_id
             {$where}
             ORDER BY pr.created_at DESC, pr.id DESC"
        );
        $stmt->execute($params);
        return array_map([self::class, 'decorate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * A single offer request, restricted to the given scope of partner ids
     * (self::scopeIdsForPartner() for a partner user, or null for admin).
     *
     * @param int[]|null $partnerIds
     * @return array<string, mixed>|null
     */
    public static function find(int $id, ?array $partnerIds = null): ?array
    {
        if ($id <= 0 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT pr.*, pk.title AS package_title, pk.photo_url AS package_photo_url, pt.name AS partner_name
                FROM package_requests pr
                LEFT JOIN packages pk ON pk.id = pr.package_id
                INNER JOIN partners pt ON pt.id = pr.partner_id
                WHERE pr.id = ?';
        $params = [$id];
        if ($partnerIds !== null) {
            if ($partnerIds === []) {
                return null;
            }
            $placeholders = implode(',', array_fill(0, count($partnerIds), '?'));
            $sql .= " AND pr.partner_id IN ({$placeholders})";
            array_push($params, ...$partnerIds);
        }
        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decorate($row);
    }

    /**
     * Every reservation_requests row created from a given offer submission
     * (usually one, several for a same-address multi-property submission),
     * so the detail page can list them with a link to open each one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function reservationRequestsFor(int $id): array
    {
        if ($id <= 0 || !Database::columnExists('reservation_requests', 'package_request_id')) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, partner_id, client_name, client_email, property_name, checkin_date, checkout_date, status
             FROM reservation_requests WHERE package_request_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function updateStatus(int $id, string $status, ?array $partnerIds = null): bool
    {
        if (!in_array($status, self::STATUSES, true) || self::find($id, $partnerIds) === null) {
            return false;
        }
        $stmt = Database::connection()->prepare('UPDATE package_requests SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        return true;
    }

    public static function delete(int $id, ?array $partnerIds = null): bool
    {
        if (self::find($id, $partnerIds) === null) {
            return false;
        }
        $stmt = Database::connection()->prepare('DELETE FROM package_requests WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private static function decorate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['package_id'] = $row['package_id'] !== null ? (int) $row['package_id'] : null;
        $row['partner_id'] = (int) $row['partner_id'];
        $row['request_count'] = (int) ($row['request_count'] ?? 0);
        return $row;
    }

    public static function statusLabel(string $status): string
    {
        return [
            self::STATUS_OPEN => 'Ouverte',
            self::STATUS_VALIDATED => 'Validée',
            self::STATUS_CANCELLED => 'Annulée',
        ][$status] ?? $status;
    }
}
