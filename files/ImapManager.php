<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Manager for centralized IMAP email storage and encryption
 * Uses global IMAP server settings + per-user email/password
 */
final class ImapManager
{
    /**
     * Get IMAP connection parameters for a user
     * Returns array with imap_host, imap_port, email, password
     */
    public static function getConnectionParams(int $userId): ?array
    {
        // Fetch user email and encrypted password
        $stmt = Database::connection()->prepare('SELECT email, email_password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $email = (string) $user['email'];
        $encryptedPassword = (string) ($user['email_password'] ?? '');

        // If user hasn't configured email password, cannot access IMAP
        if (empty($encryptedPassword)) {
            return null;
        }

        $password = self::decryptPassword($encryptedPassword);
        if (empty($password)) {
            return null;
        }

        return [
            'imap_host' => Settings::get('IMAP_HOST', 'mail.grand-baie-maurice.com'),
            'imap_port' => (int) Settings::get('IMAP_PORT', '993'),
            'email' => $email,
            'password' => $password,
        ];
    }

    /**
     * Get unread email count for user (live IMAP check).
     *
     * The custom in-house inbox/read UI has been replaced by SSO into the
     * hosting's own cPanel Webmail (Roundcube) — see WebmailSso — so emails
     * are no longer synced/stored locally. This connects live to check the
     * INBOX unread count.
     */
    public static function getUnreadCount(int $userId): int
    {
        $params = self::getConnectionParams($userId);
        if (!$params) {
            return 0;
        }

        $client = new ImapClient(
            (string) $params['imap_host'],
            (int) $params['imap_port'],
            (string) $params['email'],
            (string) $params['password'],
            (string) $params['email']
        );

        $count = $client->getUnreadCount();
        $client->disconnect();
        return $count;
    }

    /**
     * Save encrypted email password for user
     */
    public static function setEmailPassword(int $userId, string $password): bool
    {
        $encryptedPassword = self::encryptPassword($password);
        $stmt = Database::connection()->prepare('UPDATE users SET email_password = ? WHERE id = ?');
        return $stmt->execute([$encryptedPassword, $userId]);
    }

    /**
     * Encrypt password for storage
     */
    private static function encryptPassword(string $password): string
    {
        $appSecret = self::encryptionSecret();
        if (empty($appSecret)) {
            throw new \Exception(
                'No encryption secret is configured (APP_SECRET/JWT_SECRET/AUTH_SECRET). ' .
                'Cannot encrypt password.'
            );
        }

        $key = self::deriveKey($appSecret);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \Exception('Failed to encrypt password');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt password from storage
     */
    private static function decryptPassword(string $encrypted): string
    {
        try {
            $appSecret = self::encryptionSecret();
            if (empty($appSecret)) {
                error_log('[ImapManager] No encryption secret configured for decryption');
                return '';
            }

            $data = base64_decode($encrypted, true);
            if ($data === false || strlen($data) < 16) {
                return '';
            }

            $key = self::deriveKey($appSecret);
            $iv = substr($data, 0, 16);
            $encryptedData = substr($data, 16);
            $decrypted = openssl_decrypt($encryptedData, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted ?: '';
        } catch (\Throwable $e) {
            error_log('[ImapManager] Decryption failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Resolve the secret used to derive the password encryption key.
     *
     * A dedicated APP_SECRET setting is preferred when present, but this
     * install already generates and stores a JWT_SECRET at install time
     * (see install/install.php), so we fall back to it (then AUTH_SECRET)
     * rather than requiring admins to manually configure a brand new
     * setting just for the webmail feature.
     */
    private static function encryptionSecret(): string
    {
        $secret = Settings::get('APP_SECRET', Settings::get('JWT_SECRET', Settings::get('AUTH_SECRET', '')));
        return $secret ?? '';
    }

    /**
     * Derive an AES-256 key from the configured secret, with domain
     * separation so this key is distinct from the one used to sign auth
     * tokens even when both fall back to the same underlying JWT_SECRET.
     */
    private static function deriveKey(string $appSecret): string
    {
        return hash('sha256', $appSecret . '|imap-password-encryption-v1', true);
    }
}

