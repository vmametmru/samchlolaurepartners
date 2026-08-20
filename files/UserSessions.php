<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Tracks browser login sessions for the admin "qui est connecté" dashboard
 * panel (see PageController::adminPartners() / admin-partners.php) and the
 * per-user connection history dialog. Auth itself stays fully stateless
 * (JWT-only, see Auth.php) — this table is purely observational, it never
 * gates access, only records it, keyed by a random session id ("sid")
 * embedded in the JWT payload at issuance (Auth::establishSession()).
 *
 * "Currently online" is derived, not stored: a session counts as active
 * while it has no ended_at AND its last_seen_at is within the same
 * inactivity window Auth::verifyToken() itself enforces, so a session
 * never shows as "online" once its cookie would already be rejected.
 */
final class UserSessions
{
    // Mirrors Auth's own (private) INACTIVITY_SECONDS constant — kept in
    // sync manually since a session can only ever be "online" while its JWT
    // would still be accepted by Auth::verifyToken().
    private const ONLINE_WINDOW_SECONDS = 7200;

    public static function start(int $userId, string $sessionId, ?string $ip, ?string $userAgent): void
    {
        Database::connection()
            ->prepare(
                'INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, started_at, last_seen_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())'
            )
            ->execute([$userId, $sessionId, $ip, $userAgent !== null ? substr($userAgent, 0, 255) : null]);
    }

    /**
     * Heartbeat called whenever a valid cookie-based request refreshes its
     * token (see Auth::user()/requireUser()). The WHERE clause throttles
     * writes to at most once per minute per session so ordinary browsing
     * doesn't generate a write on every single page load.
     */
    public static function touch(string $sessionId): void
    {
        Database::connection()
            ->prepare(
                'UPDATE user_sessions SET last_seen_at = NOW()
                 WHERE session_id = ? AND ended_at IS NULL AND last_seen_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)'
            )
            ->execute([$sessionId]);
    }

    public static function end(string $sessionId, string $reason): void
    {
        Database::connection()
            ->prepare('UPDATE user_sessions SET ended_at = NOW(), ended_reason = ? WHERE session_id = ? AND ended_at IS NULL')
            ->execute([$reason, $sessionId]);
    }

    /**
     * One row per user (admin + partner accounts), most recently active
     * first — feeds the "qui est connecté" dashboard panel: an `online`
     * flag plus the most recent activity timestamp for offline users.
     *
     * @return array<int, array{id:int, email:string, first_name:?string, last_name:?string, role:string, partner_name:?string, last_seen_at:?string, online:bool}>
     */
    public static function overview(): array
    {
        $stmt = Database::connection()->query(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.role, p.name AS partner_name,
                    MAX(s.last_seen_at) AS last_seen_at,
                    MAX(CASE WHEN s.ended_at IS NULL THEN s.last_seen_at ELSE NULL END) AS active_last_seen_at
             FROM users u
             LEFT JOIN partners p ON p.id = u.partner_id
             LEFT JOIN user_sessions s ON s.user_id = u.id
             GROUP BY u.id, u.email, u.first_name, u.last_name, u.role, p.name
             ORDER BY (MAX(s.last_seen_at) IS NULL) ASC, MAX(s.last_seen_at) DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cutoff = time() - self::ONLINE_WINDOW_SECONDS;
        foreach ($rows as &$row) {
            $activeLastSeen = $row['active_last_seen_at'] !== null ? strtotime((string) $row['active_last_seen_at']) : null;
            $row['online'] = $activeLastSeen !== null && $activeLastSeen >= $cutoff;
            unset($row['active_last_seen_at']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Full connection history for one user (most recent first), each row
     * carrying its computed connected duration in seconds — still-open
     * sessions are timed up through now — used by the history dialog
     * opened by clicking a name in the "qui est connecté" panel.
     *
     * @return array<int, array{started_at:string, ended_at:?string, ended_reason:?string, ip_address:?string, user_agent:?string, duration_seconds:int, online:bool}>
     */
    public static function historyForUser(int $userId, int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT started_at, last_seen_at, ended_at, ended_reason, ip_address, user_agent
             FROM user_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cutoff = time() - self::ONLINE_WINDOW_SECONDS;
        foreach ($rows as &$row) {
            $startedAt = (int) strtotime((string) $row['started_at']);
            $lastSeen = $row['last_seen_at'] !== null ? (int) strtotime((string) $row['last_seen_at']) : $startedAt;
            $endedAt = $row['ended_at'] !== null ? (int) strtotime((string) $row['ended_at']) : null;
            $row['online'] = $endedAt === null && $lastSeen >= $cutoff;
            $endMoment = $endedAt ?? ($row['online'] ? time() : $lastSeen);
            $row['duration_seconds'] = max(0, $endMoment - $startedAt);
        }
        unset($row);
        return $rows;
    }
}
