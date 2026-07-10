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

App\Env::load(App\Env::resolvePath(BASE_PATH . '/.env'));
if (!is_dir(BASE_PATH . '/files/storage/cache')) {
    @mkdir(BASE_PATH . '/files/storage/cache', 0775, true);
}
if (!is_dir(BASE_PATH . '/files/storage/logs')) {
    @mkdir(BASE_PATH . '/files/storage/logs', 0775, true);
}
