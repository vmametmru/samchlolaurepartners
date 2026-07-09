<?php

declare(strict_types=1);

namespace App;

/**
 * Same signed token everywhere: HTML pages rely on the HttpOnly auth_token cookie,
 * while API clients may also send the exact same token in Authorization: Bearer.
 * This keeps one authentication primitive and one validation path across the app.
 */
final class Auth
{
    private const COOKIE_NAME = 'auth_token';
    private const EXPIRY_SECONDS = 604800;

    public static function login(string $email, string $password): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, p.id AS partner_id_val, p.name AS partner_name,
                    p.subdomain, p.logo_url, p.primary_color, p.email AS partner_email,
                    p.markup_percent, p.active AS partner_active
             FROM users u
             LEFT JOIN partners p ON p.id = u.partner_id
             WHERE u.email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $payload = self::userPayload($user);
        $token = self::issueToken($payload);
        self::setAuthCookie($token);

        return ['token' => $token, 'user' => $payload];
    }

    public static function logout(): void
    {
        self::expireCookie(null);
        foreach (self::logoutCookieDomains() as $domain) {
            self::expireCookie($domain);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    public static function issueToken(array $payload): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $issuedAt = time();
        $claims = $payload + ['iat' => $issuedAt, 'exp' => $issuedAt + self::EXPIRY_SECONDS];
        $body = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, self::secret(), true));
        return $header . '.' . $body . '.' . $signature;
    }

    public static function user(): ?array
    {
        $token = self::tokenFromRequest();
        return $token ? self::verifyToken($token) : null;
    }

    /**
     * Non-sensitive session diagnostic: tells whether an auth cookie is present and,
     * if so, why it failed to resolve to a user (missing/invalid/expired), without
     * requiring the caller to already be authenticated. Used to surface silent
     * cookie/session issues (e.g. a Domain-attribute mismatch) directly in the UI.
     */
    public static function debugStatus(): array
    {
        $token = self::tokenFromRequest();
        if ($token === null) {
            return ['cookie_present' => false, 'valid' => false];
        }

        return ['cookie_present' => true, 'valid' => self::verifyToken($token) !== null];
    }

    public static function requireUser(bool $adminOnly = false): array
    {
        $token = self::tokenFromRequest();
        $user = $token ? self::verifyToken($token) : null;
        if (!$user) {
            throw new HttpException(
                401,
                'Unauthorized',
                $token ? 'Invalid or expired token' : 'Missing or invalid Authorization header'
            );
        }
        if ($adminOnly && ($user['role'] ?? null) !== 'admin') {
            throw new HttpException(403, 'Forbidden', 'Admin access required');
        }
        return $user;
    }

    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($payload) || !isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        unset($payload['iat'], $payload['exp']);
        return $payload;
    }

    public static function setAuthCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, self::cookieOptions(time() + self::EXPIRY_SECONDS));
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    /**
     * Cookie attributes for the auth token. Kept intentionally close to how PHP's own
     * session cookie is emitted (host-only, path=/, Lax) because that cookie is known to
     * survive on this deployment. By default we do NOT set a Domain attribute: a host-only
     * cookie can never be rejected for a Domain/host mismatch, which is the classic cause
     * of "login redirect succeeds but the session silently disappears on the next request".
     * A Domain is only attached when explicitly configured (needed for cross-subdomain SSO).
     */
    private static function cookieOptions(int $expires): array
    {
        $options = [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isSecureRequest(),
        ];
        $domain = self::cookieDomain();
        if ($domain !== null) {
            $options['domain'] = $domain;
        }
        return $options;
    }

    /**
     * Whether the current request reached us over HTTPS, accounting for TLS-terminating
     * reverse proxies (common on cPanel/Cloudflare) that forward to Apache over plain HTTP
     * but advertise the original scheme via X-Forwarded-* headers.
     */
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
        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        return $forwardedSsl === 'on';
    }

    private static function tokenFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    private static function userPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'partner_id' => $user['partner_id'] !== null ? (int) $user['partner_id'] : null,
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'created_at' => (string) $user['created_at'],
            'updated_at' => (string) $user['updated_at'],
        ];
    }

    private static function secret(): string
    {
        $secret = Env::get('JWT_SECRET', Env::get('AUTH_SECRET', null));
        if ($secret === null || $secret === '') {
            throw new \RuntimeException(
                'JWT_SECRET (or AUTH_SECRET) must be set in the environment — refusing to sign/verify ' .
                'auth tokens with a fallback secret, as that would let anyone forge valid sessions.'
            );
        }
        return $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }

    /**
     * Resolves the Domain attribute for the auth cookie.
     *
     * This app is multi-tenant: the admin console lives on the apex/admin host while each
     * partner is served from its own subdomain (see Tenant::current()). With a host-only
     * cookie (no Domain) a session established on one host is NOT sent to another host, so a
     * partner who authenticates and then lands on (or navigates to) a different subdomain
     * appears logged out — the navbar shows "Connexion" instead of their email/role, even
     * though admins on the apex work fine. To make login work for EVERY role across the apex
     * and all partner subdomains, we share ONE cookie on the registrable parent domain.
     *
     * Safety: the parent domain is always derived from the CURRENT request Host and is a
     * suffix of it, so the serving host always domain-matches the cookie and the browser can
     * never silently discard the Set-Cookie for a host mismatch (the bug a host-only cookie
     * was meant to avoid). An explicit APP_COOKIE_DOMAIN/COOKIE_DOMAIN still overrides this,
     * and IPs / localhost / single-label hosts fall back to a host-only cookie.
     */
    private static function cookieDomain(): ?string
    {
        $configured = trim((string) (Env::get('APP_COOKIE_DOMAIN', Env::get('COOKIE_DOMAIN', '')) ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = self::requestHost();

        // Host-only (return null) for anything that can't legally carry a shared Domain:
        // empty, an IP literal, localhost, or a single-label host with no dot.
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) {
            return null;
        }

        return '.' . self::registrableDomain($host);
    }

    /**
     * Best-effort registrable ("eTLD+1") domain for sharing one cookie across subdomains,
     * derived purely from the given host. Uses the last two labels, extending to three when
     * the public suffix is a two-level one (e.g. "co.uk", "com.au") so the resulting Domain
     * is never a bare public suffix that browsers would reject. Because the result is always
     * a suffix of $host, the current request host always matches the cookie Domain.
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

    private static function requestHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($host) || $host === '') {
            $host = parse_url((string) (Env::get('APP_URL', '') ?? ''), PHP_URL_HOST) ?: '';
        }
        $host = strtolower(trim((string) $host));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        return trim($host, '.');
    }

    /**
     * Logout must remove both the CURRENT cookie scope and any legacy variants that may still
     * be stored by the browser (e.g. an older host-only cookie plus the newer shared-domain
     * cookie). Otherwise the browser can keep sending one surviving auth_token and the navbar
     * still resolves the user right after "Vous êtes déconnecté.".
     *
     * We therefore expire:
     * - the host-only cookie (domain omitted)
     * - the currently configured/shared domain
     * - the current request host
     * - dotted/non-dotted forms of those domains for compatibility with legacy Set-Cookie
     */
    private static function logoutCookieDomains(): array
    {
        $domains = [];
        $host = self::requestHost();
        $sharedDomain = self::cookieDomain();

        foreach ([$sharedDomain, $host] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            $candidate = trim($candidate, '.');
            if ($candidate === '') {
                continue;
            }
            $domains[] = $candidate;
            $domains[] = '.' . $candidate;
        }

        return array_values(array_unique($domains));
    }

    private static function expireCookie(?string $domain): void
    {
        $options = [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isSecureRequest(),
        ];
        if ($domain !== null) {
            $options['domain'] = $domain;
        }
        setcookie(self::COOKIE_NAME, '', $options);
    }
}
