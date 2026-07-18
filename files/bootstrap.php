<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = BASE_PATH . '/files/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (!is_dir(BASE_PATH . '/files/storage/cache')) {
    @mkdir(BASE_PATH . '/files/storage/cache', 0775, true);
}
if (!is_dir(BASE_PATH . '/files/storage/logs')) {
    @mkdir(BASE_PATH . '/files/storage/logs', 0775, true);
}

// images/logo, images/listings and images/others are gitignored (only kept
// via .gitkeep) so the app can serve/write uploads there, but some FTP
// clients and cPanel file managers silently drop empty directories / hidden
// dotfiles when uploading a deployment package, leaving these directories
// missing on the server. When that happens, ImageCache::cache() and the
// admin logo/avatar uploaders fall back to the original remote URL (or
// fail outright) on every request instead of caching locally, defeating the
// whole point of the local image cache. Recreate them here on every request,
// the same way the files/storage/* runtime directories are self-healed above.
foreach (['images/logo', 'images/listings', 'images/others'] as $imageDir) {
    if (!is_dir(BASE_PATH . '/' . $imageDir)) {
        @mkdir(BASE_PATH . '/' . $imageDir, 0775, true);
    }
}
