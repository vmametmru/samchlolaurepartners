<?php

declare(strict_types=1);

namespace App;

final class Tenant
{
    /**
     * Cookie holding the "Code Partenaire" a visitor typed in on the homepage. This app
     * does not use subdomains for tenant routing: every partner is served from the same
     * host, and the active partner is always resolved from this cookie (never parsed out
     * of the request Host header).
     */
    private const CODE_COOKIE = 'partner_code';

    public static function current(): ?array
    {
        static $partner;
        static $resolved = false;

        if ($resolved) {
            return $partner;
        }
        $resolved = true;

        return $partner = self::fromCode();
    }

    /**
     * Validates a partner code typed on the homepage form and, if valid, returns the
     * matching active partner so the caller can set the code cookie.
     */
    public static function resolveByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        return self::lookupByCode($code);
    }

    /**
     * Sets the partner code cookie for the current browser session only (no
     * "expires"): the code must be re-entered (or re-asserted via the "#/code"
     * URL fragment, see assets/js/app.js) the next time the visitor opens the
     * site, instead of the partner staying "remembered" for weeks.
     */
    public static function setCodeCookie(string $code): void
    {
        setcookie(self::CODE_COOKIE, $code, [
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isSecureRequest(),
        ]);
        $_COOKIE[self::CODE_COOKIE] = $code;
    }

    private static function fromCode(): ?array
    {
        $code = $_COOKIE[self::CODE_COOKIE] ?? '';
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            return null;
        }
        return self::lookupByCode($code);
    }

    private static function lookupByCode(string $code): ?array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE subdomain = ? AND active = 1 LIMIT 1');
            $stmt->execute([$code]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isSecureRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto !== '') {
            return explode(',', $proto)[0] === 'https';
        }
        return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on';
    }

    public static function currentPublic(): ?array
    {
        $partner = self::current();
        if (!$partner) {
            return null;
        }
        unset($partner['smtp_host'], $partner['smtp_port'], $partner['smtp_user'], $partner['smtp_pass'], $partner['markup_percent']);
        return $partner;
    }
}
