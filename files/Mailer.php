<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Mailer
{
    /** Hard cap on a single externally-fetched (non-local) embedded image, to bound memory/bandwidth use. */
    private const MAX_EXTERNAL_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * Hosts an outgoing email must never point at, nor fetch from, under any
     * circumstance: Lodgify's API/CDN image hosts. Every property photo shown
     * in an email is served from our own hosting (the locally-synced copies
     * under images/listings/, see ImageCache::cache()), so a Lodgify URL can
     * only ever appear in an email because a sync fell back to the remote URL
     * or because someone pasted one into a template by hand. Hotlinking those
     * makes each recipient's mail client hit Lodgify (slow to open, and extra
     * load on Lodgify's API), so such <img> tags are dropped from the message
     * entirely instead — see embedHotlinkedImages().
     */
    private const BLOCKED_IMAGE_HOST_SUFFIXES = ['lodgify.com', 'icdbcdn.com', 'lodgify.net'];

    /**
     * Width (px) every embedded image is capped to when the <img> tag itself
     * declares no pixel width (neither a width attribute nor an inline CSS
     * pixel width) — e.g. a fluid "width:100%" image. Roughly the widest a
     * message body is ever rendered at, so recipients see no difference,
     * while a 1920px synced photo is never embedded at full resolution.
     */
    private const MAX_EMAIL_IMAGE_WIDTH = 800;

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

    public static function sendTemplatedEmail(array $partner, array $template, string $to, array $variables, array $embeds = [], ?string $replyTo = null): void
    {
        $html = self::renderTemplate((string) $template['body_html'], $variables);
        self::sendWithEmbeds($partner, $to, self::renderTemplate((string) $template['subject'], $variables), $html, $embeds, $replyTo);
    }

    public static function sendRawEmail(array $partner, string $to, string $subject, string $html, array $embeds = [], ?string $replyTo = null): void
    {
        self::sendWithEmbeds($partner, $to, $subject, $html, $embeds, $replyTo);
    }

    /**
     * Shared final step of every send*() entry point: inlines hotlinked
     * images (resizing each one down to the width the template actually
     * displays it at along the way, see embedHotlinkedImages()) and hands
     * off to deliver(). Any temp files staged during resizing (see
     * resizeForEmail()) are always removed once this send attempt is over,
     * whether it succeeded or the underlying deliver() call threw.
     */
    private static function sendWithEmbeds(array $partner, string $to, string $subject, string $html, array $embeds, ?string $replyTo): void
    {
        $tempFiles = [];
        try {
            $inlined = self::embedHotlinkedImages($html, $embeds, $tempFiles);
            self::deliver($partner, $to, $subject, $inlined['html'], $replyTo, self::filterUnusedEmbeds($inlined['html'], $inlined['embeds']));
        } finally {
            self::cleanupTempFiles($tempFiles);
        }
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
     * Every image is also downscaled (see resizeForEmail()) to the pixel
     * width the template actually displays it at — the tag's own "width"
     * attribute (e.g. width="320") — before being embedded, instead of
     * inlining whatever resolution happens to be stored on disk (a synced
     * property photo can be up to 1920px wide). This keeps the outgoing
     * message payload proportional to what the recipient will actually
     * see, which matters both for send time and for spam/junk filters that
     * penalize oversized messages.
     *
     * @param array<int, array{cid: string, data: string, mime: string}> $embeds Already-known embeds (photo_bien, signature, ...)
     * @param list<string> $tempFiles Populated with the path of every temp file staged by resizeForEmail(), for the
     *        caller to delete once the send attempt is over (see sendWithEmbeds()/cleanupTempFiles()).
     * @return array{html: string, embeds: array<int, array{cid: string, data: string, mime: string}>}
     */
    private static function embedHotlinkedImages(string $html, array $embeds, array &$tempFiles): array
    {
        // Compared by host only (see normalizeHostForComparison()), not by a
        // literal string prefix on the full base URL: an <img src> can end
        // up pointing at our own site under a scheme/host that differs
        // slightly from what Auth::currentBaseUrl() resolves to for *this*
        // send (e.g. "http://" vs "https://", or "www." vs no "www.") —
        // most commonly because reminder/scheduled sends run from cron,
        // with no request host to go by, so they fall back to the fixed
        // APP_URL setting, which doesn't necessarily match every hostname
        // the site is actually reachable under, or whatever host an image
        // URL was authored/imported with. Treating that as "external"
        // would force a live HTTP fetch back to our own server just to
        // re-download a file that's already sitting right here on disk — a
        // self-referencing ("hairpin") request many hosts block or that
        // simply fails — leaving the image hotlinked instead of embedded
        // even though embedding it locally would have worked fine.
        $baseHost = self::normalizeHostForComparison((string) parse_url(Auth::currentBaseUrl(), PHP_URL_HOST));
        $rootPath = realpath(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));

        // Same image URL displayed at the same size twice in a template (a
        // logo repeated in header and footer, the same photo in two blocks,
        // ...) must be embedded once and referenced by the same cid, not
        // attached once per occurrence — duplicated base64 payloads are the
        // main reason a message becomes needlessly heavy and slow to open.
        $embedCache = [];

        $html = (string) preg_replace_callback(
            '/(<img\b[^>]*\ssrc=)(["\'])(https?:\/\/[^"\'>]+)\2([^>]*)>/i',
            static function (array $matches) use (&$embeds, &$tempFiles, &$embedCache, $baseHost, $rootPath): string {
                $url = $matches[3];

                // Never let an email point at (or make the server fetch from)
                // Lodgify: drop the tag entirely rather than hotlinking it.
                if (self::isBlockedImageHost($url)) {
                    error_log('Mailer: dropped Lodgify-hosted image from outgoing email (' . $url . '); only locally-synced photos may be used.');
                    return '';
                }

                $targetWidth = self::extractImgWidth($matches[0]);
                $targetHeight = self::extractImgHeight($matches[0]);
                $cacheKey = $url . '|' . ($targetWidth ?? 0) . 'x' . ($targetHeight ?? 0);
                if (isset($embedCache[$cacheKey])) {
                    return $matches[1] . $matches[2] . 'cid:' . $embedCache[$cacheKey] . $matches[2] . $matches[4] . '>';
                }

                $data = null;
                $mime = null;

                $realPath = self::localFileForUrl($url, $baseHost, $rootPath);
                if ($realPath !== null) {
                    $fileData = @file_get_contents($realPath);
                    if ($fileData !== false && $fileData !== '') {
                        $data = $fileData;
                        $mime = self::detectImageMime($data, pathinfo($realPath, PATHINFO_EXTENSION));
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

                if ($targetWidth !== null && $targetHeight !== null) {
                    // Both dimensions are fixed (e.g. the photo1/photo2/photo3
                    // slots, always inserted as a fixed WxH box): crop the
                    // actual bytes to that exact box instead of only scaling
                    // to width, so photo1/photo2/photo3 always embed at the
                    // same pixel size without ever distorting proportions,
                    // even in mail clients that ignore CSS object-fit.
                    $data = self::cropForEmail($data, $targetWidth, $targetHeight, $tempFiles);
                } elseif ($targetWidth !== null) {
                    $data = self::resizeForEmail($data, $targetWidth, $tempFiles);
                } else {
                    // No displayed pixel width could be determined (e.g. a
                    // fluid "width:100%" image): fall back to a generic cap
                    // so a full-resolution photo (up to 1920px wide once
                    // synced) is never embedded as-is, which is what made
                    // messages heavy and slow to open.
                    $data = self::resizeForEmail($data, self::MAX_EMAIL_IMAGE_WIDTH, $tempFiles);
                }

                $cid = 'inline-' . bin2hex(random_bytes(6)) . '@local';
                $embeds[] = ['cid' => $cid, 'data' => $data, 'mime' => $mime ?: 'image/jpeg'];
                $embedCache[$cacheKey] = $cid;
                return $matches[1] . $matches[2] . 'cid:' . $cid . $matches[2] . $matches[4] . '>';
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'embeds' => $embeds];
    }

    /**
     * True when $url points at Lodgify (API or CDN). Emails must never
     * hotlink such a URL, nor make the server fetch it: every property photo
     * used in an email is the locally-synced copy under images/listings/
     * (ImageCache::cache()), so a Lodgify URL in an outgoing message would
     * only add latency for the recipient and needless load on Lodgify.
     */
    private static function isBlockedImageHost(string $url): bool
    {
        $host = self::normalizeHostForComparison((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach (self::BLOCKED_IMAGE_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Maps an absolute image URL back to the file it refers to on this
     * server, or null when it isn't one of ours. The host is compared with
     * Auth::currentBaseUrl()'s (see normalizeHostForComparison()) but, as a
     * failsafe, a URL under one of the site's own public asset directories
     * (/images/, /assets/, /install/) whose file genuinely exists on disk is
     * also served from disk whatever host it was authored with: scheduled/
     * cron sends have no request host to go by and fall back to APP_URL,
     * which doesn't necessarily match every hostname the site is reachable
     * under. Without this, embedding would fall back to a live HTTP request
     * back to our own server ("hairpin"), which many hosts block — leaving
     * the image hotlinked (slow, and often blocked by the mail client) even
     * though the exact bytes were sitting right here on disk.
     *
     * @param string|false $rootPath realpath(BASE_PATH), or false when unresolvable
     */
    private static function localFileForUrl(string $url, string $baseHost, $rootPath): ?string
    {
        if ($rootPath === false) {
            return null;
        }

        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        if ($urlPath === '') {
            return null;
        }
        $urlPath = rawurldecode($urlPath);

        $urlHost = self::normalizeHostForComparison((string) parse_url($url, PHP_URL_HOST));
        $sameHost = $baseHost !== '' && $urlHost === $baseHost;
        $isPublicAssetPath = (bool) preg_match('#^/(images|assets|install)/#', $urlPath);
        if (!$sameHost && !$isPublicAssetPath) {
            return null;
        }

        $realPath = realpath($rootPath . $urlPath);
        if ($realPath === false || !str_starts_with($realPath, $rootPath . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Lowercases a host and strips a leading "www." so e.g. "Example.com"
     * and "www.example.com" compare equal — see embedHotlinkedImages() for
     * why treating those as the same site matters. Returns '' for an
     * empty/absent host so callers never treat "no host" (a malformed URL)
     * as matching another empty/absent host.
     */
    private static function normalizeHostForComparison(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Reads the pixel width the template actually displays the image at:
     * the numeric "width" HTML attribute (e.g. width="320") when present,
     * otherwise a pixel width declared in the tag's inline CSS
     * (style="width:320px" / "max-width:320px"), which is how images
     * inserted from the WYSIWYG editor or imported from a Canva export are
     * usually sized. Percentage CSS widths are ignored (no pixel target can
     * be derived from them). Returns null when no pixel width can be
     * determined, so the caller falls back to the generic email width cap
     * instead of guessing.
     */
    private static function extractImgWidth(string $imgTag): ?int
    {
        if (preg_match('/\swidth\s*=\s*(["\']?)(\d+)\1/i', $imgTag, $match) === 1) {
            $width = (int) $match[2];
            if ($width > 0) {
                return $width;
            }
        }
        return self::extractCssPixelLength($imgTag, 'width');
    }

    /**
     * Same as extractImgWidth() but for the "height" HTML attribute, used
     * alongside it to detect a fixed WxH box (e.g. the photo1/photo2/photo3
     * template slots) so that box can be cropped to instead of only scaled
     * by width (see cropForEmail()).
     */
    private static function extractImgHeight(string $imgTag): ?int
    {
        if (preg_match('/\sheight\s*=\s*(["\']?)(\d+)\1/i', $imgTag, $match) === 1) {
            $height = (int) $match[2];
            if ($height > 0) {
                return $height;
            }
        }
        return self::extractCssPixelLength($imgTag, 'height');
    }

    /**
     * Reads a pixel length ("320px") for a CSS property declared in the
     * tag's inline style attribute, trying the property itself first
     * ("width:320px") then its "max-" variant ("max-width:320px").
     * Percentages, "auto" and any other unit yield null, since no reliable
     * pixel target can be derived from them.
     */
    private static function extractCssPixelLength(string $imgTag, string $property): ?int
    {
        if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $imgTag, $styleMatch) !== 1) {
            return null;
        }
        $style = $styleMatch[2];

        foreach ([$property, 'max-' . $property] as $name) {
            if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*:\s*(\d+(?:\.\d+)?)\s*px\s*(?:;|$)/i', $style, $match) === 1) {
                $value = (int) round((float) $match[1]);
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Downscales $data to at most $targetWidth px — the width the template
     * actually displays the image at — before it's embedded, reusing the
     * exact same GD downscale logic already relied on for synced property
     * photos (ImageCache::resizeIfTooWide()): never upscales, preserves
     * format/transparency, and is a no-op if GD is unavailable or the image
     * can't be decoded/re-encoded.
     *
     * The resized bytes are also staged to a dedicated temp directory
     * (under the OS temp dir) purely so a resize this size never lingers
     * only in memory for the whole request; every path written here is
     * collected into $tempFiles and removed by cleanupTempFiles() once the
     * current send attempt (success or failure) is over.
     */
    private static function resizeForEmail(string $data, int $targetWidth, array &$tempFiles): string
    {
        $resized = ImageCache::resizeIfTooWide($data, $targetWidth);
        if ($resized === $data) {
            // Nothing to stage: already narrow enough, or resizing wasn't possible.
            return $data;
        }

        $tempDir = sys_get_temp_dir() . '/email-image-resize';
        if (is_dir($tempDir) || @mkdir($tempDir, 0775, true)) {
            $tempPath = $tempDir . '/' . bin2hex(random_bytes(8)) . '.tmp';
            if (@file_put_contents($tempPath, $resized) !== false) {
                $tempFiles[] = $tempPath;
            }
        }

        return $resized;
    }

    /**
     * Crops $data to an exact $targetWidth x $targetHeight box (see
     * ImageCache::resizeCover()) before it's embedded, so an <img> tag with
     * both width and height fixed (e.g. photo1/photo2/photo3) always embeds
     * bytes at that exact pixel size — guaranteeing identical dimensions
     * across every photo slot with no distortion, regardless of whether the
     * recipient's mail client honours CSS object-fit.
     *
     * Stages the cropped bytes the same way resizeForEmail() does, so they
     * are cleaned up by cleanupTempFiles() once the current send attempt is
     * over.
     */
    private static function cropForEmail(string $data, int $targetWidth, int $targetHeight, array &$tempFiles): string
    {
        $cropped = ImageCache::resizeCover($data, $targetWidth, $targetHeight);
        if ($cropped === $data) {
            // Nothing to stage: already the right size, or cropping wasn't possible.
            return $data;
        }

        $tempDir = sys_get_temp_dir() . '/email-image-resize';
        if (is_dir($tempDir) || @mkdir($tempDir, 0775, true)) {
            $tempPath = $tempDir . '/' . bin2hex(random_bytes(8)) . '.tmp';
            if (@file_put_contents($tempPath, $cropped) !== false) {
                $tempFiles[] = $tempPath;
            }
        }

        return $cropped;
    }

    /**
     * Deletes every temp file staged by resizeForEmail() during a single
     * send attempt. Always called from sendWithEmbeds()'s finally block, so
     * these files never accumulate even when the send itself fails.
     *
     * @param list<string> $tempFiles
     */
    private static function cleanupTempFiles(array $tempFiles): void
    {
        foreach ($tempFiles as $path) {
            @unlink($path);
        }
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
        // Goes through the same pipeline as every other send: any image in a
        // contact email is inlined (and Lodgify URLs dropped) instead of
        // being hotlinked.
        self::sendWithEmbeds($partner, $to, $subject, $html, [], $replyTo);
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
                // A malformed/empty Reply-To (e.g. a blank client email on an
                // older, pre-validation request row) must never abort the
                // whole send — it's a nice-to-have so replies route to the
                // other party, not a requirement for the message itself.
                $replyTo = trim($replyTo) !== '' && filter_var(trim($replyTo), FILTER_VALIDATE_EMAIL) !== false
                    ? self::sanitizeAddress($replyTo, 'Reply-To')
                    : null;
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

                $dkimHeader = self::dkimSignatureHeader($headers, $body, (string) $config['from_email']);
                if ($dkimHeader !== null) {
                    array_unshift($headers, $dkimHeader);
                    $trace[] = 'DKIM-Signature added';
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

    /**
     * Picks the hostname to announce in the SMTP EHLO command: the domain of
     * the authenticated From address (e.g. "grand-baie-maurice.com"), which
     * is the domain that actually publishes the SPF/DKIM/DMARC records,
     * falling back to the configured SMTP host if the From address is
     * somehow malformed. Never returns "localhost" (a common spam signal).
     */
    private static function ehloHostname(string $fromEmail, string $fallbackHost): string
    {
        $atPos = strrpos($fromEmail, '@');
        $domain = $atPos !== false ? substr($fromEmail, $atPos + 1) : '';
        $domain = self::stripCrlf($domain);

        return $domain !== '' ? $domain : $fallbackHost;
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

        // EHLO with "localhost" is a well-known spam signal: it never
        // resolves to the connecting host, and many receiving filters
        // (Gmail/iCloud included) weigh it against the sender's reputation.
        // Announcing the sender's own domain instead — the same domain that
        // publishes the SPF/DKIM/DMARC records — is what legitimate mail
        // scripts on shared hosting are expected to send.
        $ehloHost = self::ehloHostname((string) $config['from_email'], $host);

        self::expect($socket, [220], $trace);
        self::command($socket, 'EHLO ' . $ehloHost, [250], $trace);

        if ($transport === $host && function_exists('stream_socket_enable_crypto')) {
            self::command($socket, 'STARTTLS', [220], $trace);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $trace[] = 'STARTTLS negotiation failed';
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            $trace[] = 'STARTTLS negotiated';
            self::command($socket, 'EHLO ' . $ehloHost, [250], $trace);
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

        $dkimHeader = self::dkimSignatureHeader($headers, $body, $fromEmail);
        if ($dkimHeader !== null) {
            array_unshift($headers, $dkimHeader);
            $trace[] = 'DKIM-Signature added';
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

    /**
     * Builds a "DKIM-Signature:" header for the given message (RFC 6376,
     * rsa-sha256, relaxed/relaxed canonicalization) using the private key
     * configured in Settings (DKIM_DOMAIN/DKIM_SELECTOR/DKIM_PRIVATE_KEY).
     *
     * This exists because the outgoing mail server (A2Hosting/cPanel/Exim)
     * cannot be relied on to opportunistically sign every message submitted
     * over authenticated SMTP from a script — leading to messages that pass
     * SPF (envelope sender is authenticated) but have no valid DKIM
     * signature at all. Signing here removes that dependency entirely.
     *
     * Returns null (no signature added) when DKIM isn't configured, the
     * private key is invalid, or the From address's domain doesn't match
     * the configured DKIM domain (signing a mismatched domain would only
     * ever produce an invalid signature).
     *
     * @param list<string> $headers "Name: value" lines, in the order they'll be sent (no trailing CRLF, no DKIM-Signature line).
     */
    private static function dkimSignatureHeader(array $headers, string $body, string $fromEmail): ?string
    {
        $domain = trim((string) Settings::get('DKIM_DOMAIN', ''));
        $selector = trim((string) Settings::get('DKIM_SELECTOR', ''));
        $privateKeyPem = (string) Settings::get('DKIM_PRIVATE_KEY', '');
        if ($domain === '' || $selector === '' || trim($privateKeyPem) === '') {
            return null;
        }

        $atPos = strrpos($fromEmail, '@');
        $fromDomain = $atPos !== false ? substr($fromEmail, $atPos + 1) : '';
        if (strcasecmp($fromDomain, $domain) !== 0) {
            return null;
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return null;
        }

        // Only sign the headers that matter for spoofing/alignment and are
        // always present on every message this class sends; skipping
        // headers that vary per transport (e.g. only present in one of the
        // two send paths) keeps this list identical for both.
        $signableNames = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'];
        $parsed = [];
        foreach ($headers as $header) {
            $colon = strpos($header, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($header, 0, $colon));
            if (in_array(strtolower($name), $signableNames, true)) {
                $parsed[] = ['name' => $name, 'value' => substr($header, $colon + 1)];
            }
        }
        if ($parsed === []) {
            return null;
        }

        $bodyHash = base64_encode(hash('sha256', self::canonicalizeBodyRelaxed($body), true));
        $hList = implode(':', array_map(static fn (array $h): string => strtolower($h['name']), $parsed));

        $signatureTemplate = 'v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . $domain . '; s=' . $selector
            . '; h=' . $hList . '; bh=' . $bodyHash . '; b=';

        $signedData = '';
        foreach ($parsed as $header) {
            $signedData .= self::canonicalizeHeaderRelaxed($header['name'], $header['value']) . "\r\n";
        }
        $signedData .= self::canonicalizeHeaderRelaxed('DKIM-Signature', ' ' . $signatureTemplate);

        $signature = '';
        $signed = openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            return null;
        }

        return 'DKIM-Signature: ' . $signatureTemplate . base64_encode($signature);
    }

    private static function canonicalizeHeaderRelaxed(string $name, string $value): string
    {
        $value = str_replace(["\r\n", "\n"], '', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        return strtolower($name) . ':' . trim($value);
    }

    private static function canonicalizeBodyRelaxed(string $body): string
    {
        // Every body built by this class already uses "\r\n" line endings
        // consistently (see buildMimeMessage()/buildRelatedBody()).
        $lines = explode("\r\n", $body);
        $lines = array_map(static function (string $line): string {
            $line = preg_replace('/[ \t]+/', ' ', $line) ?? $line;
            return rtrim($line, " \t");
        }, $lines);

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines === [] ? '' : implode("\r\n", $lines) . "\r\n";
    }
}
