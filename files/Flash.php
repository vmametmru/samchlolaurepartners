<?php

declare(strict_types=1);

namespace App;

final class Flash
{
    public static function set(string $message, string $type = 'success'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
    }

    public static function pull(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : null;
    }
}
