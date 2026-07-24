<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Mailer
{
    /** Hard cap on a single externally-fetched (non-local) embedded image, to bound memory/bandwidth use. */
    private const MAX_EXTERNAL_IMAGE_BYTES = 5 * 1024 * 1024;

    public static function renderTemplate(string $template, array $variables): string
    {
        // Support "{{var1}}+{{var2}}(+{{var3}}...)" expressions in the
        // template body: when every referenced variable resolves to a plain
        // number or a formatted money amount (e.g. "1 234,56 EUR"), the
        // whole expression is replaced by their sum instead of being left as
        // separate values glued to a literal "+".
        $template = preg_replace_callback(
            '/\{\{[a-zA-Z0-9_]+\}\}(?:\s*\+\s*\{\{[a-zA-Z0-9_]+\}\})+/',
            static function (array $matches) use ($variables): string {
                return self::sumVariableExpression($matches[0], $variables);
            },
            $template
        ) ?? $template;

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

    /**
     * Resolves a "{{var1}}+{{var2}}(+...)" expression to the sum of the
     * referenced variables when every one of them is a plain number or a
     * money-formatted amount (as produced by
     * ReservationsController::formatMoneyFr(), e.g. "1 234,56 EUR"). If any
     * variable is missing or not numeric, the expression is left untouched
     * so the surrounding single-variable substitution still applies to each
     * {{name}} token individually.
     */
    private static function sumVariableExpression(string $expression, array $variables): string
    {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $expression, $names);

        $sum = 0.0;
        $suffix = null;
        $hasDecimals = false;

        foreach ($names[1] as $name) {
            $value = $variables[$name] ?? null;
            if ($value instanceof \Closure || (is_object($value) && is_callable($value))) {
                $value = $value(null);
            }
            if ($value === null) {
                return $expression;
            }

            $parsed = self::parseNumericAmount((string) $value);
            if ($parsed === null) {
                return $expression;
            }

            $sum += $parsed['amount'];
            if ($parsed['decimals']) {
                $hasDecimals = true;
            }
            if ($parsed['suffix'] !== '') {
                $suffix = $parsed['suffix'];
            }
        }

        $formatted = number_format($sum, $hasDecimals || $suffix !== null ? 2 : 0, ',', ' ');

        return $suffix !== null ? $formatted . ' ' . $suffix : $formatted;
    }

    /**
     * @return array{amount: float, decimals: bool, suffix: string}|null
     */
    private static function parseNumericAmount(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        // Accepts plain numbers ("3", "12.5") and money-formatted amounts
        // ("1 234,56", "1 234,56 EUR", "1 234,56 €"), with a French
        // (space thousands, comma decimals) or plain decimal notation.
        if (!preg_match('/^(-?[0-9][0-9\x{00A0}\s]*(?:[.,][0-9]+)?)\s*([A-Za-zÀ-ÿ€$£]{0,10})$/u', $trimmed, $matches)) {
            return null;
        }

        $numberPart = str_replace(["\xC2\xA0", ' '], '', $matches[1]);
        $hasDecimals = strpos($numberPart, ',') !== false || strpos($numberPart, '.') !== false;
        $numberPart = str_replace(',', '.', $numberPart);
        if (!is_numeric($numberPart)) {
            return null;
        }

        return [
            'amount' => (float) $numberPart,
            'decimals' => $hasDecimals,
            'suffix' => trim($matches[2]),
        ];
    }

    public static function sendTemplatedEmail(array $partner, array $template, string $to, array $variables, array $embeds = []): void
    {
        $html = self::renderTemplate((string) $template['body_html'], $variables);
        $inlined = self::embedHotlinkedImages($html, $embeds);
        self::deliver(
            $partner,
            $to,
            self::renderTemplate((string) $template['subject'], $variables),
            $inlined['html'],
            null,
            self::filterUnusedEmbeds($inlined['html'], $inlined['embeds'])
        );
    }

    public static function sendRawEmail(array $partner, string $to, string $subject, string $html, array $embeds = []): void
    {
        $inlined = self::embedHotlinkedImages($html, $embeds);
        self::deliver($partner, $to, $subject, $inlined['html'], null, self::filterUnusedEmbeds($inlined['html'], $inlined['embeds']));
    }

    /**
     * Converts every remaining hotlinked <img src="http(s)://..."> in the
     * final rendered HTML into an inline Content-ID embed, so a recipient's
     * mail client never has to fetch a remote image just to display the
     * message — several providers (Microsoft/Outlook among them) treat
     * hotlinked remote images as a spam signal and are more likely to bin
     * such messages. This runs as a last, generic pass over the fully
     * rendered HTML, so it transparently covers every image source: the
     * property photo/logo/signature variables (which mostly already embed
     * local files directly), any {{photoN_url}}/{{logo_partenaire_url}}/
     * {{signature_photo_url}} used raw in an <img> tag, and any image
     * inserted from the WYSIWYG "Mini galerie graphique".
     *
     * Local site-hosted images are read straight off disk. A genuinely
     * external image (e.g. a partner pasting an external logo URL) is
     * fetched once with a short timeout, best-effort: if that fetch fails,
     * the original hotlinked src is left untouched rather than failing the
     * whole send.
     *
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds Already-known embeds (photo_bien, signature, ...)
     * @return array{html: string, embeds: array<int, array{cid: string, data: string, mime: string}>}
     */
    private static function embedHotlinkedImages(string $html, array $embeds): array
    {
        $baseUrl = Auth::currentBaseUrl();
        $rootPath = realpath(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));

        $html = (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc=)(["\'])(https?:\/\/[^"\'>]+)\2/i',
            static function (array $matches) use (&$embeds, $baseUrl, $rootPath): string {
                $url = $matches[3];
                $data = null;
                $mime = null;

                if ($baseUrl !== '' && str_starts_with($url, $baseUrl . '/')) {
                    $relative = substr($url, strlen($baseUrl));
                    $path = ($rootPath !== false ? $rootPath : '') . parse_url($relative, PHP_URL_PATH);
                    $realPath = $rootPath !== false ? realpath($path) : false;
                    if ($realPath !== false && str_starts_with($realPath, $rootPath) && is_file($realPath)) {
                        $fileData = @file_get_contents($realPath);
                        if ($fileData !== false && $fileData !== '') {
                            $data = $fileData;
                            $mime = self::detectImageMime($data, pathinfo($realPath, PATHINFO_EXTENSION));
                        }
                    }
                }

                if ($data === null && self::isFetchableExternalImageUrl($url)) {
                    $context = stream_context_create([
                        'http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0],
                        'https' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0],
                    ]);
                    $fetched = @file_get_contents($url, false, $context, 0, self::MAX_EXTERNAL_IMAGE_BYTES);
                    if ($fetched !== false && $fetched !== '' && self::looksLikeImageData($fetched)) {
                        $data = $fetched;
                        $mime = self::detectImageMime($data, pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    }
                }

                if ($data === null || $data === '') {
                    return $matches[0];
                }

                $cid = 'inline-' . bin2hex(random_bytes(6)) . '@local';
                $embeds[] = ['cid' => $cid, 'data' => $data, 'mime' => $mime ?: 'image/jpeg'];
                return $matches[1] . $matches[2] . 'cid:' . $cid . $matches[2];
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'embeds' => $embeds];
    }

    /**
     * Guards against SSRF when embedHotlinkedImages() falls back to fetching
     * a genuinely external image URL (e.g. a partner-pasted external logo):
     * only http(s) URLs whose host resolves to a public, non-reserved IP
     * address are fetched. This blocks a partner-controlled template/logo
     * URL from being used to reach loopback, private (RFC1918/RFC4193),
     * link-local, or other reserved addresses (e.g. cloud metadata
     * endpoints) from the server.
     */
    private static function isFetchableExternalImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Confirms fetched bytes actually decode as an image before embedding
     * them, so a URL that (deliberately or not) doesn't serve an image
     * can't be embedded as one.
     */
    private static function looksLikeImageData(string $data): bool
    {
        return @getimagesizefromstring($data) !== false;
    }

    /**
     * Best-effort image MIME sniffing from raw bytes (via fileinfo), falling
     * back to the file extension when fileinfo is unavailable or the data
     * isn't recognized as an image.
     */
    private static function detectImageMime(string $data, string $fallbackExtension): string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $data);
                finfo_close($finfo);
                if (is_string($mime) && str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }

        return match (strtolower($fallbackExtension)) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };
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

    public static function sendContactEmail(array $partner, string $to, string $subject, string $html, ?string $replyTo = null): void
    {
        self::deliver($partner, $to, $subject, $html, $replyTo);
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
                $mime = self::buildMimeMessage($html, $embeds);
                $headers = [
                    'MIME-Version: 1.0',
                    'Date: ' . date(DATE_RFC2822),
                    'Message-ID: ' . self::messageId((string) $config['from_email']),
                    'From: "' . addslashes(self::stripCrlf($config['from_name'])) . '" <' . $config['from_email'] . '>',
                ];
                if ($replyTo) {
                    $headers[] = 'Reply-To: ' . $replyTo;
                }
                // Always send as multipart/alternative with a plain-text part
                // alongside the HTML: several providers (notably Microsoft/
                // Outlook and Gmail's spam filters) treat HTML-only messages
                // with no text/plain alternative as a strong spam signal.
                // This never changes what the recipient sees in an HTML-capable
                // client — it just adds a fallback text version they'd only
                // ever see in a plain-text-only reader.
                $headers[] = $mime['contentType'];
                $body = $mime['body'];

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
            'Message-ID: ' . self::messageId($fromEmail),
            'To: <' . $to . '>',
            'From: "' . addslashes(self::stripCrlf((string) $config['from_name'])) . '" <' . $fromEmail . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // Always send as multipart/alternative with a plain-text part
        // alongside the HTML: several providers (notably Microsoft/Outlook
        // and Gmail's spam filters) treat HTML-only messages with no
        // text/plain alternative as a strong spam signal, which was causing
        // otherwise-legitimate reservation emails to land in Junk. This
        // never changes what the recipient sees in an HTML-capable client —
        // it just adds a fallback text version they'd only ever see in a
        // plain-text-only reader.
        $mime = self::buildMimeMessage($html, $embeds);
        $headers[] = $mime['contentType'];
        $body = $mime['body'];

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
     * Wraps the HTML body (and its inline embeds, if any) in a
     * multipart/alternative envelope alongside a plain-text rendering, so
     * every outgoing message always has a text/plain part. Sending
     * HTML-only messages with no alternative text part is a well-known
     * spam signal for several providers (Microsoft/Outlook and Gmail among
     * them), which contributed to legitimate reservation emails landing in
     * Junk. HTML-capable clients still render the exact same HTML as
     * before — only plain-text-only readers ever see the fallback part.
     *
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds
     * @return array{contentType: string, body: string}
     */
    private static function buildMimeMessage(string $html, array $embeds): array
    {
        $altBoundary = self::boundary();
        $textPart = "--{$altBoundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . self::htmlToPlainText($html);

        if ($embeds !== []) {
            $relatedBoundary = self::boundary();
            $htmlPart = "--{$altBoundary}\r\n"
                // See buildRelatedBody()'s caller for why the "type" param is
                // required (RFC 2387 §3.1) so cid: references resolve inline.
                . "Content-Type: multipart/related; type=\"text/html\"; boundary=\"{$relatedBoundary}\"\r\n\r\n"
                . self::buildRelatedBody($relatedBoundary, $html, $embeds);
        } else {
            $htmlPart = "--{$altBoundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $html;
        }

        return [
            'contentType' => 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
            'body' => $textPart . "\r\n" . $htmlPart . "\r\n--{$altBoundary}--",
        ];
    }

    /**
     * Best-effort plain-text rendering of an email's HTML body, used only
     * for the text/plain alternative part (see buildMimeMessage()) — never
     * for what an HTML-capable client displays.
     */
    private static function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<(br|\/tr|\/table|\/p|\/div|\/h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Generates a unique Message-ID header. Its absence is another common
     * spam signal several providers weigh heavily; every message must have
     * exactly one.
     */
    private static function messageId(string $fromEmail): string
    {
        $domain = 'local';
        $at = strrpos($fromEmail, '@');
        if ($at !== false) {
            $candidate = substr($fromEmail, $at + 1);
            if ($candidate !== '') {
                $domain = $candidate;
            }
        }

        return '<' . bin2hex(random_bytes(16)) . '.' . (string) time() . '@' . $domain . '>';
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
