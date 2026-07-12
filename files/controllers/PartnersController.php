<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Tenant;
use PDO;
use PDOException;

final class PartnersController extends Controller
{
    public static function index(): never
    {
        Auth::requireUser(true);
        $rows = Database::connection()->query('SELECT id, subdomain, name, logo_url, primary_color, email, markup_percent, cleaning_fee_per_person_per_night, active, created_at, updated_at FROM partners ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        self::json(['data' => $rows]);
    }

    public static function current(): never
    {
        $partner = Tenant::currentPublic();
        if (!$partner) {
            self::json(['error' => 'Not Found', 'message' => 'No partner context'], 404);
        }
        self::json(['data' => $partner]);
    }

    public static function show(int $id): never
    {
        Auth::requireUser(true);
        $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::json(['error' => 'Not Found', 'message' => 'Partner not found'], 404);
        }
        self::json(['data' => $row]);
    }

    public static function create(): never
    {
        Auth::requireUser(true);
        $input = self::input();
        if (trim((string) ($input['subdomain'] ?? '')) === '' || trim((string) ($input['name'] ?? '')) === '' || trim((string) ($input['email'] ?? '')) === '') {
            self::json(['error' => 'Bad Request', 'message' => 'subdomain, name, email are required'], 400);
        }

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, cleaning_fee_per_person_per_night, smtp_host, smtp_port, smtp_user, smtp_pass)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (string) $input['subdomain'],
                (string) $input['name'],
                self::nullableString($input['logo_url'] ?? null),
                self::nullableString($input['primary_color'] ?? '#E61E4D') ?? '#E61E4D',
                (string) $input['email'],
                self::decimal($input['markup_percent'] ?? 0),
                self::decimal($input['cleaning_fee_per_person_per_night'] ?? 0),
                self::nullableString($input['smtp_host'] ?? null),
                self::nullableInt($input['smtp_port'] ?? null),
                self::nullableString($input['smtp_user'] ?? null),
                self::nullableString($input['smtp_pass'] ?? null),
            ]);
            self::json(['data' => ['id' => (int) Database::connection()->lastInsertId()], 'message' => 'Partner created'], 201);
        } catch (PDOException $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to create partner'], 500);
        }
    }

    public static function update(int $id): never
    {
        Auth::requireUser(true);
        $input = self::input();
        try {
            $stmt = Database::connection()->prepare(
                'UPDATE partners SET name = ?, logo_url = ?, primary_color = ?, email = ?, markup_percent = ?, cleaning_fee_per_person_per_night = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, active = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([
                self::nullableString($input['name'] ?? null),
                self::nullableString($input['logo_url'] ?? null),
                self::nullableString($input['primary_color'] ?? null),
                self::nullableString($input['email'] ?? null),
                self::decimal($input['markup_percent'] ?? 0),
                self::decimal($input['cleaning_fee_per_person_per_night'] ?? 0),
                self::nullableString($input['smtp_host'] ?? null),
                self::nullableInt($input['smtp_port'] ?? null),
                self::nullableString($input['smtp_user'] ?? null),
                self::nullableString($input['smtp_pass'] ?? null),
                isset($input['active']) && ($input['active'] === false || $input['active'] === '0' || $input['active'] === 0) ? 0 : 1,
                $id,
            ]);
            self::json(['data' => null, 'message' => 'Partner updated']);
        } catch (PDOException $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to update partner'], 500);
        }
    }

    public static function delete(int $id): never
    {
        Auth::requireUser(true);
        Database::connection()->prepare('UPDATE partners SET active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        self::json(['data' => null, 'message' => 'Partner deactivated']);
    }

    public static function formData(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
