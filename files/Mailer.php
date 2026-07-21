<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Mailer
{
    public static function renderTemplate(string $template, array $variables): string
    {
        $rendered = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)(?::(\d{1,4}))?\}\}/', static function (array $matches) use ($variables): string {
            $name = (string) $matches[1];
            $size = isset($matches[2]) ? (int) $matches[2] : null;
            $value = $variables[$name] ?? null;
            if ($value === null) {
                return $matches[0];
            }
            if ($value instanceof \Closure || (is_object($value) && is_callable($value))) {
                return (string) $value($size);
            }
            return (string) $value;
        }, $template) ?? $template;

        // A template built with the WYSIWYG editor can contain
        // <img src="{{photoN_url}}" ...> tags for a property photo slot that
        // doesn't actually exist (e.g. the listing only has one synced
        // photo but the template references {{photo2_url}}/{{photo3_url}}).
        // Those "_url" variables resolve to an empty string above, leaving
        // a broken <img src=""> that most mail clients render as a visible
        // broken-image placeholder. Strip any such now-empty-src <img> tag
        // entirely rather than showing recipients a broken icon.
        $rendered = (string) preg_replace('/<img\b[^>]*\ssrc=(["\'])\1[^>]*>/i', '', $rendered);

        // Images inserted from the "Mini galerie graphique" (or any other
        // locally-hosted asset) are saved as a site-root-relative path, e.g.
        // "/images/others/email-template-assets/partner-1/foo.png". That
        // resolves fine in the admin's own browser preview (relative to the
        // page's own origin), but a mail client has no such origin to
        // resolve it against, so the image silently fails to load. Rewrite
        // any such root-relative <img src="..."> into an absolute URL using
        // the current request's host, leaving already-absolute (http(s)://)
        // and data:/cid: sources untouched.
        return (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc=)(["\'])(\/(?!\/)[^"\'>]*)\2/i',
            static function (array $matches): string {
                $baseUrl = Auth::currentBaseUrl();
                if ($baseUrl === '') {
                    return $matches[0];
                }
                return $matches[1] . $matches[2] . $baseUrl . $matches[3] . $matches[2];
            },
            $rendered
        );
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
            self::keepUsedEmbeds($html, $embeds)
        );
    }

    public static function sendRawEmail(array $partner, string $to, string $subject, string $html, array $embeds = []): void
    {
        self::deliver($partner, $to, $subject, $html, null, self::keepUsedEmbeds($html, $embeds));
    }

    /**
     * Callers (ReservationsController) always build a "photo_bien" CID
     * embed for the property photo, whether or not the partner's template
     * actually references {{photo_bien}} in its body (a custom template may
     * instead use {{photo1_url}} to hotlink the photo, or omit it entirely).
     * Attaching an inline image whose Content-ID is never referenced by the
     * HTML ("cid:...") makes several mail clients (Gmail, Outlook…) render
     * it as a plain attachment shown below the message body — looking like
     * a duplicated/stray property photo. Drop any embed whose cid isn't
     * actually used in the final HTML before sending.
     *
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds
     * @return array<int, array{cid: string, data: string, mime: string}>
     */
    private static function keepUsedEmbeds(string $html, array $embeds): array
    {
        return array_values(array_filter(
            $embeds,
            static fn (array $embed): bool => str_contains($html, 'cid:' . ($embed['cid'] ?? ''))
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
            self::sendSmtp($config, $to, $subject, $html, $replyTo, $embeds);
            return;
        }

        $boundary = self::boundary();
        $headers = [
            'MIME-Version: 1.0',
            'From: "' . addslashes(self::stripCrlf($config['from_name'])) . '" <' . $config['from_email'] . '>',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        if ($embeds !== []) {
            $headers[] = 'Content-Type: multipart/related; boundary="' . $boundary . '"';
            $body = self::buildRelatedBody($boundary, $html, $embeds);
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $body = $html;
        }

        if (!@mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('Unable to send email via mail()');
        }
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

    private static function sendSmtp(array $config, string $to, string $subject, string $html, ?string $replyTo, array $embeds = []): void
    {
        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $transport = $port === 465 ? 'ssl://' . $host : $host;
        $socket = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($socket, 15);

        self::expect($socket, [220]);
        self::command($socket, 'EHLO localhost', [250]);

        if ($transport === $host && function_exists('stream_socket_enable_crypto')) {
            self::command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            self::command($socket, 'EHLO localhost', [250]);
        }

        if (!empty($config['user'])) {
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode((string) $config['user']), [334]);
            self::command($socket, base64_encode((string) $config['pass']), [235]);
        }

        $fromEmail = (string) $config['from_email'];
        self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        self::command($socket, 'DATA', [354]);

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
            $headers[] = 'Content-Type: multipart/related; boundary="' . $boundary . '"';
            $body = self::buildRelatedBody($boundary, $html, $embeds);
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $html;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($body) . "\r\n.";
        fwrite($socket, $message . "\r\n");
        self::expect($socket, [250]);
        self::command($socket, 'QUIT', [221]);
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

    private static function command($socket, string $command, array $okCodes): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $okCodes);
    }

    private static function expect($socket, array $okCodes): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
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
