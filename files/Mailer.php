<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Mailer
{
    public static function renderTemplate(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $matches) use ($variables): string {
            return (string) ($variables[$matches[1]] ?? $matches[0]);
        }, $template) ?? $template;
    }

    public static function sendTemplatedEmail(array $partner, array $template, string $to, array $variables, array $embeds = []): void
    {
        $html = self::renderTemplate((string) $template['body_html'], $variables);
        self::deliver(
            $partner,
            $to,
            self::renderTemplate((string) $template['subject'], $variables),
            $html,
            null,
            self::filterUnusedEmbeds($html, $embeds)
        );
    }

    public static function sendRawEmail(array $partner, string $to, string $subject, string $html, array $embeds = []): void
    {
        self::deliver($partner, $to, $subject, $html, null, self::filterUnusedEmbeds($html, $embeds));
    }

    /**
     * A partner can freely edit their email templates (copy one from
     * another partner, delete the {{photo_bien}}/{{signature_photo}}
     * placeholder, ...). If a template no longer references a given
     * embed's cid: anywhere in its rendered HTML, sending it anyway as
     * part of the multipart/related message causes mail clients (Apple
     * Mail/iCloud Mail among them) to fall back to showing it as a plain
     * trailing attachment instead of just omitting it — since nothing in
     * the HTML asks for it to be placed inline. Dropping genuinely unused
     * embeds keeps every recipient's message either fully inline or, if
     * the template doesn't want the photo at all, free of a stray
     * attachment.
     *
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds
     * @return array<int, array{cid: string, data: string, mime: string}>
     */
    private static function filterUnusedEmbeds(string $html, array $embeds): array
    {
        return array_values(array_filter(
            $embeds,
            static fn (array $embed): bool => isset($embed['cid']) && str_contains($html, 'cid:' . $embed['cid'])
        ));
    }

    public static function sendContactEmail(array $partner, string $replyTo, string $subject, string $html): void
    {
        self::deliver($partner, (string) $partner['email'], $subject, $html, $replyTo);
    }

    /**
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds
     *        Images to embed inline via Content-ID (referenced in $html as
     *        <img src="cid:...">), instead of hotlinking an external URL.
     *        When non-empty, the message is sent as multipart/related.
     */
    private static function deliver(array $partner, string $to, string $subject, string $html, ?string $replyTo = null, array $embeds = []): void
    {
        $startedAt = microtime(true);
        // Collects every SMTP command sent and every server response line
        // (see command()/expect()/sendSmtp()), so /admin/diagnostic can show
        // the exact protocol conversation for a given send attempt instead of
        // just "SENT"/"FAILED" — critical for diagnosing cases where the
        // server accepts the message (250 OK) but it never actually reaches
        // the recipient's mailbox (silently dropped downstream by their mail
        // provider, e.g. Microsoft/Outlook/live.com spam filtering).
        $trace = [];
        $meta = [
            'transport' => 'mail()',
            'host' => null,
            'port' => null,
            'security' => null,
            'embeds' => count($embeds),
            'embed_bytes' => array_sum(array_map(static fn (array $e): int => strlen((string) ($e['data'] ?? '')), $embeds)),
        ];

        try {
            $to = self::sanitizeAddress($to, 'recipient');
            $subject = self::stripCrlf($subject);
            if ($replyTo !== null) {
                $replyTo = self::sanitizeAddress($replyTo, 'Reply-To');
            }

            $config = [
                'host' => self::firstNonEmpty($partner['smtp_host'] ?? null, Settings::get('SMTP_HOST', 'mail.grand-baie-maurice.com')),
                'port' => (int) self::firstNonEmpty($partner['smtp_port'] ?? null, (string) Settings::int('SMTP_PORT', 465)),
                'user' => self::firstNonEmpty($partner['smtp_user'] ?? null, Settings::get('SMTP_USER', 'infos@grand-baie-maurice.com')),
                'pass' => self::firstNonEmpty($partner['smtp_pass'] ?? null, Settings::get('SMTP_PASS', '')),
                'from_email' => self::firstNonEmpty($partner['smtp_user'] ?? null, Settings::get('SMTP_FROM_EMAIL', ''), Settings::get('SMTP_USER', 'infos@grand-baie-maurice.com')),
                'from_name' => (string) ($partner['name'] ?? Settings::get('SMTP_FROM_NAME', 'samchlolaurepartners')),
                'security' => strtolower((string) Settings::get('SMTP_SECURITY', 'ssl')),
            ];

            if (!empty($config['host'])) {
                $meta['transport'] = 'smtp';
                $meta['host'] = $config['host'];
                $meta['port'] = $config['port'];
                $meta['security'] = $config['security'];
                self::sendSmtp($config, $to, $subject, $html, $replyTo, $embeds, $trace);
            } else {
                $boundary = self::boundary();
                $headers = [
                    'MIME-Version: 1.0',
                    'From: "' . addslashes(self::stripCrlf($config['from_name'])) . '" <' . $config['from_email'] . '>',
                ];
                if ($replyTo) {
                    $headers[] = 'Reply-To: ' . $replyTo;
                }
                if ($embeds !== []) {
                    // RFC 2387 §3.1: multipart/related needs a "type" parameter
                    // naming the root body part's media type. Without it, some
                    // mail clients (notably Apple Mail / iCloud Mail) fail to
                    // resolve the HTML's cid: references and instead render
                    // every embedded image as a plain attachment at the end of
                    // the message instead of inline where referenced.
                    $headers[] = 'Content-Type: multipart/related; type="text/html"; boundary="' . $boundary . '"';
                    $body = self::buildRelatedBody($boundary, $html, $embeds);
                } else {
                    $headers[] = 'Content-Type: text/html; charset=UTF-8';
                    $body = $html;
                }

                $trace[] = 'mail(' . $to . ', ' . $subject . ', ' . strlen($body) . ' bytes body, ' . count($headers) . ' headers)';
                if (!@mail($to, $subject, $body, implode("\r\n", $headers))) {
                    $trace[] = 'mail() returned false';
                    throw new RuntimeException('Unable to send email via mail()');
                }
                $trace[] = 'mail() returned true';
            }
        } catch (\Throwable $e) {
            self::logMail($to, $subject, 'FAILED: ' . $e->getMessage(), $meta, $trace, $startedAt);
            throw $e;
        }

        self::logMail($to, $subject, 'SENT', $meta, $trace, $startedAt);
    }

    /**
     * Appends a structured (JSON-lines) entry to files/storage/logs/mail.log
     * for every send attempt (success or failure), independent of PHP's own
     * error_log() destination. On shared/cPanel hosting, error_log() often
     * goes to a server-level log the partner/admin can't easily reach, which
     * made silent SMTP failures (bad credentials, wrong host, auth rejected,
     * message accepted then dropped downstream, ...) impossible to diagnose
     * from within the app. This file is always reachable from the deployment
     * package. Each line is a standalone JSON object so /admin/diagnostic can
     * show a readable summary plus the full SMTP transcript per attempt; a
     * plain-text fallback line is used only if json_encode() itself fails.
     *
     * @param array<string, mixed> $meta transport/host/port/security/embeds info (see deliver())
     * @param list<string> $trace every SMTP command sent + server response line, in order
     */
    private static function logMail(string $to, string $subject, string $status, array $meta, array $trace, float $startedAt): void
    {
        $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/files/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = [
            'ts' => date('Y-m-d H:i:s'),
            'to' => str_replace(["\r", "\n"], ' ', $to),
            'subject' => str_replace(["\r", "\n"], ' ', $subject),
            'status' => str_replace(["\r", "\n"], ' ', $status),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ] + $meta + ['trace' => $trace];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = sprintf(
                '[%s] to=%s subject=%s status=%s',
                $entry['ts'],
                $entry['to'],
                $entry['subject'],
                $entry['status']
            );
        }
        @file_put_contents($dir . '/mail.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Rejects malformed addresses and strips CR/LF so untrusted input (client-supplied
     * "client_email" on the public booking form, "email" on the contact form) can never
     * break out of a SMTP command line or inject extra mail headers (e.g. a forged Bcc:).
     */
    private static function sanitizeAddress(string $address, string $label): string
    {
        $address = self::stripCrlf(trim($address));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid ' . $label . ' email address');
        }
        return $address;
    }

    private static function stripCrlf(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private static function sendSmtp(array $config, string $to, string $subject, string $html, ?string $replyTo, array $embeds, array &$trace): void
    {
        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $transport = $port === 465 ? 'ssl://' . $host : $host;
        $trace[] = 'connect ' . $transport . ':' . $port;
        $socket = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!is_resource($socket)) {
            $trace[] = 'connection failed: ' . $errstr . ' (' . $errno . ')';
            throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($socket, 15);

        self::expect($socket, [220], $trace);
        self::command($socket, 'EHLO localhost', [250], $trace);

        if ($transport === $host && function_exists('stream_socket_enable_crypto')) {
            self::command($socket, 'STARTTLS', [220], $trace);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $trace[] = 'STARTTLS negotiation failed';
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            $trace[] = 'STARTTLS negotiated';
            self::command($socket, 'EHLO localhost', [250], $trace);
        }

        if (!empty($config['user'])) {
            // The base64-encoded username/password are never written to the
            // trace (only the literal "AUTH LOGIN"/"***" placeholders),
            // since /admin/diagnostic renders this trace to admins and the
            // encoding is trivially reversible, not real encryption.
            self::command($socket, 'AUTH LOGIN', [334], $trace);
            self::command($socket, base64_encode((string) $config['user']), [334], $trace, '*** (username)');
            self::command($socket, base64_encode((string) $config['pass']), [235], $trace, '*** (password)');
        }

        $fromEmail = (string) $config['from_email'];
        self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $trace);
        self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251], $trace);
        self::command($socket, 'DATA', [354], $trace);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'To: <' . $to . '>',
            'From: "' . addslashes(self::stripCrlf((string) $config['from_name'])) . '" <' . $fromEmail . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        if ($embeds !== []) {
            $boundary = self::boundary();
            // See the matching comment in deliver()'s mail() fallback: the
            // "type" parameter is required by RFC 2387 for mail clients to
            // reliably inline cid:-referenced images instead of showing them
            // as trailing attachments.
            $headers[] = 'Content-Type: multipart/related; type="text/html"; boundary="' . $boundary . '"';
            $body = self::buildRelatedBody($boundary, $html, $embeds);
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $html;
        }

        $trace[] = 'DATA payload: ' . count($embeds) . ' embed(s), ' . strlen($body) . ' bytes body';
        $message = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($body) . "\r\n.";
        fwrite($socket, $message . "\r\n");
        self::expect($socket, [250], $trace);
        self::command($socket, 'QUIT', [221], $trace);
        fclose($socket);
    }

    /**
     * Builds a multipart/related body: the HTML part first, then one part
     * per embedded image, each addressable from the HTML via
     * "cid:{$embed['cid']}" instead of an external URL.
     */
    private static function buildRelatedBody(string $boundary, string $html, array $embeds): string
    {
        $parts = [
            "--{$boundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $html,
        ];

        foreach ($embeds as $embed) {
            $mime = (string) ($embed['mime'] ?? 'application/octet-stream');
            $cid = (string) ($embed['cid'] ?? '');
            $data = (string) ($embed['data'] ?? '');
            if ($cid === '' || $data === '') {
                continue;
            }
            $parts[] =
                "--{$boundary}\r\n" .
                "Content-Type: {$mime}\r\n" .
                "Content-Transfer-Encoding: base64\r\n" .
                "Content-ID: <{$cid}>\r\n" .
                "Content-Disposition: inline; filename=\"{$cid}\"\r\n\r\n" .
                chunk_split(base64_encode($data));
        }

        return implode("\r\n", $parts) . "\r\n--{$boundary}--";
    }

    private static function boundary(): string
    {
        return 'boundary_' . bin2hex(random_bytes(16));
    }

    /**
     * SMTP DATA requires "dot-stuffing": any line that starts with a lone "."
     * must be escaped as ".." so the mail server doesn't mistake it for the
     * end-of-data marker. Both plain HTML and base64-encoded attachment
     * bodies can (rarely) contain such a line.
     */
    private static function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function command($socket, string $command, array $okCodes, array &$trace, ?string $traceLabel = null): void
    {
        fwrite($socket, $command . "\r\n");
        $trace[] = '> ' . ($traceLabel ?? $command);
        self::expect($socket, $okCodes, $trace);
    }

    private static function expect($socket, array $okCodes, array &$trace): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $trace[] = '< ' . trim($response);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
    }

    private static function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) ($value ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}
