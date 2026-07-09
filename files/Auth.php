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
        $options = self::cookieOptions(time() - 3600);
        setcookie(self::COOKIE_NAME, '', $options);
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
     * Only ever returns an *explicitly configured* cookie domain. When none is set we
     * return null so the browser stores a host-only cookie for the exact host serving the
     * request — the safest, most compatible behaviour. Deriving a Domain from the request
     * host or APP_URL is what previously caused browsers to silently drop the Set-Cookie
     * (e.g. when the served host was a www/apex or cPanel preview domain that didn't match),
     * leaving the user "logged in" after the redirect but with no session on the next page.
     * Set APP_COOKIE_DOMAIN (or COOKIE_DOMAIN) only if you need one cookie shared across
     * several subdomains, e.g. ".example.com".
     */
    private static function cookieDomain(): ?string
    {
        $configured = trim((string) (Env::get('APP_COOKIE_DOMAIN', Env::get('COOKIE_DOMAIN', '')) ?? ''));
        return $configured !== '' ? $configured : null;
    }
}
