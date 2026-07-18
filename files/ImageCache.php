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
            return $remoteUrl;
        }

        $scheme = parse_url($remoteUrl, PHP_URL_SCHEME);
        $host = parse_url($remoteUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || !is_string($host) || $host === '') {
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
            error_log('ImageCache: could not create directory ' . $dir . ', falling back to remote URL');
            return $remoteUrl;
        }

        $data = self::download($remoteUrl);
        if ($data === null || $data === '') {
            error_log('ImageCache: download failed for ' . $remoteUrl . ', falling back to remote URL');
            return $remoteUrl;
        }

        $tmpPath = $localPath . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmpPath, $data) === false) {
            error_log('ImageCache: could not write ' . $tmpPath . ', falling back to remote URL');
            return $remoteUrl;
        }
        if (!@rename($tmpPath, $localPath)) {
            @unlink($tmpPath);
            error_log('ImageCache: could not rename ' . $tmpPath . ' to ' . $localPath . ', falling back to remote URL');
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

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
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

        if ($body === false || $body === '' || $error !== '' || $status >= 400) {
            return null;
        }

        return $body;
    }
}
