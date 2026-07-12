<?php

declare(strict_types=1);

namespace App;

final class Tenant
{
    /**
     * Cookie holding the "Code Partenaire" a visitor typed in on the apex/www homepage.
     * Only consulted when the request host has no dedicated partner subdomain, so that
     * entering a code on www.example.com resolves the same partner a real
     * partner.example.com subdomain would.
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

        $hostHeader = self::trustedForwardedHost() ?? $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(trim(explode(':', (string) $hostHeader)[0]));
        if ($host === '' || $host === 'localhost') {
            return $partner = self::fromCode();
        }

        $parts = explode('.', $host);
        if (count($parts) < 2 || $host === self::registrableDomain($host)) {
            return $partner = self::fromCode();
        }

        $subdomain = $parts[0];
        if (in_array($subdomain, ['www', 'admin', 'api', 'localhost'], true)) {
            return $partner = self::fromCode();
        }

        $partner = self::lookupByCode($subdomain);
        return $partner;
    }

    /**
     * Best-effort registrable ("eTLD+1") domain for the given host, used to tell apart the
     * bare apex/www domain (e.g. "grand-baie-maurice.com") from an actual partner subdomain
     * (e.g. "partner.grand-baie-maurice.com"). Without this check, a request to the naked
     * apex domain would wrongly treat its own first label as a subdomain/partner code, fail
     * to find a matching partner, and never fall back to the "Code Partenaire" cookie — so a
     * visitor who entered a valid code would keep seeing the code entry form forever.
     */
    private static function registrableDomain(string $host): string
    {
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }

        $tld = $labels[$count - 1];
        $sld = $labels[$count - 2];
        // Two-level public suffixes look like "<=3 char SLD>.<2 char ccTLD>", e.g. co.uk,
        // com.au, org.uk, ac.nz, com.br. In that case keep three labels; otherwise two.
        $keep = (strlen($tld) === 2 && strlen($sld) <= 3) ? 3 : 2;
        return implode('.', array_slice($labels, -$keep));
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

    public static function setCodeCookie(string $code): void
    {
        setcookie(self::CODE_COOKIE, $code, [
            'expires' => time() + 60 * 60 * 24 * 30,
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

    /**
     * X-Forwarded-Host is only honored when the direct client (REMOTE_ADDR) is a
     * proxy explicitly listed in TRUSTED_PROXIES. Otherwise it is attacker-controlled
     * and trusting it would let anyone spoof which tenant/partner a request resolves to
     * (e.g. to hijack which partner's SMTP config sends a public reservation/contact email).
     */
    private static function trustedForwardedHost(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
        if (!$forwarded) {
            return null;
        }
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = array_filter(array_map('trim', explode(',', (string) Settings::get('TRUSTED_PROXIES', ''))));
        if ($remoteAddr === '' || !in_array($remoteAddr, $trusted, true)) {
            return null;
        }
        return (string) $forwarded;
    }
}
