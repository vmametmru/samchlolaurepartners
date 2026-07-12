<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Migrator
{
    /**
     * Automatically applies any pending SQL migration on every request so the
     * live site never breaks with "Column not found" / "Table doesn't exist"
     * errors just because a deploy forgot (or was unable, on shared hosting
     * without shell access) to run `php bin/migrate.php` after new migration
     * files were uploaded. Each migration is only ever applied once (tracked
     * in the db_migrations table), and the check itself is throttled with a
     * small on-disk marker so it doesn't hit the database on every single
     * request.
     */
    public static function autoRun(int $throttleSeconds = 60): array
    {
        $marker = BASE_PATH . '/files/storage/cache/migrations-last-check.txt';
        $lastCheck = is_file($marker) ? (int) file_get_contents($marker) : 0;
        if ($lastCheck > 0 && (time() - $lastCheck) < $throttleSeconds) {
            return ['applied' => [], 'skipped' => []];
        }

        @file_put_contents($marker, (string) time());

        return self::run();
    }

    public static function run(): array
    {
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS db_migrations (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $dir = BASE_PATH . '/db/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        $skipped = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $check = $pdo->prepare('SELECT id FROM db_migrations WHERE filename = ? LIMIT 1');
            $check->execute([$filename]);
            if ($check->fetch()) {
                $skipped[] = $filename;
                continue;
            }

            $sql = (string) file_get_contents($file);
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO db_migrations (filename) VALUES (?)')->execute([$filename]);
            $applied[] = $filename;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}
