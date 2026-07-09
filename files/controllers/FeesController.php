<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use PDO;
use Throwable;

final class FeesController extends Controller
{
    public static function cleaning(): never
    {
        Auth::requireUser(true);
        $rows = Database::connection()->query('SELECT * FROM cleaning_fees ORDER BY property_id')->fetchAll(PDO::FETCH_ASSOC);
        self::json(['data' => $rows]);
    }

    public static function updateCleaning(string $propertyId): never
    {
        Auth::requireUser(true);
        $input = self::input();
        if (!array_key_exists('per_person_per_night', $input)) {
            self::json(['error' => 'Bad Request', 'message' => 'per_person_per_night is required'], 400);
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO cleaning_fees (property_id, per_person_per_night)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE per_person_per_night = VALUES(per_person_per_night), updated_at = NOW()'
            )->execute([$propertyId === 'default' ? null : $propertyId, (float) $input['per_person_per_night']]);
            self::json(['data' => null, 'message' => 'Cleaning fee updated']);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to update cleaning fee'], 500);
        }
    }

    public static function touristTax(): never
    {
        Auth::requireUser(true);
        $row = Database::connection()->query('SELECT * FROM tourist_tax LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        self::json(['data' => $row]);
    }

    public static function updateTouristTax(): never
    {
        Auth::requireUser(true);
        $input = self::input();
        try {
            Database::connection()->prepare(
                'INSERT INTO tourist_tax (id, per_person_per_night, applies_to_foreigners_only, applies_to_children)
                 VALUES (1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE per_person_per_night = VALUES(per_person_per_night), applies_to_foreigners_only = VALUES(applies_to_foreigners_only), applies_to_children = VALUES(applies_to_children), updated_at = NOW()'
            )->execute([
                is_numeric($input['per_person_per_night'] ?? null) ? (float) $input['per_person_per_night'] : 0,
                isset($input['applies_to_foreigners_only']) && ($input['applies_to_foreigners_only'] === true || $input['applies_to_foreigners_only'] === '1' || $input['applies_to_foreigners_only'] === 1) ? 1 : 0,
                isset($input['applies_to_children']) && ($input['applies_to_children'] === true || $input['applies_to_children'] === '1' || $input['applies_to_children'] === 1) ? 1 : 0,
            ]);
            self::json(['data' => null, 'message' => 'Tourist tax updated']);
        } catch (Throwable $e) {
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to update tourist tax'], 500);
        }
    }
}
