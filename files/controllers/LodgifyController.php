<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\LodgifyClient;
use PDO;
use Throwable;

final class LodgifyController extends Controller
{
    public static function properties(): never
    {
        try {
            $client = new LodgifyClient();
            self::json(['data' => $client->getProperties()]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to fetch properties'], 500);
        }
    }

    public static function property(int $id): never
    {
        try {
            $client = new LodgifyClient();
            self::json(['data' => $client->getProperty($id)]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to fetch property'], 500);
        }
    }

    public static function availability(int $id): never
    {
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        if (!$from || !$to) {
            self::json(['error' => 'Bad Request', 'message' => 'from and to query params are required'], 400);
        }
        try {
            $client = new LodgifyClient();
            self::json(['data' => $client->getAvailability($id, (string) $from, (string) $to)]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to fetch availability'], 500);
        }
    }

    public static function rates(int $id): never
    {
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $guests = (int) ($_GET['guests'] ?? 2);
        if (!$from || !$to) {
            self::json(['error' => 'Bad Request', 'message' => 'from and to query params are required'], 400);
        }

        try {
            $client = new LodgifyClient();
            $rawRates = $client->getRates($id, (string) $from, (string) $to, $guests);
            $user = Auth::user();
            $markup = 0.0;
            if (!empty($user['partner_id'])) {
                $stmt = Database::connection()->prepare('SELECT markup_percent FROM partners WHERE id = ? LIMIT 1');
                $stmt->execute([(int) $user['partner_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $markup = (float) $row['markup_percent'];
                }
            }
            $rates = array_map(static function (array $rate) use ($markup): array {
                $markedUp = round(((float) $rate['price_per_night']) * (1 + $markup / 100), 2);
                return [
                    'date_from' => $rate['date_from'],
                    'date_to' => $rate['date_to'],
                    'currency' => $rate['currency'],
                    'price_per_night' => $markedUp,
                    'price_per_night_with_markup' => $markedUp,
                    'markup_percent' => $markup,
                ];
            }, $rawRates);
            self::json(['data' => $rates]);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to fetch rates'], 500);
        }
    }

    public static function sync(): never
    {
        Auth::requireUser(true);
        try {
            $client = new LodgifyClient();
            $client->invalidate('lodgify:');
            $client->getProperties();
            self::json(['data' => null, 'message' => 'Lodgify cache cleared and refreshed']);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Sync failed'], 500);
        }
    }
}
