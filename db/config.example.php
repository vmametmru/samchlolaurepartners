<?php

/**
 * Manual database connection override.
 *
 * Copy this file to "config.php" (same folder) and fill in your real
 * credentials to make the app read the DB connection from here instead of
 * the ".env" file. This is entirely optional: if "db/config.php" does not
 * exist, the app falls back to the DB_HOST / DB_PORT / DB_NAME / DB_USER /
 * DB_PASSWORD values from ".env".
 *
 * "db/config.php" is git-ignored and directly denied by "db/.htaccess", so
 * it is never committed to the repository nor reachable over HTTP.
 */

declare(strict_types=1);

return [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'partners_db',
    'user' => 'partners_user',
    'password' => 'partners_pass',
];
