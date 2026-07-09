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

    public static function sendTemplatedEmail(array $partner, array $template, string $to, array $variables): void
    {
        self::deliver(
            $partner,
            $to,
            self::renderTemplate((string) $template['subject'], $variables),
            self::renderTemplate((string) $template['body_html'], $variables)
        );
    }

    public static function sendRawEmail(array $partner, string $to, string $subject, string $html): void
    {
        self::deliver($partner, $to, $subject, $html);
    }

    public static function sendContactEmail(array $partner, string $replyTo, string $subject, string $html): void
    {
        self::deliver($partner, (string) $partner['email'], $subject, $html, $replyTo);
    }

    private static function deliver(array $partner, string $to, string $subject, string $html, ?string $replyTo = null): void
    {
        $to = self::sanitizeAddress($to, 'recipient');
        $subject = self::stripCrlf($subject);
        if ($replyTo !== null) {
            $replyTo = self::sanitizeAddress($replyTo, 'Reply-To');
        }

        $config = [
            'host' => $partner['smtp_host'] ?? Env::get('SMTP_HOST', ''),
            'port' => (int) ($partner['smtp_port'] ?? Env::int('SMTP_PORT', 25)),
            'user' => $partner['smtp_user'] ?? Env::get('SMTP_USER', ''),
            'pass' => $partner['smtp_pass'] ?? Env::get('SMTP_PASS', ''),
            'from_email' => (string) ($partner['email'] ?? Env::get('SMTP_FROM_EMAIL', 'no-reply@example.com')),
            'from_name' => (string) ($partner['name'] ?? Env::get('SMTP_FROM_NAME', 'samchlolaurepartners')),
        ];

        if (!empty($config['host'])) {
            self::sendSmtp($config, $to, $subject, $html, $replyTo);
            return;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: "' . addslashes(self::stripCrlf($config['from_name'])) . '" <' . $config['from_email'] . '>',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        if (!@mail($to, $subject, $html, implode("\r\n", $headers))) {
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

    private static function sendSmtp(array $config, string $to, string $subject, string $html, ?string $replyTo): void
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

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'To: <' . $to . '>',
            'From: "' . addslashes(self::stripCrlf((string) $config['from_name'])) . '" <' . $fromEmail . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.";
        fwrite($socket, $message . "\r\n");
        self::expect($socket, [250]);
        self::command($socket, 'QUIT', [221]);
        fclose($socket);
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
