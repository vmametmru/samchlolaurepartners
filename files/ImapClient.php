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

                $structure = @imap_fetchstructure($this->mailbox, $i);
                $bodies = $this->extractBodies($this->mailbox, $i, $structure);

                $emails[] = [
                    'uid' => isset($header->Uid) ? (int) $header->Uid : $i,
                    'msgno' => (int) $header->Msgno,
                    'subject' => $this->decodeHeader($header->subject ?? ''),
                    'from_email' => $this->extractFromEmail($header->from ?? []),
                    'from_name' => $header->from[0]->personal ?? '',
                    'to_emails' => $this->getRecipients($header->to ?? []),
                    'cc_emails' => $this->getRecipients($header->cc ?? []),
                    'received_at' => gmdate('Y-m-d H:i:s', strtotime($header->date ?? 'now')),
                    'body_text' => $bodies['text'],
                    'body_html' => $bodies['html'],
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

            $structure = @imap_fetchstructure($this->mailbox, $uid);
            $bodies = $this->extractBodies($this->mailbox, $uid, $structure);

            return [
                'uid' => isset($header->Uid) ? (int) $header->Uid : $uid,
                'msgno' => (int) $header->Msgno,
                'subject' => $this->decodeHeader($header->subject ?? ''),
                'from_email' => $this->extractFromEmail($header->from ?? []),
                'from_name' => $header->from[0]->personal ?? '',
                'to_emails' => $this->getRecipients($header->to ?? []),
                'cc_emails' => $this->getRecipients($header->cc ?? []),
                'received_at' => gmdate('Y-m-d H:i:s', strtotime($header->date ?? 'now')),
                'body_text' => $bodies['text'],
                'body_html' => $bodies['html'],
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
     * Walk the full MIME structure (recursing into nested multipart/* such
     * as multipart/related inside multipart/mixed) and extract decoded
     * text/plain and text/html bodies, embedding any inline (cid:) images
     * referenced by the HTML as data URIs so they display without needing
     * separate attachment storage/routes.
     *
     * @return array{text: string, html: string}
     */
    private function extractBodies($mailbox, int $msgnum, ?object $structure): array
    {
        $parts = ['html' => null, 'text' => null, 'images' => []];

        if ($structure) {
            $this->walkStructure($mailbox, $msgnum, $structure, '', $parts);
        }

        $html = $parts['html'];
        if ($html !== null && !empty($parts['images'])) {
            $html = $this->embedInlineImages($html, $parts['images']);
        }

        return [
            'text' => $parts['text'] ?? '',
            'html' => $html ?? '',
        ];
    }

    /**
     * Recursively visit every leaf part of a (possibly nested) MIME
     * structure, using IMAP's dotted part-number scheme (e.g. "2.1") so
     * imap_fetchbody() can address parts inside a nested multipart.
     */
    private function walkStructure($mailbox, int $msgnum, object $structure, string $prefix, array &$parts): void
    {
        if ((int) $structure->type === TYPEMULTIPART && !empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $partNum = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
                $this->walkStructure($mailbox, $msgnum, $part, $partNum, $parts);
            }
            return;
        }

        // Leaf (non-multipart) part. For a non-multipart message, IMAP
        // addresses the whole body as part "1".
        $partNum = $prefix === '' ? '1' : $prefix;
        $type = (int) $structure->type;
        $subtype = strtolower($structure->subtype ?? '');

        if ($type === TYPETEXT && $subtype === 'html' && $parts['html'] === null) {
            $parts['html'] = $this->fetchDecodedTextPart($mailbox, $msgnum, $partNum, $structure);
            return;
        }

        if ($type === TYPETEXT && $subtype === 'plain' && $parts['text'] === null) {
            $parts['text'] = $this->fetchDecodedTextPart($mailbox, $msgnum, $partNum, $structure);
            return;
        }

        if ($type === TYPEIMAGE) {
            $cid = $this->partContentId($structure);
            if ($cid !== null) {
                $raw = $this->fetchRawPart($mailbox, $msgnum, $partNum, $structure);
                $parts['images'][$cid] = 'data:image/' . $subtype . ';base64,' . base64_encode($raw);
            }
        }
    }

    /**
     * Fetch a part's raw content, decoding its Content-Transfer-Encoding
     * (base64/quoted-printable/etc.) so callers get the real bytes instead
     * of the still-encoded wire format.
     */
    private function fetchRawPart($mailbox, int $msgnum, string $partNum, object $structure): string
    {
        $data = @imap_fetchbody($mailbox, $msgnum, $partNum);
        if ($data === false) {
            return '';
        }

        switch ((int) ($structure->encoding ?? ENC7BIT)) {
            case ENCBASE64:
                $decoded = base64_decode($data, true);
                return $decoded !== false ? $decoded : $data;
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    /**
     * Like fetchRawPart(), but additionally converts the part's declared
     * charset to UTF-8 so text/html and text/plain bodies render correctly
     * regardless of the sender's original encoding.
     */
    private function fetchDecodedTextPart($mailbox, int $msgnum, string $partNum, object $structure): string
    {
        $data = $this->fetchRawPart($mailbox, $msgnum, $partNum, $structure);

        $charset = $this->partCharset($structure);
        if ($charset !== null && strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'US-ASCII') !== 0) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
            if ($converted !== false) {
                $data = $converted;
            }
        }

        return $data;
    }

    /**
     * Read the CHARSET parameter from a part's structure, if present.
     */
    private function partCharset(object $structure): ?string
    {
        if (empty($structure->parameters)) {
            return null;
        }
        foreach ($structure->parameters as $param) {
            if (isset($param->attribute) && strcasecmp($param->attribute, 'CHARSET') === 0) {
                return (string) $param->value;
            }
        }
        return null;
    }

    /**
     * Read a part's Content-ID (used to match inline images referenced by
     * "cid:" URLs in an HTML body), stripped of angle brackets.
     */
    private function partContentId(object $structure): ?string
    {
        if (!empty($structure->id)) {
            return trim((string) $structure->id, '<>');
        }
        return null;
    }

    /**
     * Replace cid: image references in HTML with inline data URIs so
     * images embedded via multipart/related display without needing a
     * separate attachment-serving route.
     *
     * @param array<string, string> $images Map of Content-ID => data URI
     */
    private function embedInlineImages(string $html, array $images): string
    {
        return preg_replace_callback(
            '/\bsrc\s*=\s*(["\'])cid:([^"\']+)\1/i',
            function (array $matches) use ($images): string {
                $cid = $matches[2];
                if (isset($images[$cid])) {
                    return 'src="' . $images[$cid] . '"';
                }
                return $matches[0];
            },
            $html
        ) ?? $html;
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
