<?php

declare(strict_types=1);

namespace App;

final class Env
{
    private static bool $loaded = false;
    private static ?string $resolvedPath = null;

    /**
     * Resolves the real .env path to use, in case the app root (BASE_PATH) is
     * deployed one or two levels below the actual domain docroot (e.g. the app
     * lives under /admin while a shared .env sits at the domain root).
     *
     * Tries $primaryPath first, then walks up parent directories looking for
     * a ".env" file, up to $maxLevelsUp levels. Returns the first path found,
     * or $primaryPath unchanged if none of the candidates exist.
     */
    public static function resolvePath(string $primaryPath, int $maxLevelsUp = 2): string
    {
        if (is_file($primaryPath)) {
            return $primaryPath;
        }

        $dir = dirname($primaryPath);
        for ($i = 0; $i < $maxLevelsUp; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // reached filesystem root
            }
            $candidate = $parent . '/.env';
            if (is_file($candidate)) {
                return $candidate;
            }
            $dir = $parent;
        }

        return $primaryPath;
    }

    public static function getResolvedPath(): ?string
    {
        return self::$resolvedPath;
    }

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_file($path)) {
            self::$loaded = true;
            self::$resolvedPath = null;
            return;
        }
        self::$resolvedPath = $path;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === '\'' && str_ends_with($value, '\'')))) {
                $value = substr($value, 1, -1);
            }
            $value = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $value);

            // Override when the inherited value is absent or blank.
            // Check both getenv() and $_ENV because PHP-FPM may not expose
            // server-level vars through getenv() after clear_env = yes.
            $current = getenv($key);
            $envCurrent = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : '';
            if (($current === false || $current === '') && $envCurrent === '') {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // $_ENV is always populated by load() and works reliably on PHP-FPM
        // regardless of variables_order or clear_env settings.
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        return (int) (self::get($key) ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) self::get($key, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
