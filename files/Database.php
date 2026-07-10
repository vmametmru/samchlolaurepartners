<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    /**
     * The DB connection is the one thing that cannot itself be stored in the
     * database (chicken-and-egg problem), so it is manually configured in
     * "db/config.php" (copy it from "db/config.example.php" and fill in your
     * real credentials). That file is git-ignored and blocked from direct
     * web access by "db/.htaccess". Every other setting (API keys, mail
     * defaults, etc.) lives in the "settings" table — see App\Settings.
     *
     * @return array<string, mixed>
     */
    private static function fileConfig(): array
    {
        $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/db/config.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'db/config.php is missing. Copy db/config.example.php to db/config.php and fill in ' .
                'your MySQL connection details.'
            );
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

        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }
        $port = isset($config['port']) ? (int) $config['port'] : 3306;
        $db = trim((string) ($config['name'] ?? ''));
        if ($db === '') {
            $db = 'partners_db';
        }
        $user = trim((string) ($config['user'] ?? ''));
        if ($user === '') {
            $user = 'partners_user';
        }
        $pass = (string) ($config['password'] ?? '');

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
