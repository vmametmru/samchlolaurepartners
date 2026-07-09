<?php

declare(strict_types=1);

namespace App;

abstract class Controller
{
    protected static function input(): array
    {
        static $input;
        if (is_array($input)) {
            return $input;
        }

        $input = $_POST;
        $raw = file_get_contents('php://input');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if ($raw !== false && $raw !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        } elseif ($raw !== false && $raw !== '' && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['PUT', 'DELETE', 'PATCH'], true)) {
            parse_str($raw, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $input = $parsed;
            }
        }

        return $input;
    }

    protected static function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected static function redirect(string $path, ?string $flash = null, string $type = 'success'): never
    {
        if ($flash !== null) {
            Flash::set($flash, $type);
        }
        header('Location: ' . $path);
        exit;
    }

    protected static function requirePartnerContext(): array
    {
        $partner = Tenant::current();
        if (!$partner) {
            throw new HttpException(400, 'Bad Request', 'No partner context');
        }
        return $partner;
    }

    protected static function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json') || str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api/');
    }
}
