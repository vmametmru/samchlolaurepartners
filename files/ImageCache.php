<?php

declare(strict_types=1);

namespace App;

/**
 * Downloads remote Lodgify photos and keeps a local copy under images/listings/
 * so the property detail gallery serves cached files instead of hotlinking to
 * Lodgify's CDN. Falls back to the original remote URL whenever the download
 * fails (network error, invalid URL, ...), so the gallery never breaks.
 */
final class ImageCache
{
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Collects the reasons behind every cache() call that fell back to the
     * remote URL during the current request, so a manual sync can report
     * *why* images/listings/{id}/ ended up empty (permission denied, curl/SSL
     * failure, disk full, ...) instead of silently succeeding with nothing
     * written — the failures were previously only visible in the PHP error
     * log, which most shared-hosting admins can't easily read.
     */
    private static array $lastErrors = [];

    /**
     * Returns and clears the errors collected since the last call, so callers
     * (e.g. LodgifyClient::refreshAllPropertyDetails()) can report a fresh
     * batch per sync run.
     */
    public static function drainErrors(): array
    {
        $errors = self::$lastErrors;
        self::$lastErrors = [];
        return $errors;
    }

    private static function recordError(string $message): void
    {
        error_log('ImageCache: ' . $message);
        self::$lastErrors[] = $message;
    }

    /**
     * @param int|null $index When given (1-based), the photo is saved under a
     *                        normalized "photoN.ext" filename instead of a
     *                        content-hashed one, so the manual sync always
     *                        produces predictable names (photo1.jpg = first
     *                        photo, photo2.jpg = second, ...) that other code
     *                        (e.g. reservation emails) can link to directly.
     *                        Unlike the hash-based filename, "photoN" is not
     *                        content-addressed, so an existing file at that
     *                        position is always overwritten with whatever
     *                        Lodgify currently serves there.
     */
    public static function cache(string $remoteUrl, int $propertyId, ?int $index = null): string
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            // No URL at all for this photo slot: nothing to download, but
            // this is worth surfacing too — a previous version of this
            // method returned silently here (and on the scheme/host check
            // below), so a whole sync could report "terminée" with zero
            // photo_errors and refreshed=N even though not a single photo
            // was ever written to images/listings/, with no way to tell why.
            self::recordError('empty image URL received for property ' . $propertyId . ', nothing to cache');
            return $remoteUrl;
        }

        // Lodgify (and some proxies/CDNs) sometimes serve protocol-relative
        // URLs ("//cdn.example.com/photo.jpg") or HTML-entity-escaped ones
        // ("&amp;" instead of "&" in the query string); normalize both
        // before validating, instead of silently treating them as invalid
        // and falling back to the remote URL without ever attempting a
        // download.
        if (str_starts_with($remoteUrl, '//')) {
            $remoteUrl = 'https:' . $remoteUrl;
        }
        if (str_contains($remoteUrl, '&amp;')) {
            $remoteUrl = html_entity_decode($remoteUrl, ENT_QUOTES | ENT_HTML5);
        }

        $scheme = parse_url($remoteUrl, PHP_URL_SCHEME);
        $host = parse_url($remoteUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || !is_string($host) || $host === '') {
            self::recordError('invalid or unsupported image URL "' . $remoteUrl . '" for property ' . $propertyId . ' (scheme/host missing), falling back to remote URL as-is');
            return $remoteUrl;
        }

        $extension = self::extensionFromUrl($remoteUrl);
        $filename = $index !== null ? 'photo' . $index . $extension : sha1($remoteUrl) . $extension;
        $relativeDir = 'listings/' . $propertyId;
        $dir = BASE_PATH . '/images/' . $relativeDir;
        $localPath = $dir . '/' . $filename;
        $publicPath = '/images/' . $relativeDir . '/' . $filename;

        if ($index === null && is_file($localPath) && filesize($localPath) > 0) {
            return $publicPath;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $lastError = error_get_last();
            self::recordError('could not create directory ' . $dir . ' (' . ($lastError['message'] ?? 'permission denied ?') . '), falling back to remote URL for ' . $remoteUrl);
            return $remoteUrl;
        }

        [$data, $downloadError] = self::download($remoteUrl);
        if ($data === null || $data === '') {
            self::recordError('download failed for ' . $remoteUrl . ' (' . ($downloadError ?: 'unknown error') . '), falling back to remote URL');
            return $remoteUrl;
        }

        $tmpPath = $localPath . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmpPath, $data) === false) {
            $lastError = error_get_last();
            self::recordError('could not write ' . $tmpPath . ' (' . ($lastError['message'] ?? 'permission denied ?') . '), falling back to remote URL for ' . $remoteUrl);
            return $remoteUrl;
        }
        if (!@rename($tmpPath, $localPath)) {
            @unlink($tmpPath);
            $lastError = error_get_last();
            self::recordError('could not rename ' . $tmpPath . ' to ' . $localPath . ' (' . ($lastError['message'] ?? 'permission denied ?') . '), falling back to remote URL for ' . $remoteUrl);
            return $remoteUrl;
        }

        return $publicPath;
    }

    private static function extensionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? '.' . $extension : '.jpg';
    }

    /**
     * @return array{0: ?string, 1: ?string} [downloaded body or null, error detail or null]
     */
    private static function download(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [null, 'curl extension is not available on this server'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return [null, 'curl_init() failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'grand-baie-maurice.com image cache',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return [null, $error !== '' ? $error : 'curl_exec() returned false'];
        }
        if ($status >= 400) {
            return [null, 'HTTP ' . $status];
        }
        if ($body === '') {
            return [null, 'empty response body'];
        }

        return [$body, null];
    }
}
