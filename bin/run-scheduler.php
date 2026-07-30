#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/files/bootstrap.php';

// Lodgify property fiche data (photos, description, ...) is no longer
// refreshed automatically by this cron job: it is synced manually only, via
// the "Synchroniser maintenant" button on /admin/sync (see
// PageController::adminSync() -> Scheduler::syncLodgify()). Prices and
// availability are cached for one hour per property/date range
// (LodgifyClient::getAvailability()/getRates()) and refreshed lazily on the
// next visit, so this cron only handles scheduled reservation emails plus a
// cleanup of expired cache rows.
try {
    $result = App\Scheduler::runOnce();
    echo '[scheduler] Checked: ' . $result['checked'] . ', sent: ' . $result['sent'] . PHP_EOL;
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, '[scheduler] ' . $error . PHP_EOL);
    }

    try {
        $purged = (new App\LodgifyClient())->purgeExpiredCache();
        echo '[scheduler] Expired Lodgify cache rows purged: ' . $purged . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[scheduler] Cache purge failed: ' . $e->getMessage() . PHP_EOL);
    }

    exit($result['errors'] === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[scheduler] Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
