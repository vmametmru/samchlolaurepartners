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

    /** Fixed width (px) and filename suffix for the email thumbnail generated alongside each synced photo. */
    public const THUMBNAIL_WIDTH = 320;
    public const THUMBNAIL_SUFFIX = '-320.jpg';

    /**
     * Maximum width (px) allowed for a synced full-size photo. Images wider
     * than this are downscaled (preserving aspect ratio and original
     * format) before being written to images/listings/; images already at
     * or below this width are left untouched at their original resolution.
     */
    public const MAX_PHOTO_WIDTH = 1920;

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

        // Downscale full-size photos wider than MAX_PHOTO_WIDTH before
        // saving, so a single very large Lodgify export doesn't bloat the
        // gallery/emails; images already narrower are kept at their
        // original resolution (never upscaled). Failures here are
        // non-fatal: the original bytes are saved as-is if resizing fails.
        $data = self::resizeIfTooWide($data, self::MAX_PHOTO_WIDTH);

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

        if ($index !== null) {
            // Also produce a fixed-width thumbnail (photoN-320.jpg) next to the
            // full-size file, so email templates can embed a small, predictable
            // image (see ReservationsController::propertyPhotoTag()) instead of
            // downloading/hotlinking the full-resolution photo. Failures here
            // are non-fatal: the full-size photo was already saved successfully
            // above, and callers fall back to it when the thumbnail is missing.
            self::createThumbnail($data, $dir . '/photo' . $index . self::THUMBNAIL_SUFFIX, self::THUMBNAIL_WIDTH);
        }

        return $publicPath;
    }

    /**
     * Resizes the just-downloaded image bytes to a fixed-width JPEG thumbnail
     * and writes it to $destPath. Always re-encodes as JPEG (regardless of the
     * source format) so the output is a single, predictable, universally
     * supported format for email clients; transparency (PNG/GIF/WEBP) is
     * flattened onto a white background. Returns false (and leaves no partial
     * file behind) on any failure, e.g. GD missing or corrupt image data.
     */
    private static function createThumbnail(string $data, string $destPath, int $targetWidth): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            self::recordError('GD extension is not available, skipping thumbnail generation for ' . $destPath);
            return false;
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            self::recordError('could not decode image data for thumbnail ' . $destPath);
            return false;
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            imagedestroy($source);
            self::recordError('invalid image dimensions for thumbnail ' . $destPath);
            return false;
        }

        $targetHeight = max(1, (int) round($srcHeight * ($targetWidth / $srcWidth)));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $white);
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);
        imagedestroy($source);

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($thumbnail);
            self::recordError('could not create directory ' . $dir . ' for thumbnail ' . $destPath);
            return false;
        }

        $tmpPath = $destPath . '.tmp-' . bin2hex(random_bytes(6));
        $ok = imagejpeg($thumbnail, $tmpPath, 82);
        imagedestroy($thumbnail);
        if (!$ok) {
            @unlink($tmpPath);
            self::recordError('could not encode JPEG thumbnail ' . $destPath);
            return false;
        }
        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            self::recordError('could not rename thumbnail to ' . $destPath);
            return false;
        }

        return true;
    }

    /**
     * Downscales $data to at most $maxWidth px wide, preserving aspect ratio
     * and the original image format (JPEG/PNG/GIF/WEBP), so the saved
     * full-size photo keeps its original type instead of always becoming a
     * JPEG like the email thumbnail does. Returns the original $data
     * unchanged whenever: GD is unavailable, the image can't be decoded,
     * its width is already <= $maxWidth (never upscaled), or re-encoding
     * fails for any reason — resizing is a best-effort optimization, not a
     * requirement for the photo to be saved.
     */
    private static function resizeIfTooWide(string $data, int $maxWidth): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $data;
        }

        $info = @getimagesizefromstring($data);
        if ($info === false) {
            return $data;
        }
        [$srcWidth, $srcHeight] = $info;
        $type = $info[2] ?? null;
        if ($srcWidth <= 0 || $srcHeight <= 0 || $srcWidth <= $maxWidth) {
            return $data;
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            self::recordError('could not decode image data while checking resize (width ' . $srcWidth . 'px)');
            return $data;
        }

        $targetHeight = max(1, (int) round($srcHeight * ($maxWidth / $srcWidth)));
        $resized = imagecreatetruecolor($maxWidth, $targetHeight);

        // Preserve transparency for formats that support it instead of
        // flattening onto a background color, since (unlike the email
        // thumbnail) this stays in the original format.
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $maxWidth, $targetHeight, $srcWidth, $srcHeight);
        imagedestroy($source);

        ob_start();
        $ok = match ($type) {
            IMAGETYPE_PNG => imagepng($resized, null, 9),
            IMAGETYPE_GIF => imagegif($resized),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($resized, null, 90) : false,
            IMAGETYPE_JPEG => imagejpeg($resized, null, 90),
            default => false,
        };
        $encoded = ob_get_clean();
        imagedestroy($resized);

        if (!$ok || $encoded === false || $encoded === '') {
            self::recordError('could not re-encode resized image (width ' . $srcWidth . 'px -> ' . $maxWidth . 'px)');
            return $data;
        }

        return $encoded;
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
