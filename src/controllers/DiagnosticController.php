<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Env;
use App\LodgifyClient;
use Throwable;

final class DiagnosticController extends Controller
{
    public static function run(): never
    {
        Auth::requireUser(true);
        $results = [];
        $results['database'] = Database::test();

        $client = new LodgifyClient();
        $key = Env::get('LODGIFY_API_KEY', '') ?? '';
        $base = Env::get('LODGIFY_BASE_URL', 'https://api.lodgify.com/v2') ?? 'https://api.lodgify.com/v2';
        if ($key === '') {
            $results['lodgify'] = ['ok' => false, 'error' => 'LODGIFY_API_KEY is not set'];
        } else {
            try {
                $sample = $client->getProperties();
                $results['lodgify'] = [
                    'ok' => true,
                    'http_status' => 200,
                    'property_count' => count($sample),
                    'response_keys' => ['items'],
                    'sample' => array_slice($sample, 0, 2),
                ];
            } catch (Throwable $e) {
                $results['lodgify'] = ['ok' => false, 'error' => $e->getMessage(), 'http_status' => null, 'response_body' => null];
            }
        }

        $results['cache'] = [
            'properties_cached' => Database::connection()->query("SELECT COUNT(*) FROM lodgify_cache WHERE cache_key = 'lodgify:properties' AND expires_at > NOW()")->fetchColumn() > 0,
            'keys_checked' => ['lodgify:properties'],
        ];
        $results['env'] = [
            'NODE_ENV' => Env::get('APP_ENV', 'production') ?? 'production',
            'PORT' => Env::get('PORT', '(not set)') ?? '(not set)',
            'LODGIFY_BASE_URL' => $base,
            'LODGIFY_API_KEY_SET' => $key !== '',
            'CORS_ORIGIN' => Env::get('CORS_ORIGIN', '(not set)') ?? '(not set)',
            'DB_HOST' => Env::get('DB_HOST', '(not set)') ?? '(not set)',
            'DB_NAME' => Env::get('DB_NAME', '(not set)') ?? '(not set)',
        ];

        self::json(['data' => $results]);
    }
}
