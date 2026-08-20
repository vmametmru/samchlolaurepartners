<?php

declare(strict_types=1);

namespace App;

/**
 * IMAP client for reading emails from partner accounts.
 * Supports SSL/TLS connections and email synchronization.
 */
final class ImapClient
{
    private $mailbox;
    private $email;
    private $server;
    private $port;
    private $username;
    private $password;
    private bool $connected = false;

    /**
     * Initialize IMAP client with connection details
     */
    public function __construct(string $server, int $port, string $username, string $password, string $email)
    {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->email = $email;
    }

    /**
     * Connect to IMAP server
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        // Build IMAP connection string with proper SSL/TLS validation
        $ssl = $this->port == 993 ? '/ssl' : '/tls';
        $mailbox = '{' . $this->server . ':' . $this->port . $ssl . '}INBOX';

        try {
            $this->mailbox = @imap_open($mailbox, $this->username, $this->password);
            if ($this->mailbox === false) {
                throw new \Exception('Failed to connect to IMAP server: ' . imap_last_error());
            }
            $this->connected = true;
            return true;
        } catch (\Throwable $e) {
            error_log('[ImapClient] Connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disconnect from IMAP server
     */
    public function disconnect(): void
    {
        if ($this->connected && $this->mailbox !== false) {
            @imap_close($this->mailbox);
            $this->connected = false;
        }
    }

    /**
     * Get unread email count
     */
    public function getUnreadCount(): int
    {
        if (!$this->connect()) {
            return 0;
        }

        $check = @imap_mailboxmsginfo($this->mailbox);
        return $check ? (int) $check->Unread : 0;
    }

    /**
     * Get emails from specified folder (default INBOX)
     */
    public function getEmails(string $folder = 'INBOX', int $limit = 50, int $offset = 0): array
    {
        if (!$this->connect()) {
            return [];
        }

        try {
            // Get message count
            $msgCount = @imap_num_msg($this->mailbox);
            if ($msgCount === false || $msgCount === 0) {
                return [];
            }

            $emails = [];
            $start = max(1, $msgCount - $offset - $limit + 1);
            $end = $msgCount - $offset;

            for ($i = $start; $i <= $end; $i++) {
                if ($i < 1) continue;
                
                $header = @imap_headerinfo($this->mailbox, $i);
                if (!$header) continue;

                $body = @imap_body($this->mailbox, $i);
                $structure = @imap_fetchstructure($this->mailbox, $i);

                $emails[] = [
                    'uid' => isset($header->Uid) ? (int) $header->Uid : $i,
                    'msgno' => (int) $header->Msgno,
                    'subject' => $this->decodeHeader($header->subject ?? ''),
                    'from_email' => $this->extractFromEmail($header->from ?? []),
                    'from_name' => $header->from[0]->personal ?? '',
                    'to_emails' => $this->getRecipients($header->to ?? []),
                    'cc_emails' => $this->getRecipients($header->cc ?? []),
                    'received_at' => gmdate('Y-m-d H:i:s', strtotime($header->date ?? 'now')),
                    'body_text' => $body ?: '',
                    'body_html' => $this->extractHtmlBody($this->mailbox, $i, $structure) ?: '',
                    'is_read' => isset($header->Recent) && $header->Recent == 'R' ? 1 : 0,
                ];
            }

            return array_reverse($emails);
        } catch (\Throwable $e) {
            error_log('[ImapClient] Error fetching emails: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get single email by UID
     */
    public function getEmail(int $uid): ?array
    {
        if (!$this->connect()) {
            return null;
        }

        try {
            $header = @imap_headerinfo($this->mailbox, $uid);
            if (!$header) {
                return null;
            }

            $body = @imap_body($this->mailbox, $uid);
            $structure = @imap_fetchstructure($this->mailbox, $uid);

            return [
                'uid' => isset($header->Uid) ? (int) $header->Uid : $uid,
                'msgno' => (int) $header->Msgno,
                'subject' => $this->decodeHeader($header->subject ?? ''),
                'from_email' => $this->extractFromEmail($header->from ?? []),
                'from_name' => $header->from[0]->personal ?? '',
                'to_emails' => $this->getRecipients($header->to ?? []),
                'cc_emails' => $this->getRecipients($header->cc ?? []),
                'received_at' => gmdate('Y-m-d H:i:s', strtotime($header->date ?? 'now')),
                'body_text' => $body ?: '',
                'body_html' => $this->extractHtmlBody($this->mailbox, $uid, $structure) ?: '',
                'is_read' => isset($header->Recent) && $header->Recent == 'R' ? 1 : 0,
            ];
        } catch (\Throwable $e) {
            error_log('[ImapClient] Error fetching email: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark email as read
     */
    public function markAsRead(int $uid): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            @imap_setflag_full($this->mailbox, (string) $uid, '\\Seen', ST_UID);
            return true;
        } catch (\Throwable $e) {
            error_log('[ImapClient] Error marking email as read: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete email
     */
    public function deleteEmail(int $uid): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            @imap_delete($this->mailbox, (string) $uid, FT_UID);
            @imap_expunge($this->mailbox);
            return true;
        } catch (\Throwable $e) {
            error_log('[ImapClient] Error deleting email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decode email header
     */
    private function decodeHeader(string $header): string
    {
        $decoded = @imap_mime_header_decode($header);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result ?: $header;
    }

    /**
     * Extract email from header->from array
     */
    private function extractFromEmail(array $fromArray): string
    {
        if (empty($fromArray) || !isset($fromArray[0])) {
            return '';
        }
        $from = $fromArray[0];
        if (!isset($from->mailbox, $from->host)) {
            return '';
        }
        return $from->mailbox . '@' . $from->host;
    }

    /**
     * Extract recipients from header
     */
    private function getRecipients(array $recipients): string
    {
        $emails = [];
        foreach ($recipients as $recipient) {
            if (isset($recipient->mailbox, $recipient->host)) {
                $emails[] = $recipient->mailbox . '@' . $recipient->host;
            }
        }
        return implode(', ', $emails);
    }

    /**
     * Extract HTML body from multipart message
     */
    private function extractHtmlBody($mailbox, int $msgnum, ?object $structure): ?string
    {
        if (!$structure) {
            return null;
        }

        if ($structure->type == 1) { // multipart
            foreach ($structure->parts as $i => $part) {
                if ($part->type == 0 && $part->subtype == 'html') {
                    return @imap_fetchbody($mailbox, $msgnum, (string) ($i + 1));
                }
            }
        } elseif ($structure->type == 0 && $structure->subtype == 'html') {
            return @imap_fetchbody($mailbox, $msgnum, '1');
        }

        return null;
    }

    /**
     * Get list of folders
     */
    public function getFolders(): array
    {
        if (!$this->connect()) {
            return [];
        }

        try {
            $mailboxes = @imap_list($this->mailbox, '{' . $this->server . ':' . $this->port . '}', '*');
            if (!$mailboxes) {
                return ['INBOX'];
            }
            return array_map(fn($m) => trim(str_replace('{' . $this->server . ':' . $this->port . '}', '', $m)), $mailboxes);
        } catch (\Throwable $e) {
            error_log('[ImapClient] Error fetching folders: ' . $e->getMessage());
            return [];
        }
    }
}
