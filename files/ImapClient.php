<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal IMAP client used only to check a mailbox's unread count for the
 * navbar badge.
 *
 * The in-house webmail (reading/composing emails ourselves) was replaced by
 * single sign-on into the hosting provider's own cPanel Webmail/Roundcube
 * portal (see WebmailSso and EmailController::openWebmail()), which is far
 * more robust than re-implementing MIME parsing, HTML rendering, and inline
 * image handling in-house. This class only needs to open the mailbox and
 * ask the server how many messages are unseen.
 */
final class ImapClient
{
    /** @var resource|false */
    private $mailbox = false;
    private string $server;
    private int $port;
    private string $username;
    private string $password;
    private bool $connected = false;

    public function __construct(string $server, int $port, string $username, string $password, string $email)
    {
        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        // $email is accepted for API-compatibility with callers but isn't
        // needed for a simple unread-count check.
    }

    /**
     * Connect to the IMAP server
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $ssl = $this->port === 993 ? '/ssl' : '/tls';
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
     * Disconnect from the IMAP server
     */
    public function disconnect(): void
    {
        if ($this->connected && $this->mailbox !== false) {
            @imap_close($this->mailbox);
            $this->connected = false;
        }
    }

    /**
     * Get the unread (unseen) message count for the INBOX
     */
    public function getUnreadCount(): int
    {
        if (!$this->connect()) {
            return 0;
        }

        $check = @imap_mailboxmsginfo($this->mailbox);
        return $check ? (int) $check->Unread : 0;
    }
}
