<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::int('DB_PORT', 3306);
        $db = Env::get('DB_NAME', 'partners_db');
        $user = Env::get('DB_USER', 'partners_user');
        $pass = Env::get('DB_PASSWORD', 'partners_pass');

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
