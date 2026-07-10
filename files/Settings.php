<?php

declare(strict_types=1);

namespace App;

use PDOException;
use Throwable;

/**
 * All application configuration (API keys, mail defaults, cookie domain, etc.)
 * lives in the "settings" table in MySQL, not in a ".env" file. The only thing
 * still supplied outside the database is the DB connection itself (see
 * "db/config.php"), since no database-stored setting can be read before the
 * app can connect to the database in the first place.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([$key, $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /**
     * Forces the in-memory cache to be reloaded from the database on the next access.
     */
    public static function reload(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        try {
            $rows = Database::connection()->query('SELECT `key`, `value` FROM settings')->fetchAll();
            foreach ($rows as $row) {
                self::$cache[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        } catch (PDOException|Throwable) {
            // The "settings" table may not exist yet (e.g. before migrations have
            // run, or during install). Fall back to an empty config rather than
            // crashing the whole request.
            self::$cache = [];
        }

        return self::$cache;
    }
}
