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

    /**
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     *        Images to embed directly inside the message (multipart/related) so
     *        they render even when the recipient's mail client can't fetch a
     *        remote/hotlinked <img src> URL. Each entry's "cid" must match the
     *        "cid:" reference used in the HTML body.
     */
    public static function sendTemplatedEmail(array $partner, array $template, string $to, array $variables, array $inlineImages = []): void
    {
        self::deliver(
            $partner,
            $to,
            self::renderTemplate((string) $template['subject'], $variables),
            self::renderTemplate((string) $template['body_html'], $variables),
            null,
            $inlineImages
        );
    }

    /**
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     */
    public static function sendRawEmail(array $partner, string $to, string $subject, string $html, array $inlineImages = []): void
    {
        self::deliver($partner, $to, $subject, $html, null, $inlineImages);
    }

    public static function sendContactEmail(array $partner, string $replyTo, string $subject, string $html): void
    {
        self::deliver($partner, (string) $partner['email'], $subject, $html, $replyTo);
    }

    /**
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     */
    private static function deliver(array $partner, string $to, string $subject, string $html, ?string $replyTo = null, array $inlineImages = []): void
    {
        $to = self::sanitizeAddress($to, 'recipient');
        $subject = self::stripCrlf($subject);
        if ($replyTo !== null) {
            $replyTo = self::sanitizeAddress($replyTo, 'Reply-To');
        }

        $config = [
            'host' => $partner['smtp_host'] ?? Settings::get('SMTP_HOST', ''),
            'port' => (int) ($partner['smtp_port'] ?? Settings::int('SMTP_PORT', 25)),
            'user' => $partner['smtp_user'] ?? Settings::get('SMTP_USER', ''),
            'pass' => $partner['smtp_pass'] ?? Settings::get('SMTP_PASS', ''),
            'from_email' => (string) ($partner['email'] ?? Settings::get('SMTP_FROM_EMAIL', 'no-reply@example.com')),
            'from_name' => (string) ($partner['name'] ?? Settings::get('SMTP_FROM_NAME', 'samchlolaurepartners')),
        ];

        if (!empty($config['host'])) {
            self::sendSmtp($config, $to, $subject, $html, $replyTo, $inlineImages);
            return;
        }

        [$contentType, $body] = self::buildBody($html, $inlineImages);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType,
            'From: "' . addslashes(self::stripCrlf($config['from_name'])) . '" <' . $config['from_email'] . '>',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
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

    private static function sendSmtp(array $config, string $to, string $subject, string $html, ?string $replyTo, array $inlineImages = []): void
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

        if ($port === 587 && function_exists('stream_socket_enable_crypto')) {
            self::command($socket, 'STARTTLS', [220]);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
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

        [$contentType, $body] = self::buildBody($html, $inlineImages);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'To: <' . $to . '>',
            'From: "' . addslashes(self::stripCrlf((string) $config['from_name'])) . '" <' . $fromEmail . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType,
        ];
        if ($inlineImages === []) {
            // Multipart bodies declare a Content-Transfer-Encoding per part, so
            // only set a top-level one for the simple single-part message.
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        }
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $message = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($body) . "\r\n.";
        fwrite($socket, $message . "\r\n");
        self::expect($socket, [250]);
        self::command($socket, 'QUIT', [221]);
        fclose($socket);
    }

    /**
     * Builds the MIME body for a message. When inline images are supplied the
     * message is wrapped as multipart/related so the images travel *inside* the
     * email (referenced by "cid:") and render without the recipient's client
     * having to fetch any remote/hotlinked URL — the previous approach, which
     * broke in many mail clients. Returns [contentTypeHeaderValue, body].
     *
     * @param array<int,array{cid:string,data:string,mime:string}> $inlineImages
     * @return array{0:string,1:string}
     */
    private static function buildBody(string $html, array $inlineImages): array
    {
        if ($inlineImages === []) {
            return ['text/html; charset=UTF-8', $html];
        }

        $boundary = 'rel_' . bin2hex(random_bytes(16));
        $lines = [];
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $html;

        foreach ($inlineImages as $image) {
            $cid = self::stripCrlf((string) ($image['cid'] ?? ''));
            $data = (string) ($image['data'] ?? '');
            if ($cid === '' || $data === '') {
                continue;
            }
            $mime = self::stripCrlf((string) ($image['mime'] ?? 'application/octet-stream'));
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: ' . $mime;
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = 'Content-ID: <' . $cid . '>';
            $lines[] = 'Content-Disposition: inline';
            $lines[] = '';
            $lines[] = trim(chunk_split(base64_encode($data), 76, "\r\n"));
        }

        $lines[] = '--' . $boundary . '--';

        return [
            'multipart/related; boundary="' . $boundary . '"',
            implode("\r\n", $lines),
        ];
    }

    /**
     * Dot-stuffing per RFC 5321 §4.5.2: any body line starting with '.' must be
     * escaped with an extra leading '.' so it isn't mistaken for the DATA
     * terminator. Also normalises bare LFs to CRLF for SMTP transport.
     */
    private static function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);
        return implode("\r\n", $lines);
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
}
