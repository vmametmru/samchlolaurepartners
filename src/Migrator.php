<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Migrator
{
    public static function run(): array
    {
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS db_migrations (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $dir = BASE_PATH . '/database/migrations';
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
