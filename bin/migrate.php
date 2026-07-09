#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/files/bootstrap.php';

try {
    $result = App\Migrator::run();
    foreach ($result['skipped'] as $file) {
        echo '[migrate] Skipping already applied: ' . $file . PHP_EOL;
    }
    foreach ($result['applied'] as $file) {
        echo '[migrate] Applied: ' . $file . PHP_EOL;
    }
    echo '[migrate] All migrations applied.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
