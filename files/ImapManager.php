<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Manager for IMAP account configuration and email storage
 */
final class ImapManager
{
    /**
     * Save IMAP account configuration
     */
    public static function saveAccount(int $userId, string $email, string $server, int $port, string $username, string $password): bool
    {
        $encryptedPassword = self::encryptPassword($password);
        
        $stmt = Database::connection()->prepare(
            'INSERT INTO user_imap_accounts (user_id, email, imap_server, imap_port, imap_username, imap_password_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                imap_server = VALUES(imap_server),
                imap_port = VALUES(imap_port),
                imap_username = VALUES(imap_username),
                imap_password_encrypted = VALUES(imap_password_encrypted),
                updated_at = NOW()'
        );

        return $stmt->execute([$userId, $email, $server, $port, $username, $encryptedPassword]);
    }

    /**
     * Get IMAP account for user
     */
    public static function getAccount(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM user_imap_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $account['imap_password'] = self::decryptPassword((string) $account['imap_password_encrypted']);
        }

        return $account ?: null;
    }

    /**
     * Save email to database
     */
    public static function saveEmail(int $userId, array $emailData): ?int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO imap_emails 
             (user_id, subject, from_email, from_name, to_emails, cc_emails, body_html, body_text, received_at, is_read, folder)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        ]);

        return $success ? (int) Database::connection()->lastInsertId() : null;
    }

    /**
     * Get emails for user
     */
    public static function getEmails(int $userId, string $folder = 'INBOX', int $limit = 50, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM imap_emails WHERE user_id = ? AND folder = ? ORDER BY received_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $folder, $limit, $offset]);
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
     * Clear emails for user (e.g., when reconfiguring IMAP)
     */
    public static function clearEmails(int $userId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM imap_emails WHERE user_id = ?');
        return $stmt->execute([$userId]);
    }

    /**
     * Encrypt password for storage
     */
    private static function encryptPassword(string $password): string
    {
        $key = hash('sha256', (string) Settings::get('APP_SECRET', 'default-secret'), true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, true, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt password from storage
     */
    private static function decryptPassword(string $encrypted): string
    {
        try {
            $data = base64_decode($encrypted, true);
            if ($data === false || strlen($data) < 16) {
                return '';
            }

            $key = hash('sha256', (string) Settings::get('APP_SECRET', 'default-secret'), true);
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, true, $iv);
            return $decrypted ?: '';
        } catch (\Throwable $e) {
            error_log('[ImapManager] Decryption failed: ' . $e->getMessage());
            return '';
        }
    }
}
