<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use PDO;
use Throwable;

final class VersionsController extends Controller
{
    public static function index(): never
    {
        Auth::requireUser(true);
        $rows = Database::connection()->query('SELECT * FROM app_versions ORDER BY deployed_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        self::json(['data' => $rows]);
    }

    public static function deploy(): never
    {
        $user = Auth::requireUser(true);
        $input = self::input();
        if (trim((string) ($input['version'] ?? '')) === '') {
            self::json(['error' => 'Bad Request', 'message' => 'version is required'], 400);
        }
        try {
            Database::connection()->prepare('INSERT INTO app_versions (version, deployed_by, notes) VALUES (?, ?, ?)')->execute([(string) $input['version'], (string) ($user['email'] ?? 'system'), ($input['notes'] ?? null) !== '' ? (string) ($input['notes'] ?? null) : null]);
            self::json(['data' => ['id' => (int) Database::connection()->lastInsertId()], 'message' => 'Version ' . $input['version'] . ' deployed'], 201);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to record deployment'], 500);
        }
    }

    public static function rollback(): never
    {
        Auth::requireUser(true);
        $input = self::input();
        if (empty($input['version_id'])) {
            self::json(['error' => 'Bad Request', 'message' => 'version_id is required'], 400);
        }
        try {
            Database::connection()->prepare('UPDATE app_versions SET rolled_back_at = NOW() WHERE id = ?')->execute([(int) $input['version_id']]);
            self::json(['data' => null, 'message' => 'Rollback recorded']);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to rollback'], 500);
        }
    }

    public static function migrations(): never
    {
        Auth::requireUser(true);
        $rows = Database::connection()->query('SELECT * FROM db_migrations ORDER BY applied_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        self::json(['data' => $rows]);
    }
}
