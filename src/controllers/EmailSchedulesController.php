<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use PDO;
use Throwable;

final class EmailSchedulesController extends Controller
{
    public static function index(): never
    {
        $user = Auth::requireUser();
        $partnerId = ($user['role'] ?? '') === 'admin'
            ? (isset($_GET['partner_id']) ? (string) $_GET['partner_id'] : ($user['partner_id'] ?? null))
            : ($user['partner_id'] ?? null);
        $stmt = Database::connection()->prepare('SELECT * FROM email_schedules WHERE partner_id = ? ORDER BY days_before_arrival');
        $stmt->execute([$partnerId]);
        self::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function create(): never
    {
        $user = Auth::requireUser();
        $input = self::input();
        if (!array_key_exists('days_before_arrival', $input) || trim((string) ($input['template_type'] ?? '')) === '') {
            self::json(['error' => 'Bad Request', 'message' => 'days_before_arrival and template_type are required'], 400);
        }
        try {
            $stmt = Database::connection()->prepare('INSERT INTO email_schedules (partner_id, days_before_arrival, template_type) VALUES (?, ?, ?)');
            $stmt->execute([$user['partner_id'] ?? null, (int) $input['days_before_arrival'], (string) $input['template_type']]);
            self::json(['data' => ['id' => (int) Database::connection()->lastInsertId()], 'message' => 'Schedule created'], 201);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to create schedule'], 500);
        }
    }

    public static function update(int $id): never
    {
        $user = Auth::requireUser();
        $input = self::input();
        try {
            $stmt = Database::connection()->prepare('UPDATE email_schedules SET days_before_arrival = ?, template_type = ?, active = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?');
            $stmt->execute([
                array_key_exists('days_before_arrival', $input) ? (int) $input['days_before_arrival'] : null,
                isset($input['template_type']) ? (string) $input['template_type'] : null,
                isset($input['active']) && ($input['active'] === false || $input['active'] === '0' || $input['active'] === 0) ? 0 : 1,
                $id,
                $user['partner_id'] ?? null,
            ]);
            self::json(['data' => null, 'message' => 'Schedule updated']);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to update schedule'], 500);
        }
    }

    public static function delete(int $id): never
    {
        $user = Auth::requireUser();
        Database::connection()->prepare('DELETE FROM email_schedules WHERE id = ? AND partner_id = ?')->execute([$id, $user['partner_id'] ?? null]);
        self::json(['data' => null, 'message' => 'Schedule deleted']);
    }

    public static function listForPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_schedules WHERE partner_id = ? ORDER BY days_before_arrival');
        $stmt->execute([$partnerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
