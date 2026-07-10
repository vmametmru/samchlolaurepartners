<?php

declare(strict_types=1);

namespace App;

final class Tenant
{
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
            return $partner = null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return $partner = null;
        }

        $subdomain = $parts[0];
        if (in_array($subdomain, ['www', 'admin', 'api', 'localhost'], true)) {
            return $partner = null;
        }

        try {
            $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE subdomain = ? AND active = 1 LIMIT 1');
            $stmt->execute([$subdomain]);
            $partner = $stmt->fetch() ?: null;
        } catch (\Throwable) {
            $partner = null;
        }
        return $partner;
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
