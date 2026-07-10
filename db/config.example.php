<?php

/**
 * Database connection configuration.
 *
 * Copy this file to "config.php" (same folder) and fill in your real
 * credentials. This is the only setting that lives outside the database:
 * every other setting (API keys, SMTP, JWT secret, etc.) is stored in the
 * "settings" MySQL table, but the DB connection itself obviously can't be —
 * no database-stored config can be read before the app can connect to the
 * database in the first place.
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
