<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\I18n;
use PDO;
use Throwable;

final class EmailTemplatesController extends Controller
{
    public static function index(): never
    {
        $user = Auth::requireUser();
        $partnerId = ($user['role'] ?? '') === 'admin'
            ? (isset($_GET['partner_id']) ? (string) $_GET['partner_id'] : ($user['partner_id'] ?? null))
            : ($user['partner_id'] ?? null);
        $language = isset($_GET['language']) ? (string) $_GET['language'] : null;
        if ($language !== null && in_array($language, I18n::SUPPORTED, true)) {
            $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND language = ? ORDER BY type');
            $stmt->execute([$partnerId, $language]);
        } else {
            $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE partner_id = ? ORDER BY type, language');
            $stmt->execute([$partnerId]);
        }
        self::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function show(int $id): never
    {
        $user = Auth::requireUser();
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE id = ? AND partner_id = ? LIMIT 1');
        $stmt->execute([$id, $user['partner_id'] ?? null]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::json(['error' => 'Not Found', 'message' => 'Template not found'], 404);
        }
        self::json(['data' => $row]);
    }

    public static function create(): never
    {
        $user = Auth::requireUser();
        $input = self::input();
        if (trim((string) ($input['type'] ?? '')) === '' || trim((string) ($input['subject'] ?? '')) === '' || trim((string) ($input['body_html'] ?? '')) === '') {
            self::json(['error' => 'Bad Request', 'message' => 'type, subject, body_html are required'], 400);
        }
        try {
            $language = in_array((string) ($input['language'] ?? ''), I18n::SUPPORTED, true)
                ? (string) $input['language']
                : I18n::DEFAULT_LANGUAGE;
            $stmt = Database::connection()->prepare('INSERT INTO email_templates (partner_id, type, language, subject, body_html) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$user['partner_id'] ?? null, (string) $input['type'], $language, (string) $input['subject'], (string) $input['body_html']]);
            self::json(['data' => ['id' => (int) Database::connection()->lastInsertId()], 'message' => 'Template created'], 201);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to create template'], 500);
        }
    }

    public static function update(int $id): never
    {
        $user = Auth::requireUser();
        $input = self::input();
        try {
            $stmt = Database::connection()->prepare('UPDATE email_templates SET subject = ?, body_html = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?');
            $stmt->execute([(string) ($input['subject'] ?? ''), (string) ($input['body_html'] ?? ''), $id, $user['partner_id'] ?? null]);
            self::json(['data' => null, 'message' => 'Template updated']);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to update template'], 500);
        }
    }

    public static function delete(int $id): never
    {
        $user = Auth::requireUser();
        Database::connection()->prepare('DELETE FROM email_templates WHERE id = ? AND partner_id = ?')->execute([$id, $user['partner_id'] ?? null]);
        self::json(['data' => null, 'message' => 'Template deleted']);
    }

    public static function listForPartner(int $partnerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE partner_id = ? ORDER BY type');
        $stmt->execute([$partnerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
