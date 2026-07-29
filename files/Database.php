<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

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

    /** @var array<string, bool> */
    private static array $columnExistsCache = [];

    /**
     * Whether a table already has a given column, cached per request. Used
     * to guard reads of columns added by more recent migrations: Migrator::
     * autoRun() applies pending migrations on every request, but on shared
     * hosting an ALTER can fail (privileges/timing) and leave the migration
     * unapplied — referencing the column unconditionally would then turn
     * every affected page into a "Column not found" 500 instead of simply
     * falling back to the pre-migration behaviour.
     */
    public static function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (!array_key_exists($cacheKey, self::$columnExistsCache)) {
            try {
                $pdo = self::connection();
                $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote($column));
                self::$columnExistsCache[$cacheKey] = $stmt !== false && $stmt->fetch() !== false;
            } catch (Throwable $e) {
                self::$columnExistsCache[$cacheKey] = false;
            }
        }
        return self::$columnExistsCache[$cacheKey];
    }

    /** @var array<string, bool> */
    private static array $columnNullableCache = [];

    /**
     * Ensures a column allows NULL, self-healing it with an inline ALTER
     * TABLE when it doesn't. Used by write paths that legitimately need to
     * insert/update a NULL value (e.g. email_schedules.partner_id NULL =
     * "Tous les partenaires", see migration 033) but whose column may still
     * be NOT NULL on a live database where that migration failed to apply
     * (Migrator::autoRun() logs and swallows migration errors instead of
     * breaking the page — see index.php) — without this, saving would fail
     * with "SQLSTATE[23000] ... cannot be null" instead of self-healing.
     */
    public static function ensureColumnNullable(string $table, string $column, string $columnDefinition): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnNullableCache)) {
            return self::$columnNullableCache[$cacheKey];
        }

        $pdo = self::connection();
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote($column));
            $row = $stmt !== false ? $stmt->fetch() : false;
            $isNullable = $row !== false && ($row['Null'] ?? 'NO') === 'YES';
        } catch (Throwable $e) {
            return self::$columnNullableCache[$cacheKey] = false;
        }

        if (!$isNullable) {
            try {
                $pdo->exec('ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $column . ' ' . $columnDefinition);
                $isNullable = true;
            } catch (Throwable $e) {
                $isNullable = false;
            }
        }

        return self::$columnNullableCache[$cacheKey] = $isNullable;
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
