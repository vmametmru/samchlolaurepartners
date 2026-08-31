<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\HttpException;
use App\Mailer;
use App\Settings;
use App\Tenant;
use App\View;
use PDO;

final class AnalyticsController extends Controller
{
    /** All analytics times are displayed in Mauritius time (GMT+4). */
    private const TZ = 'Indian/Mauritius';
    private const TZ_OFFSET = '+04:00';

    // ── Visit tracking (called from JS beacon) ──────────────────────

    /**
     * POST /api/analytics/track
     * Records a page visit. Called by the JS tracking snippet on every
     * page load. Accepts JSON: {page_url, page_title, duration_seconds}.
     */
    public static function track(): never
    {
        $input = self::input();
        $pageUrl = trim((string) ($input['page_url'] ?? ''));
        if ($pageUrl === '') {
            self::json(['ok' => false], 400);
        }

        $user = Auth::user();
        $partner = Tenant::current();
        $partnerId = $partner ? (int) $partner['id'] : null;

        $visitorType = 'client';
        if ($user) {
            $role = (string) ($user['role'] ?? '');
            if ($role === 'admin') {
                $visitorType = 'admin';
            } elseif ($role === 'partner') {
                $visitorType = 'partner';
            }
        }

        $ip = self::clientIp();

        $pdo = Database::connection();
        if (!Database::tableExists('page_visits')) {
            self::json(['ok' => true]);
        }

        $country = self::countryFromIp($ip);

        $pdo->prepare(
            'INSERT INTO page_visits (partner_id, visitor_type, user_id, page_url, page_title, duration_seconds, country_code, country_name, ip_address, user_agent, referrer, session_id, visited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $partnerId,
            $visitorType,
            $user ? (int) $user['id'] : null,
            substr($pageUrl, 0, 500),
            substr(trim((string) ($input['page_title'] ?? '')), 0, 255) ?: null,
            isset($input['duration_seconds']) ? max(0, (int) $input['duration_seconds']) : null,
            $country['code'],
            $country['name'],
            $ip,
            substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500) ?: null,
            substr(trim((string) ($input['referrer'] ?? '')), 0, 500) ?: null,
            trim((string) ($input['session_id'] ?? '')) ?: null,
        ]);

        self::json(['ok' => true]);
    }

    /**
     * POST /api/analytics/track-duration
     * Updates the duration of the most recent visit for the same session/page.
     */
    public static function trackDuration(): never
    {
        $input = self::input();
        $sessionId = trim((string) ($input['session_id'] ?? ''));
        $pageUrl = substr(trim((string) ($input['page_url'] ?? '')), 0, 500);
        $duration = max(0, (int) ($input['duration_seconds'] ?? 0));

        if ($sessionId === '' || $pageUrl === '' || $duration <= 0) {
            self::json(['ok' => false], 400);
        }

        if (!Database::tableExists('page_visits')) {
            self::json(['ok' => true]);
        }

        Database::connection()->prepare(
            'UPDATE page_visits SET duration_seconds = ? WHERE session_id = ? AND page_url = ? ORDER BY visited_at DESC LIMIT 1'
        )->execute([$duration, $sessionId, $pageUrl]);

        self::json(['ok' => true]);
    }

    // ── Analytics page (admin + partner) ────────────────────────────

    /**
     * GET /admin/analytics  or  GET /partner/analytics
     */
    public static function page(): void
    {
        $user = Auth::requireUser();
        $role = (string) ($user['role'] ?? '');

        if ($role === 'admin') {
            self::adminAnalyticsPage($user);
        } elseif ($role === 'partner') {
            self::partnerAnalyticsPage($user);
        } else {
            throw new HttpException(403, 'Forbidden', 'Accès réservé.');
        }
    }

    private static function adminAnalyticsPage(array $user): void
    {
        if (!Database::tableExists('page_visits')) {
            View::render('pages/analytics', [
                'pageTitle' => 'Analyse',
                'isAdmin' => true,
                'partners' => [],
                'kpis' => self::emptyKpis(),
                'visitsByCountry' => [],
                'visitsByDate' => [],
                'visitsByPage' => [],
                'visitsByHour' => [],
                'visits' => [],
                'filters' => self::defaultFilters(),
                'reportSchedule' => null,
                'reportSchedules' => [],
            ]);
            return;
        }

        $pdo = Database::connection();
        $partners = $pdo->query('SELECT id, name, subdomain FROM partners WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

        $filters = self::readFilters();
        $where = self::buildWhereClause($filters);

        View::render('pages/analytics', [
            'pageTitle' => 'Analyse',
            'isAdmin' => true,
            'partners' => $partners,
            'kpis' => self::computeKpis($pdo, $where),
            'visitsByCountry' => self::visitsByCountry($pdo, $where),
            'visitsByDate' => self::visitsByDate($pdo, $where),
            'visitsByPage' => self::visitsByPage($pdo, $where),
            'visitsByHour' => self::visitsByHour($pdo, $where),
            'visits' => self::recentVisits($pdo, $where, 200),
            'filters' => $filters,
            'reportSchedule' => null,
            'reportSchedules' => self::getAllReportSchedules($pdo),
        ]);
    }

    private static function partnerAnalyticsPage(array $user): void
    {
        $partnerId = (int) ($user['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            throw new HttpException(403, 'Forbidden', 'Aucun partenaire associé.');
        }

        $pdo = Database::connection();
        $partner = $pdo->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
        $partner->execute([$partnerId]);
        $partnerRow = $partner->fetch(PDO::FETCH_ASSOC);

        if (!$partnerRow) {
            throw new HttpException(404, 'Not Found', 'Partenaire introuvable.');
        }

        // Check if analytics is visible for this partner
        $analyticsVisible = Database::columnExists('partners', 'analytics_visible')
            ? (int) ($partnerRow['analytics_visible'] ?? 0)
            : 0;
        if (!$analyticsVisible) {
            throw new HttpException(403, 'Forbidden', 'L\'accès aux analyses n\'est pas activé pour votre compte.');
        }

        if (!Database::tableExists('page_visits')) {
            View::render('pages/analytics', [
                'pageTitle' => 'Analyse',
                'isAdmin' => false,
                'partners' => [],
                'kpis' => self::emptyKpis(),
                'visitsByCountry' => [],
                'visitsByDate' => [],
                'visitsByPage' => [],
                'visitsByHour' => [],
                'visits' => [],
                'filters' => self::defaultFilters(),
                'reportSchedule' => self::getReportSchedule($pdo, $partnerId),
            ]);
            return;
        }

        $filters = self::readFilters();
        $filters['partner_id'] = (string) $partnerId; // Force partner scope
        $where = self::buildWhereClause($filters);
        // Partners must not see admin visits
        $where = self::excludeAdminVisits($where);

        $reportSchedule = self::getReportSchedule($pdo, $partnerId);

        View::render('pages/analytics', [
            'pageTitle' => 'Analyse',
            'isAdmin' => false,
            'partners' => [],
            'kpis' => self::computeKpis($pdo, $where),
            'visitsByCountry' => self::visitsByCountry($pdo, $where),
            'visitsByDate' => self::visitsByDate($pdo, $where),
            'visitsByPage' => self::visitsByPage($pdo, $where),
            'visitsByHour' => self::visitsByHour($pdo, $where),
            'visits' => self::recentVisits($pdo, $where, 200),
            'filters' => $filters,
            'reportSchedule' => $reportSchedule,
        ]);
    }

    // ── CSV Export ──────────────────────────────────────────────────

    /**
     * GET /admin/analytics/export  or  GET /partner/analytics/export
     */
    public static function exportCsv(): never
    {
        $user = Auth::requireUser();
        $role = (string) ($user['role'] ?? '');
        $filters = self::readFilters();

        if ($role === 'partner') {
            $partnerId = (int) ($user['partner_id'] ?? 0);
            if ($partnerId <= 0) {
                throw new HttpException(403, 'Forbidden', 'Accès refusé.');
            }
            self::checkPartnerAnalyticsVisible($partnerId);
            $filters['partner_id'] = (string) $partnerId;
        } elseif ($role !== 'admin') {
            throw new HttpException(403, 'Forbidden', 'Accès réservé.');
        }

        if (!Database::tableExists('page_visits')) {
            throw new HttpException(404, 'Not Found', 'Pas de données.');
        }

        $where = self::buildWhereClause($filters);
        if ($role === 'partner') {
            $where = self::excludeAdminVisits($where);
        }
        $pdo = Database::connection();
        $rows = self::recentVisits($pdo, $where, 10000);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="analytics-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel
        fputcsv($out, ['Date/Heure', 'Page', 'Type visiteur', 'Pays', 'Durée (s)', 'IP', 'Navigateur', 'Référent']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['visited_at'],
                $row['page_url'],
                $row['visitor_type'],
                $row['country_name'] ?: $row['country_code'],
                $row['duration_seconds'],
                $row['ip_address'],
                $row['user_agent'],
                $row['referrer'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── PDF Export ──────────────────────────────────────────────────

    /**
     * GET /admin/analytics/pdf  or  GET /partner/analytics/pdf
     */
    public static function exportPdf(): never
    {
        $user = Auth::requireUser();
        $role = (string) ($user['role'] ?? '');
        $filters = self::readFilters();
        $partnerId = null;

        if ($role === 'partner') {
            $partnerId = (int) ($user['partner_id'] ?? 0);
            if ($partnerId <= 0) {
                throw new HttpException(403, 'Forbidden', 'Accès refusé.');
            }
            self::checkPartnerAnalyticsVisible($partnerId);
            $filters['partner_id'] = (string) $partnerId;
        } elseif ($role !== 'admin') {
            throw new HttpException(403, 'Forbidden', 'Accès réservé.');
        }

        $pdfData = self::generatePdfReport($filters, $partnerId, $role === 'partner');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="rapport-analytics-' . date('Y-m-d') . '.pdf"');
        header('Content-Length: ' . strlen($pdfData));
        echo $pdfData;
        exit;
    }

    // ── Report schedule (partner saves) ─────────────────────────────

    /**
     * POST /partner/analytics/report-schedule
     */
    public static function saveReportSchedule(): never
    {
        $user = Auth::requireUser();
        if (($user['role'] ?? '') !== 'partner') {
            throw new HttpException(403, 'Forbidden', 'Accès partenaire requis.');
        }
        $partnerId = (int) ($user['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            throw new HttpException(403, 'Forbidden', 'Accès refusé.');
        }
        self::checkPartnerAnalyticsVisible($partnerId);

        $enabled = isset($_POST['report_enabled']) ? 1 : 0;
        $dayOfWeek = max(0, min(6, (int) ($_POST['report_day'] ?? 1)));
        $timeOfDay = trim((string) ($_POST['report_time'] ?? '08:00'));
        if (!preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
            $timeOfDay = '08:00';
        }

        $pdo = Database::connection();
        if (!Database::tableExists('analytics_report_schedules')) {
            self::redirect('/partner/analytics', 'Configuration sauvegardée.');
        }

        $pdo->prepare(
            'INSERT INTO analytics_report_schedules (partner_id, enabled, day_of_week, time_of_day)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), day_of_week = VALUES(day_of_week), time_of_day = VALUES(time_of_day)'
        )->execute([$partnerId, $enabled, $dayOfWeek, $timeOfDay]);

        self::redirect('/partner/analytics', 'Configuration du rapport automatique sauvegardée.');
    }

    /**
     * POST /admin/analytics/report-schedule
     * Admin configures report schedule for a specific partner.
     */
    public static function adminSaveReportSchedule(): never
    {
        Auth::requireUser(true);
        $partnerId = (int) ($_POST['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            self::redirect('/admin/analytics', 'Partenaire invalide.', 'error');
        }

        $enabled = isset($_POST['report_enabled']) ? 1 : 0;
        $dayOfWeek = max(0, min(6, (int) ($_POST['report_day'] ?? 1)));
        $timeOfDay = trim((string) ($_POST['report_time'] ?? '08:00'));
        if (!preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
            $timeOfDay = '08:00';
        }

        $pdo = Database::connection();
        if (Database::tableExists('analytics_report_schedules')) {
            $pdo->prepare(
                'INSERT INTO analytics_report_schedules (partner_id, enabled, day_of_week, time_of_day)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), day_of_week = VALUES(day_of_week), time_of_day = VALUES(time_of_day)'
            )->execute([$partnerId, $enabled, $dayOfWeek, $timeOfDay]);
        }

        self::redirect('/admin/analytics', 'Configuration du rapport automatique sauvegardée.');
    }

    // ── Admin toggle analytics_visible ──────────────────────────────

    /**
     * POST /admin/partners/{id}/analytics-toggle
     */
    public static function adminToggleAnalytics(int $partnerId): never
    {
        Auth::requireUser(true);
        if (Database::columnExists('partners', 'analytics_visible')) {
            $current = Database::connection()->prepare('SELECT analytics_visible FROM partners WHERE id = ?');
            $current->execute([$partnerId]);
            $row = $current->fetch(PDO::FETCH_ASSOC);
            $newValue = $row ? (((int) $row['analytics_visible']) === 1 ? 0 : 1) : 1;
            Database::connection()->prepare('UPDATE partners SET analytics_visible = ? WHERE id = ?')->execute([$newValue, $partnerId]);
        }
        self::redirect('/admin/partners/' . $partnerId . '/edit', 'Visibilité des analyses mise à jour.');
    }

    /**
     * POST /admin/analytics/{id}/delete
     * Delete a single analytics row.
     */
    public static function adminDeleteVisit(int $visitId): never
    {
        Auth::requireUser(true);
        if (Database::tableExists('page_visits')) {
            Database::connection()->prepare('DELETE FROM page_visits WHERE id = ?')->execute([$visitId]);
        }
        self::redirect('/admin/analytics', 'Entrée supprimée.');
    }

    /**
     * POST /admin/analytics/purge-partner
     * Delete ALL analytics for a given partner.
     */
    public static function adminPurgePartner(): never
    {
        Auth::requireUser(true);
        $partnerId = (int) ($_POST['partner_id'] ?? 0);
        if ($partnerId <= 0) {
            self::redirect('/admin/analytics', 'Partenaire invalide.', 'error');
        }
        if (Database::tableExists('page_visits')) {
            Database::connection()->prepare('DELETE FROM page_visits WHERE partner_id = ?')->execute([$partnerId]);
        }
        self::redirect('/admin/analytics', 'Toutes les données analytiques du partenaire ont été supprimées.');
    }

    /**
     * POST /admin/analytics/report-schedule/{id}/delete
     * Delete a report schedule entry.
     */
    public static function adminDeleteReportSchedule(int $scheduleId): never
    {
        Auth::requireUser(true);
        if (Database::tableExists('analytics_report_schedules')) {
            Database::connection()->prepare('DELETE FROM analytics_report_schedules WHERE id = ?')->execute([$scheduleId]);
        }
        self::redirect('/admin/analytics', 'Configuration de rapport supprimée.');
    }

    // ── Scheduler: send weekly reports ───────────────────────────────

    /**
     * Called from the cron scheduler. Checks all partner report schedules
     * and sends PDF reports to those whose day/time has arrived.
     */
    public static function sendScheduledReports(): array
    {
        $result = ['checked' => 0, 'sent' => 0, 'errors' => []];

        if (!Database::tableExists('analytics_report_schedules') || !Database::tableExists('page_visits')) {
            return $result;
        }

        $pdo = Database::connection();
        // Current time in GMT+4 (Mauritius)
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Indian/Mauritius'));
        $currentDay = (int) $now->format('w'); // 0=Sun..6=Sat
        $currentTime = $now->format('H:i');

        $stmt = $pdo->prepare(
            'SELECT ars.*, p.id AS p_id, p.name AS p_name, p.email AS p_email, p.logo_url AS p_logo_url,
                    p.subdomain, p.primary_color, p.smtp_host, p.smtp_port, p.smtp_user, p.smtp_pass
             FROM analytics_report_schedules ars
             JOIN partners p ON p.id = ars.partner_id
             WHERE ars.enabled = 1
               AND ars.day_of_week = ?
               AND ars.time_of_day <= ?
               AND (ars.last_sent_at IS NULL OR ars.last_sent_at < DATE_SUB(NOW(), INTERVAL 6 DAY))'
        );
        $stmt->execute([$currentDay, $currentTime]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['checked'] = count($schedules);

        foreach ($schedules as $schedule) {
            $partnerEmail = trim((string) ($schedule['p_email'] ?? ''));
            if ($partnerEmail === '') {
                continue;
            }

            try {
                $filters = self::defaultFilters();
                $filters['partner_id'] = (string) $schedule['partner_id'];
                $filters['date_from'] = $now->modify('-7 days')->format('Y-m-d');
                $filters['date_to'] = $now->format('Y-m-d');

                $pdfData = self::generatePdfReport($filters, (int) $schedule['partner_id']);

                $partnerRow = [
                    'name' => $schedule['p_name'],
                    'email' => $schedule['p_email'],
                    'smtp_host' => $schedule['smtp_host'] ?? null,
                    'smtp_port' => $schedule['smtp_port'] ?? null,
                    'smtp_user' => $schedule['smtp_user'] ?? null,
                    'smtp_pass' => $schedule['smtp_pass'] ?? null,
                ];

                $subject = 'Rapport d\'analyse hebdomadaire - ' . $schedule['p_name'];
                $html = '<p>Bonjour,</p><p>Veuillez trouver ci-joint votre rapport d\'analyse hebdomadaire.</p><p>Cordialement,<br>samchlolaurepartners</p>';

                Mailer::sendRawEmail(
                    $partnerRow,
                    $partnerEmail,
                    $subject,
                    $html,
                    [],
                    null,
                    [['filename' => 'rapport-analytics-' . date('Y-m-d') . '.pdf', 'data' => $pdfData, 'mime' => 'application/pdf']]
                );

                $pdo->prepare('UPDATE analytics_report_schedules SET last_sent_at = NOW() WHERE id = ?')
                    ->execute([(int) $schedule['id']]);

                $result['sent']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'Partner ' . $schedule['partner_id'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────

    private static function checkPartnerAnalyticsVisible(int $partnerId): void
    {
        if (!Database::columnExists('partners', 'analytics_visible')) {
            throw new HttpException(403, 'Forbidden', 'Analyses non disponibles.');
        }
        $stmt = Database::connection()->prepare('SELECT analytics_visible FROM partners WHERE id = ?');
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['analytics_visible'] !== 1) {
            throw new HttpException(403, 'Forbidden', 'L\'accès aux analyses n\'est pas activé pour votre compte.');
        }
    }

    private static function clientIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? substr($ip, 0, 45) : null;
    }

    /**
     * Best-effort country lookup from IP. Uses ip-api.com free tier
     * (45 req/min, no key needed). Falls back gracefully.
     */
    private static function countryFromIp(?string $ip): array
    {
        static $cache = [];
        $default = ['code' => null, 'name' => null];
        if ($ip === null || $ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return $default;
        }
        // Skip private/reserved IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $default;
        }
        if (array_key_exists($ip, $cache)) {
            return $cache[$ip];
        }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $response = @file_get_contents('https://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,country', false, $ctx);
            if ($response === false) {
                return $cache[$ip] = $default;
            }
            $data = json_decode($response, true);
            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                return $cache[$ip] = ['code' => (string) ($data['countryCode'] ?? ''), 'name' => (string) ($data['country'] ?? '')];
            }
        } catch (\Throwable) {
            // ignore
        }
        return $cache[$ip] = $default;
    }

    private static function defaultFilters(): array
    {
        return [
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
            'partner_id' => '',
            'visitor_type' => '',
            'country' => '',
            'page' => '',
        ];
    }

    private static function readFilters(): array
    {
        return [
            'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')))),
            'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-d'))),
            'partner_id' => trim((string) ($_GET['partner_id'] ?? '')),
            'visitor_type' => trim((string) ($_GET['visitor_type'] ?? '')),
            'country' => trim((string) ($_GET['country'] ?? '')),
            'page' => trim((string) ($_GET['page'] ?? '')),
        ];
    }

    /**
     * @return array{sql: string, params: array}
     */
    private static function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($filters['date_from'] !== '') {
            $conditions[] = 'pv.visited_at >= ?';
            $params[] = gmdate('Y-m-d H:i:s', strtotime($filters['date_from'] . ' 00:00:00') - 4 * 3600);
        }
        if ($filters['date_to'] !== '') {
            $conditions[] = 'pv.visited_at <= ?';
            $params[] = gmdate('Y-m-d H:i:s', strtotime($filters['date_to'] . ' 23:59:59') - 4 * 3600);
        }
        if ($filters['partner_id'] !== '') {
            $conditions[] = 'pv.partner_id = ?';
            $params[] = (int) $filters['partner_id'];
        }
        if ($filters['visitor_type'] !== '') {
            $conditions[] = 'pv.visitor_type = ?';
            $params[] = $filters['visitor_type'];
        }
        if ($filters['country'] !== '') {
            $conditions[] = '(pv.country_code = ? OR pv.country_name LIKE ?)';
            $params[] = $filters['country'];
            $params[] = '%' . $filters['country'] . '%';
        }
        if ($filters['page'] !== '') {
            $conditions[] = 'pv.page_url LIKE ?';
            $params[] = '%' . $filters['page'] . '%';
        }

        $sql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Adds a condition to exclude admin visits from the WHERE clause.
     * Used for partner views so they only see client and partner visits.
     */
    private static function excludeAdminVisits(array $where): array
    {
        if ($where['sql'] !== '') {
            $where['sql'] .= " AND pv.visitor_type != 'admin'";
        } else {
            $where['sql'] = "WHERE pv.visitor_type != 'admin'";
        }
        return $where;
    }

    private static function emptyKpis(): array
    {
        return [
            'total_visits' => 0,
            'unique_visitors' => 0,
            'client_visits' => 0,
            'partner_visits' => 0,
            'admin_visits' => 0,
            'avg_duration' => 0,
            'countries' => 0,
            'pages_viewed' => 0,
        ];
    }

    private static function computeKpis(PDO $pdo, array $where): array
    {
        $sql = "SELECT
            COUNT(*) AS total_visits,
            COUNT(DISTINCT COALESCE(pv.session_id, pv.ip_address)) AS unique_visitors,
            SUM(CASE WHEN pv.visitor_type = 'client' THEN 1 ELSE 0 END) AS client_visits,
            SUM(CASE WHEN pv.visitor_type = 'partner' THEN 1 ELSE 0 END) AS partner_visits,
            SUM(CASE WHEN pv.visitor_type = 'admin' THEN 1 ELSE 0 END) AS admin_visits,
            ROUND(AVG(pv.duration_seconds), 0) AS avg_duration,
            COUNT(DISTINCT pv.country_code) AS countries,
            COUNT(DISTINCT pv.page_url) AS pages_viewed
            FROM page_visits pv {$where['sql']}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: self::emptyKpis();
    }

    private static function visitsByCountry(PDO $pdo, array $where): array
    {
        $extra = "pv.country_code IS NOT NULL AND pv.country_code != ''";
        if ($where['sql'] !== '') {
            $sql = "SELECT pv.country_code, pv.country_name, COUNT(*) AS visits
                    FROM page_visits pv {$where['sql']} AND {$extra}
                    GROUP BY pv.country_code, pv.country_name
                    ORDER BY visits DESC LIMIT 20";
        } else {
            $sql = "SELECT pv.country_code, pv.country_name, COUNT(*) AS visits
                    FROM page_visits pv WHERE {$extra}
                    GROUP BY pv.country_code, pv.country_name
                    ORDER BY visits DESC LIMIT 20";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function visitsByDate(PDO $pdo, array $where): array
    {
        $sql = "SELECT DATE(CONVERT_TZ(pv.visited_at, '+00:00', '" . self::TZ_OFFSET . "')) AS visit_date,
                COUNT(*) AS total,
                SUM(CASE WHEN pv.visitor_type = 'client' THEN 1 ELSE 0 END) AS clients,
                SUM(CASE WHEN pv.visitor_type = 'partner' THEN 1 ELSE 0 END) AS partners,
                SUM(CASE WHEN pv.visitor_type = 'admin' THEN 1 ELSE 0 END) AS admins
                FROM page_visits pv {$where['sql']}
                GROUP BY visit_date ORDER BY visit_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function visitsByPage(PDO $pdo, array $where): array
    {
        $sql = "SELECT pv.page_url, pv.page_title,
                COALESCE(pa.name, '—') AS partner_name,
                COUNT(*) AS visits,
                ROUND(AVG(pv.duration_seconds), 0) AS avg_duration,
                SUM(CASE WHEN pv.visitor_type = 'client' THEN 1 ELSE 0 END) AS client_visits,
                SUM(CASE WHEN pv.visitor_type = 'partner' THEN 1 ELSE 0 END) AS partner_visits,
                SUM(CASE WHEN pv.visitor_type = 'admin' THEN 1 ELSE 0 END) AS admin_visits
                FROM page_visits pv
                LEFT JOIN partners pa ON pa.id = pv.partner_id
                {$where['sql']}
                GROUP BY pv.page_url, pv.page_title, pa.name ORDER BY visits DESC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function visitsByHour(PDO $pdo, array $where): array
    {
        $sql = "SELECT HOUR(CONVERT_TZ(pv.visited_at, '+00:00', '" . self::TZ_OFFSET . "')) AS visit_hour, COUNT(*) AS visits
                FROM page_visits pv {$where['sql']}
                GROUP BY visit_hour ORDER BY visit_hour ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function recentVisits(PDO $pdo, array $where, int $limit): array
    {
        $sql = "SELECT pv.*, CONVERT_TZ(pv.visited_at, '+00:00', '" . self::TZ_OFFSET . "') AS visited_at, COALESCE(pa.name, '—') AS partner_name FROM page_visits pv LEFT JOIN partners pa ON pa.id = pv.partner_id {$where['sql']} ORDER BY pv.visited_at DESC LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function getReportSchedule(PDO $pdo, int $partnerId): ?array
    {
        if (!Database::tableExists('analytics_report_schedules')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM analytics_report_schedules WHERE partner_id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function getAllReportSchedules(PDO $pdo): array
    {
        if (!Database::tableExists('analytics_report_schedules')) {
            return [];
        }
        return $pdo->query(
            'SELECT ars.*, p.name AS partner_name FROM analytics_report_schedules ars JOIN partners p ON p.id = ars.partner_id ORDER BY p.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── PDF generation ─────────────────────────────────────────────

    /**
     * Generates a PDF analytics report as raw bytes. Uses pure-PHP HTML-to-PDF
     * approach: renders an HTML document and wraps it in a basic PDF structure.
     * No external library needed.
     */
    public static function generatePdfReport(array $filters, ?int $partnerId = null, bool $excludeAdmin = false): string
    {
        if (!Database::tableExists('page_visits')) {
            return self::buildSimplePdf('Rapport d\'analyse', 'Aucune donnée disponible.');
        }

        $pdo = Database::connection();
        $where = self::buildWhereClause($filters);
        if ($excludeAdmin) {
            $where = self::excludeAdminVisits($where);
        }
        $kpis = self::computeKpis($pdo, $where);
        $visitsByCountry = self::visitsByCountry($pdo, $where);
        $visitsByPage = self::visitsByPage($pdo, $where);

        $partnerName = 'Toutes les données';
        $logoUrl = '';
        if ($partnerId !== null) {
            $stmt = $pdo->prepare('SELECT name, logo_url FROM partners WHERE id = ?');
            $stmt->execute([$partnerId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $partnerName = (string) $p['name'];
                $logoUrl = (string) ($p['logo_url'] ?? '');
            }
        }

        $dateRange = ($filters['date_from'] ?? '?') . ' — ' . ($filters['date_to'] ?? '?');

        // ── Build structured PDF with KPIs + tables ──
        $lines = [];

        // Title
        $lines[] = ['type' => 'title', 'text' => 'Rapport d\'analyse — ' . $partnerName];
        $lines[] = ['type' => 'meta', 'text' => 'Période : ' . $dateRange . '  ·  Généré le ' . (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('d/m/Y à H:i') . ' (GMT+4)'];
        $lines[] = ['type' => 'spacer'];

        // KPIs as a formatted block
        $lines[] = ['type' => 'section', 'text' => 'Indicateurs clés'];
        $kpiPairs = [
            ['Visites totales', (int) $kpis['total_visits']],
            ['Visiteurs uniques', (int) $kpis['unique_visitors']],
            ['Visites clients', (int) $kpis['client_visits']],
            ['Visites partenaires', (int) $kpis['partner_visits']],
            ['Visites admin', (int) $kpis['admin_visits']],
            ['Durée moyenne', (int) $kpis['avg_duration'] . 's'],
            ['Pays', (int) $kpis['countries']],
            ['Pages vues', (int) $kpis['pages_viewed']],
        ];
        foreach ($kpiPairs as [$label, $value]) {
            $lines[] = ['type' => 'kpi', 'label' => $label, 'value' => (string) $value];
        }
        $lines[] = ['type' => 'spacer'];

        // Visits by country table
        if ($visitsByCountry !== []) {
            $lines[] = ['type' => 'section', 'text' => 'Visites par pays'];
            $lines[] = ['type' => 'table_header', 'cols' => ['Pays', 'Visites']];
            foreach ($visitsByCountry as $row) {
                $lines[] = ['type' => 'table_row', 'cols' => [
                    (string) ($row['country_name'] ?: $row['country_code']),
                    (string) (int) $row['visits'],
                ]];
            }
            $lines[] = ['type' => 'spacer'];
        }

        // Visits by page table
        if ($visitsByPage !== []) {
            $lines[] = ['type' => 'section', 'text' => 'Pages les plus visitées'];
            $lines[] = ['type' => 'table_header', 'cols' => ['Page', 'Visites', 'Durée moy.', 'Clients', 'Partenaires']];
            foreach (array_slice($visitsByPage, 0, 20) as $row) {
                $pageLabel = (string) ($row['page_title'] ?: $row['page_url']);
                if (mb_strlen($pageLabel) > 45) {
                    $pageLabel = mb_substr($pageLabel, 0, 42) . '...';
                }
                $lines[] = ['type' => 'table_row', 'cols' => [
                    $pageLabel,
                    (string) (int) $row['visits'],
                    (int) ($row['avg_duration'] ?? 0) . 's',
                    (string) (int) $row['client_visits'],
                    (string) (int) $row['partner_visits'],
                ]];
            }
        }

        return self::buildStructuredPdf($lines, $partnerName, $logoUrl);
    }

    /**
     * Builds a structured PDF with KPIs, section headers, and tables.
     * Pure PHP — no external library.
     */
    private static function buildStructuredPdf(array $items, string $title, string $logoUrl = ''): string
    {
        $pageWidth = 595;
        $pageHeight = 842;
        $margin = 40;
        $rightEdge = $pageWidth - $margin;
        $usableWidth = $rightEdge - $margin;

        // Prepare logo image object if available
        $logoObjNum = null;
        $logoRaw = '';
        $logoW = 0;
        $logoH = 0;
        if ($logoUrl !== '') {
            $absPath = $logoUrl;
            if (str_starts_with($logoUrl, '/')) {
                $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
                $absPath = $basePath . $logoUrl;
            }
            if (is_file($absPath)) {
                $logoData = @file_get_contents($absPath);
                if ($logoData !== false) {
                    $info = @getimagesize($absPath);
                    if ($info !== false) {
                        $logoW = $info[0];
                        $logoH = $info[1];
                        $logoRaw = $logoData;
                    }
                }
            }
        }

        // Build all page content streams
        $pageStreams = [];
        $y = $pageHeight - $margin;
        $currentStream = '';
        $needNewPage = false;

        $esc = function (string $s): string {
            $s = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        };

        $checkSpace = function (float $needed) use (&$y, &$currentStream, &$pageStreams, &$needNewPage, $pageHeight, $margin) {
            if ($y - $needed < $margin) {
                $pageStreams[] = $currentStream;
                $currentStream = '';
                $y = $pageHeight - $margin;
                $needNewPage = true;
            }
        };

        // Logo at top center
        $logoPlaceholder = false;
        if ($logoRaw !== '' && $logoW > 0 && $logoH > 0) {
            $logoPlaceholder = true;
            $displayH = 50;
            $displayW = ($logoW / $logoH) * $displayH;
            if ($displayW > 180) {
                $displayW = 180;
                $displayH = ($logoH / $logoW) * $displayW;
            }
            $logoX = ($pageWidth - $displayW) / 2;
            $logoY = $y - $displayH;
            // Image will be placed via the Do command referencing /Im1
            $currentStream .= "q {$displayW} 0 0 {$displayH} {$logoX} {$logoY} cm /Im1 Do Q\n";
            $y -= $displayH + 10;
        }

        foreach ($items as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'title') {
                $checkSpace(30);
                $currentStream .= "BT /F1 18 Tf {$margin} {$y} Td (" . $esc($item['text']) . ") Tj ET\n";
                $y -= 24;
            } elseif ($type === 'meta') {
                $checkSpace(16);
                $currentStream .= "BT /F1 9 Tf 0.5 0.5 0.5 rg {$margin} {$y} Td (" . $esc($item['text']) . ") Tj 0 0 0 rg ET\n";
                $y -= 16;
            } elseif ($type === 'section') {
                $checkSpace(26);
                // Draw a line
                $currentStream .= "0.85 0.85 0.85 RG 1 w {$margin} " . ($y - 2) . " m {$rightEdge} " . ($y - 2) . " l S\n";
                $y -= 8;
                $currentStream .= "BT /F2 12 Tf 0.2 0.2 0.2 rg {$margin} {$y} Td (" . $esc($item['text']) . ") Tj 0 0 0 rg ET\n";
                $y -= 20;
            } elseif ($type === 'kpi') {
                $checkSpace(16);
                $valueStr = $item['value'];
                $labelStr = $item['label'];
                // Value in brand color, label in gray
                $currentStream .= "BT /F2 11 Tf 0.902 0.118 0.302 rg " . ($margin + 4) . " {$y} Td (" . $esc($valueStr) . ") Tj ET\n";
                $currentStream .= "BT /F1 10 Tf 0.4 0.4 0.4 rg " . ($margin + 80) . " {$y} Td (" . $esc($labelStr) . ") Tj 0 0 0 rg ET\n";
                $y -= 16;
            } elseif ($type === 'spacer') {
                $y -= 10;
            } elseif ($type === 'table_header') {
                $cols = $item['cols'];
                $colCount = count($cols);
                $colW = $usableWidth / $colCount;
                $checkSpace(20);
                // Header background
                $currentStream .= "0.96 0.96 0.96 rg {$margin} " . ($y - 4) . " {$usableWidth} 16 re f\n";
                $currentStream .= "0.85 0.85 0.85 RG 0.5 w {$margin} " . ($y - 4) . " {$usableWidth} 16 re S\n";
                foreach ($cols as $ci => $col) {
                    $cx = $margin + $ci * $colW + 4;
                    $currentStream .= "BT /F2 8 Tf 0.2 0.2 0.2 rg {$cx} " . ($y + 1) . " Td (" . $esc($col) . ") Tj 0 0 0 rg ET\n";
                }
                $y -= 18;
            } elseif ($type === 'table_row') {
                $cols = $item['cols'];
                $colCount = count($cols);
                $colW = $usableWidth / $colCount;
                $checkSpace(16);
                // Row bottom border
                $currentStream .= "0.9 0.9 0.9 RG 0.5 w {$margin} " . ($y - 2) . " m {$rightEdge} " . ($y - 2) . " l S\n";
                foreach ($cols as $ci => $col) {
                    $cx = $margin + $ci * $colW + 4;
                    $currentStream .= "BT /F1 8 Tf {$cx} " . ($y + 2) . " Td (" . $esc($col) . ") Tj ET\n";
                }
                $y -= 14;
            }
        }

        // Final page
        if ($currentStream !== '') {
            $pageStreams[] = $currentStream;
        }
        if ($pageStreams === []) {
            $pageStreams = ["BT /F1 12 Tf {$margin} 780 Td (Aucune donnee.) Tj ET\n"];
        }

        // ── Assemble PDF objects ──
        $objects = [];
        $objectOffsets = [];

        // Obj 1: Catalog
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        // Obj 3: Font Helvetica
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        // Obj 4: Font Helvetica-Bold
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        $nextObj = 5;

        // Logo image XObject (if any)
        if ($logoRaw !== '' && $logoW > 0) {
            $logoObjNum = $nextObj++;
            $info = @getimagesize($logoUrl !== '' && str_starts_with($logoUrl, '/') ? ((defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . $logoUrl) : $logoUrl);
            $bpc = 8;
            $cs = '/DeviceRGB';
            $filter = '';
            // Detect JPEG or PNG
            $isJpeg = (substr($logoRaw, 0, 2) === "\xff\xd8");
            if ($isJpeg) {
                $filter = '/DCTDecode';
                $streamData = $logoRaw;
            } else {
                // For PNG or other formats, try to convert to raw RGB via GD
                $img = @imagecreatefromstring($logoRaw);
                if ($img !== false) {
                    $w = imagesx($img);
                    $h = imagesy($img);
                    $logoW = $w;
                    $logoH = $h;
                    $raw = '';
                    for ($row = 0; $row < $h; $row++) {
                        for ($col = 0; $col < $w; $col++) {
                            $rgb = imagecolorat($img, $col, $row);
                            $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
                        }
                    }
                    imagedestroy($img);
                    $streamData = gzcompress($raw);
                    $filter = '/FlateDecode';
                } else {
                    $logoObjNum = null;
                    $streamData = '';
                }
            }
            if ($logoObjNum !== null) {
                $objects[$logoObjNum] = "{$logoObjNum} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH} /ColorSpace {$cs} /BitsPerComponent {$bpc} /Filter {$filter} /Length " . strlen($streamData) . " >>\nstream\n{$streamData}\nendstream\nendobj\n";
            }
        }

        // Page content objects + page objects
        $pageObjNums = [];
        foreach ($pageStreams as $stream) {
            $contentObj = $nextObj++;
            $pageObj = $nextObj++;
            $pageObjNums[] = $pageObj;

            $objects[$contentObj] = "{$contentObj} 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj\n";

            $resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>';
            if ($logoObjNum !== null) {
                $resources .= ' /XObject << /Im1 ' . $logoObjNum . ' 0 R >>';
            }
            $resources .= ' >>';

            $objects[$pageObj] = "{$pageObj} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents {$contentObj} 0 R /Resources {$resources} >>\nendobj\n";
        }

        // Obj 2: Pages
        $kids = implode(' ', array_map(static fn(int $n): string => $n . ' 0 R', $pageObjNums));
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count " . count($pageObjNums) . " >>\nendobj\n";

        // ── Serialize ──
        $pdf = "%PDF-1.4\n";
        ksort($objects);
        foreach ($objects as $num => $obj) {
            $objectOffsets[$num] = strlen($pdf);
            $pdf .= $obj;
        }

        $xrefOffset = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            if (isset($objectOffsets[$i])) {
                $pdf .= sprintf("%010d 00000 n \n", $objectOffsets[$i]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * Builds a minimal valid PDF containing the given text or HTML content.
     * This is a pure-PHP PDF builder — no external libraries required.
     * For HTML mode, the content is stored as a text rendering (stripped HTML).
     */
    private static function buildSimplePdf(string $title, string $content, bool $isHtml = false): string
    {
        if ($isHtml) {
            // Strip HTML to plain text for the PDF text stream
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</tr>', '</p>', '</div>', '</h1>', '</h2>', '</h3>'], "\n", $content));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
            $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
            $text = trim($text);
        } else {
            $text = $content;
        }

        // Wrap long lines
        $lines = explode("\n", $text);
        $wrappedLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 90) {
                $wrappedLines = array_merge($wrappedLines, str_split($line, 90));
            } else {
                $wrappedLines[] = $line;
            }
        }

        // Build PDF content stream
        $fontSize = 10;
        $titleSize = 16;
        $leading = $fontSize * 1.4;
        $pageWidth = 595; // A4
        $pageHeight = 842;
        $margin = 50;
        $usableHeight = $pageHeight - 2 * $margin;
        $maxLinesPerPage = (int) floor($usableHeight / $leading) - 2; // reserve for title

        // Split into pages
        $pages = [];
        $currentPage = [];
        $lineCount = 0;
        foreach ($wrappedLines as $line) {
            if ($lineCount >= $maxLinesPerPage) {
                $pages[] = $currentPage;
                $currentPage = [];
                $lineCount = 0;
            }
            $currentPage[] = $line;
            $lineCount++;
        }
        if ($currentPage !== []) {
            $pages[] = $currentPage;
        }
        if ($pages === []) {
            $pages = [['Aucune donnée.']];
        }

        // Build PDF objects
        $objects = [];
        $objectOffsets = [];

        // Object 1: Catalog
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Object 2: Pages (will be updated after page objects are built)
        $pageObjNums = [];
        $nextObj = 4; // 3 = font

        // Object 3: Font
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        foreach ($pages as $pageIndex => $pageLines) {
            $contentObjNum = $nextObj++;
            $pageObjNum = $nextObj++;
            $pageObjNums[] = $pageObjNum;

            // Build content stream
            $stream = "BT\n/F1 {$titleSize} Tf\n";
            $y = $pageHeight - $margin;
            if ($pageIndex === 0) {
                $escapedTitle = self::pdfEscapeString($title);
                $stream .= "{$margin} {$y} Td\n({$escapedTitle}) Tj\n";
                $y -= $titleSize * 1.5;
                $stream .= "0 " . (-$titleSize * 1.5) . " Td\n";
                $stream .= "/F1 {$fontSize} Tf\n";
            } else {
                $stream .= "{$margin} {$y} Td\n/F1 {$fontSize} Tf\n";
            }

            foreach ($pageLines as $i => $line) {
                $escapedLine = self::pdfEscapeString($line);
                if ($i === 0 && $pageIndex === 0) {
                    $stream .= "0 0 Td\n({$escapedLine}) Tj\n";
                } else {
                    $stream .= "0 " . (-$leading) . " Td\n({$escapedLine}) Tj\n";
                }
            }
            $stream .= "ET\n";

            $objects[$contentObjNum] = "{$contentObjNum} 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj\n";
            $objects[$pageObjNum] = "{$pageObjNum} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents {$contentObjNum} 0 R /Resources << /Font << /F1 3 0 R >> >> >>\nendobj\n";
        }

        // Object 2: Pages
        $kids = implode(' ', array_map(static fn(int $n): string => $n . ' 0 R', $pageObjNums));
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count " . count($pageObjNums) . " >>\nendobj\n";

        // Build the PDF file
        $pdf = "%PDF-1.4\n";
        ksort($objects);
        foreach ($objects as $num => $obj) {
            $objectOffsets[$num] = strlen($pdf);
            $pdf .= $obj;
        }

        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= max(array_keys($objects)); $i++) {
            if (isset($objectOffsets[$i])) {
                $pdf .= sprintf("%010d 00000 n \n", $objectOffsets[$i]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $pdf .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    private static function pdfEscapeString(string $str): string
    {
        // Convert to Latin-1 for Type1 font compatibility
        $str = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str) ?: $str;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
    }
}
