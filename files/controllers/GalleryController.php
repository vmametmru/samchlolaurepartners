<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\HttpException;
use App\LodgifyClient;
use App\PartnerPropertyVisibility;
use App\View;
use Throwable;

/**
 * Photo gallery for partners/admins: browses the photos already
 * synced from Lodgify and saved locally under images/listings/{propertyId}/
 * (see LodgifyClient::getProperty()/ImageCache::cache()), grouped in one
 * "directory" per property (named after the property). Never re-downloads
 * anything from Lodgify itself — only the local, previously-cached copies are
 * ever served — and the only actions offered are downloading a single photo
 * or a zip of a selection; nothing here can add/replace/delete a photo.
 */
final class GalleryController extends Controller
{
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private static function requirePartnerUser(): array
    {
        $user = Auth::requireUser();
        if (($user['role'] ?? '') !== 'partner') {
            throw new HttpException(403, 'Forbidden', 'Accès partenaire requis.');
        }
        return $user;
    }

    private static function requireAdminUser(): array
    {
        return Auth::requireUser(true);
    }

    public static function partnerIndex(): void
    {
        $user = self::requirePartnerUser();
        $visibilityMap = PartnerPropertyVisibility::allForPartner((int) $user['partner_id']);
        $folders = self::galleryFolders($visibilityMap);
        View::render('pages/partner-gallery', [
            'pageTitle' => 'Galerie photo',
            'folders' => $folders,
            'basePath' => '/partner/gallery',
        ]);
    }

    public static function partnerShow(int $propertyId): void
    {
        $user = self::requirePartnerUser();
        $visibilityMap = PartnerPropertyVisibility::allForPartner((int) $user['partner_id']);
        $visibility = $visibilityMap[(string) $propertyId] ?? PartnerPropertyVisibility::FULL;
        if ($visibility === PartnerPropertyVisibility::NONE) {
            throw new HttpException(404, 'Not Found', 'Hébergement introuvable');
        }
        self::renderShow($propertyId, '/partner/gallery', 'pages/partner-gallery-property');
    }

    public static function partnerDownloadZip(int $propertyId): never
    {
        $user = self::requirePartnerUser();
        $visibilityMap = PartnerPropertyVisibility::allForPartner((int) $user['partner_id']);
        $visibility = $visibilityMap[(string) $propertyId] ?? PartnerPropertyVisibility::FULL;
        if ($visibility === PartnerPropertyVisibility::NONE) {
            throw new HttpException(404, 'Not Found', 'Hébergement introuvable');
        }
        self::streamZipDownload($propertyId);
    }

    public static function adminIndex(): void
    {
        self::requireAdminUser();
        $folders = self::galleryFolders(null);
        View::render('pages/admin-gallery', [
            'pageTitle' => 'Galerie photo',
            'folders' => $folders,
            'basePath' => '/admin/gallery',
        ]);
    }

    public static function adminShow(int $propertyId): void
    {
        self::requireAdminUser();
        self::renderShow($propertyId, '/admin/gallery', 'pages/admin-gallery-property');
    }

    public static function adminDownloadZip(int $propertyId): never
    {
        self::requireAdminUser();
        self::streamZipDownload($propertyId);
    }

    /**
     * @param array<string, string>|null $visibilityMap When null, every
     *        Lodgify property is returned (admin scope). Otherwise
     *        properties marked "none" for this partner are excluded.
     * @return array<int, array{id: int, name: string, count: int, cover: ?string}>
     */
    private static function galleryFolders(?array $visibilityMap): array
    {
        $properties = self::loadProperties();
        $folders = [];
        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }
            if ($visibilityMap !== null) {
                $visibility = $visibilityMap[(string) $propertyId] ?? PartnerPropertyVisibility::FULL;
                if ($visibility === PartnerPropertyVisibility::NONE) {
                    continue;
                }
            }
            $photos = self::photosFor($propertyId);
            $folders[] = [
                'id' => $propertyId,
                'name' => View::localized($property, 'name') ?: ('Bien #' . $propertyId),
                'count' => count($photos),
                'cover' => $photos[0]['url'] ?? null,
            ];
        }
        usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $folders;
    }

    private static function renderShow(int $propertyId, string $basePath, string $template): void
    {
        $properties = self::loadProperties();
        $propertyName = 'Bien #' . $propertyId;
        foreach ($properties as $property) {
            if ((int) ($property['id'] ?? 0) === $propertyId) {
                $propertyName = View::localized($property, 'name') ?: $propertyName;
                break;
            }
        }
        $photos = self::photosFor($propertyId);
        View::render($template, [
            'pageTitle' => $propertyName,
            'propertyId' => $propertyId,
            'propertyName' => $propertyName,
            'photos' => $photos,
            'basePath' => $basePath,
        ]);
    }

    /**
     * Cached Lodgify property list (id/name/name_fr), the same source used
     * across the site for property names (see PageController::properties()).
     * Only used to label the "directory" for each property — never to fetch
     * or download photos, which always come from the local
     * images/listings/{id}/ copy saved by the sync (see ImageCache::cache()).
     *
     * @return array<int, array>
     */
    private static function loadProperties(): array
    {
        try {
            return (new LodgifyClient())->getProperties();
        } catch (Throwable $e) {
            error_log('GalleryController: failed to load property names: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Lists the locally-synced full-size photos for a property, sorted by
     * their sequential index (photo1, photo2, ...). Never touches the
     * network: only images/listings/{propertyId}/photoN.ext files already on
     * disk (thumbnails such as photo1-320.jpg are excluded — those are
     * email-only assets, not gallery photos).
     *
     * @return array<int, array{filename: string, url: string, index: int}>
     */
    private static function photosFor(int $propertyId): array
    {
        if ($propertyId <= 0) {
            return [];
        }
        $dir = self::propertyDir($propertyId);
        if (!is_dir($dir)) {
            return [];
        }
        $extensions = implode(',', self::PHOTO_EXTENSIONS);
        $matches = glob($dir . '/photo*.{' . $extensions . '}', GLOB_BRACE) ?: [];
        $extensionPattern = implode('|', self::PHOTO_EXTENSIONS);
        $photos = [];
        foreach ($matches as $path) {
            $filename = basename($path);
            if (!preg_match('/^photo(\d+)\.(' . $extensionPattern . ')$/i', $filename, $m)) {
                // Skips thumbnails (photoN-320.jpg) and any unexpected file.
                continue;
            }
            $photos[] = [
                'filename' => $filename,
                'url' => '/images/listings/' . $propertyId . '/' . $filename,
                'index' => (int) $m[1],
            ];
        }
        usort($photos, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
        return $photos;
    }

    private static function propertyDir(int $propertyId): string
    {
        return BASE_PATH . '/images/listings/' . $propertyId;
    }

    /**
     * Slugifies a property name for use as a directory entry / zip filename
     * (ASCII letters/digits/dashes only), so the downloaded archive's
     * internal folder/file name is readable and filesystem-safe on any OS.
     */
    private static function slugify(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
            if ($transliterated !== false) {
                $value = $transliterated;
            }
        }
        $value = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
        $value = trim($value, '-');
        return $value !== '' ? $value : 'bien';
    }

    /**
     * Streams a zip archive of the selected photos (files[] in the request
     * body, whitelisted against the property's own local photo list to
     * prevent path traversal) — or every local photo when no selection is
     * made — never fetching anything from Lodgify. Exits the request.
     */
    private static function streamZipDownload(int $propertyId): never
    {
        $available = self::photosFor($propertyId);
        if ($available === []) {
            throw new HttpException(404, 'Not Found', 'Aucune photo disponible pour ce bien.');
        }
        $availableByFilename = [];
        foreach ($available as $photo) {
            $availableByFilename[$photo['filename']] = $photo;
        }

        $input = self::input();
        $requested = is_array($input['files'] ?? null) ? $input['files'] : [];
        $selected = [];
        foreach ($requested as $filename) {
            $filename = basename((string) $filename);
            if (isset($availableByFilename[$filename])) {
                $selected[] = $filename;
            }
        }
        if ($selected === []) {
            // No (valid) selection: fall back to the whole gallery for this property.
            $selected = array_keys($availableByFilename);
        }

        $properties = self::loadProperties();
        $propertyName = 'Bien #' . $propertyId;
        foreach ($properties as $property) {
            if ((int) ($property['id'] ?? 0) === $propertyId) {
                $propertyName = View::localized($property, 'name') ?: $propertyName;
                break;
            }
        }
        $slug = self::slugify($propertyName);

        $tmpZip = tempnam(sys_get_temp_dir(), 'gallery-') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            throw new HttpException(500, 'Internal Server Error', 'Impossible de créer l\'archive zip.');
        }
        $dir = self::propertyDir($propertyId);
        foreach ($selected as $filename) {
            $fullPath = $dir . '/' . $filename;
            if (is_file($fullPath)) {
                $zip->addFile($fullPath, $slug . '/' . $filename);
            }
        }
        $zip->close();

        if (!is_file($tmpZip) || filesize($tmpZip) === 0) {
            @unlink($tmpZip);
            throw new HttpException(500, 'Internal Server Error', 'Impossible de créer l\'archive zip.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        header('X-Content-Type-Options: nosniff');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
}
