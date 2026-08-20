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
     * Save email to database for user
     */
    public static function saveEmail(int $userId, array $emailData): ?int
    {
        // Generate unique ID for deduplication (IMAP Message-ID or SHA256 hash)
        $imap_message_id = $emailData['imap_message_id'] ?? hash('sha256',
            ($emailData['from_email'] ?? '') .
            ($emailData['received_at'] ?? '') .
            ($emailData['subject'] ?? ''),
            false
        );

        $stmt = Database::connection()->prepare(
            'INSERT INTO imap_emails
             (user_id, subject, from_email, from_name, to_emails, cc_emails, body_html, body_text, received_at, is_read, folder, imap_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_read = VALUES(is_read),
                updated_at = NOW()'
        );

        $success = $stmt->execute([
            $userId,
            substr((string) ($emailData['subject'] ?? ''), 0, 500),
            substr((string) ($emailData['from_email'] ?? ''), 0, 255),
            substr((string) ($emailData['from_name'] ?? ''), 0, 255),
            (string) ($emailData['to_emails'] ?? ''),
            (string) ($emailData['cc_emails'] ?? ''),
            (string) ($emailData['body_html'] ?? ''),
            (string) ($emailData['body_text'] ?? ''),
            $emailData['received_at'] ?? null,
            (int) ($emailData['is_read'] ?? 0),
            substr((string) ($emailData['folder'] ?? 'INBOX'), 0, 255),
            $imap_message_id,
        ]);

        return $success ? (int) Database::connection()->lastInsertId() : null;
    }

    /**
     * Get emails for user (with pagination)
     */
    public static function getEmails(int $userId, string $folder = 'INBOX', int $limit = 50, int $offset = 0): array
    {
        $pdo = Database::connection();
        // LIMIT/OFFSET can't be bound as params in MySQL's native prepared
        // statements, so they're inlined directly here — safe because both
        // are cast to int above. user_id/folder stay parameterized.
        $stmt = $pdo->prepare(
            'SELECT * FROM imap_emails WHERE user_id = ? AND folder = ? ' .
            'ORDER BY received_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$userId, $folder]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get unread email count for user
     */
    public static function getUnreadCount(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) as count FROM imap_emails WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get single email
     */
    public static function getEmail(int $userId, int $emailId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM imap_emails WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$emailId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Mark email as read
     */
    public static function markAsRead(int $userId, int $emailId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE imap_emails SET is_read = 1 WHERE id = ? AND user_id = ?');
        return $stmt->execute([$emailId, $userId]);
    }

    /**
     * Delete email
     */
    public static function deleteEmail(int $userId, int $emailId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM imap_emails WHERE id = ? AND user_id = ?');
        return $stmt->execute([$emailId, $userId]);
    }

    /**
     * Clear emails for user
     */
    public static function clearEmails(int $userId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM imap_emails WHERE user_id = ?');
        return $stmt->execute([$userId]);
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

