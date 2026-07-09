#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = App\Scheduler::runOnce();
    echo '[scheduler] Checked: ' . $result['checked'] . ', sent: ' . $result['sent'] . PHP_EOL;
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, '[scheduler] ' . $error . PHP_EOL);
    }
    exit($result['errors'] === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[scheduler] Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
