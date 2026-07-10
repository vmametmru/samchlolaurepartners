<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    /**
     * Optional manual override: if "db/config.php" exists, it takes
     * precedence over the ".env" values. See "db/config.example.php" for
     * the expected format. The file is git-ignored and blocked from direct
     * web access by "db/.htaccess".
     *
     * @return array<string, mixed>
     */
    private static function fileConfig(): array
    {
        $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/db/config.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = self::fileConfig();

        $host = trim((string) ($config['host'] ?? Env::get('DB_HOST') ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }
        $port = isset($config['port']) ? (int) $config['port'] : Env::int('DB_PORT', 3306);
        $db = trim((string) ($config['name'] ?? Env::get('DB_NAME') ?? ''));
        if ($db === '') {
            $db = 'partners_db';
        }
        $user = trim((string) ($config['user'] ?? Env::get('DB_USER') ?? ''));
        if ($user === '') {
            $user = 'partners_user';
        }
        $pass = (string) ($config['password'] ?? Env::get('DB_PASSWORD', 'partners_pass'));

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
        self::$instance = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$instance;
    }

    public static function reconnect(): void
    {
        self::$instance = null;
    }

    public static function test(): array
    {
        try {
            self::connection()->query('SELECT 1');
            return ['ok' => true];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
