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
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::EXPIRY_SECONDS,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        $_COOKIE[self::COOKIE_NAME] = $token;
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
}
