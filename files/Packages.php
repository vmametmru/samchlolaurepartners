<?php

declare(strict_types=1);

namespace App;

use App\controllers\PageController;
use App\controllers\ReservationsController;
use PDO;
use Throwable;

/**
 * "Offres Complètes" (packages): an agency bundles a flight, one or several
 * of its accommodations and some activities into a single offer, shown on a
 * dedicated public page (/offres, /offres/{id}).
 *
 * Everything here is strictly additive and invisible until an admin enables
 * partners.packages_visible for a given partner (see
 * db/migrations/064_create_packages.sql), and every availability/price
 * lookup is *cache-only*: the public offer pages must never trigger a live
 * Lodgify API call (see searchAccommodations() below).
 */
final class Packages
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';

    public const STOCK_NONE = 'none';
    public const STOCK_BOOKINGS = 'bookings';
    public const STOCK_PERSONS = 'persons';

    public const PRICE_PER_PERSON = 'per_person';
    public const PRICE_PER_GROUP = 'per_group';

    /** Île Maurice: offers expire on a GMT+4 wall-clock date/time. */
    public const DISPLAY_TIMEZONE = 'Etc/GMT-4';

    /**
     * How far around the requested check-in date alternative stays are
     * looked for, and how many nights an alternative may drop (never more
     * than 2, and never below one night).
     */
    private const FALLBACK_DAY_OFFSET = 7;
    private const FALLBACK_MAX_NIGHTS_LOST = 2;
    private const FALLBACK_MAX_RESULTS = 4;

    /**
     * True once migration 064 has actually applied on this install. Every
     * public entry point checks this first so a not-yet-migrated deploy
     * simply behaves as if the feature didn't exist instead of 500-ing.
     */
    public static function tablesReady(): bool
    {
        return Database::tableExists('packages')
            && Database::tableExists('package_flights')
            && Database::tableExists('package_activities')
            && Database::tableExists('package_properties')
            && Database::columnExists('partners', 'packages_visible');
    }

    /** Whether the admin enabled the "Offres Complètes" option for a partner. */
    public static function enabledForPartner(?array $partner): bool
    {
        // Cheapest check first: the navbar calls this on every single page
        // render, and an absent/0 flag (every partner until an admin enables
        // the option) must not cost the tablesReady() SHOW TABLES queries.
        if (!is_array($partner) || (int) ($partner['packages_visible'] ?? 0) !== 1) {
            return false;
        }
        return self::tablesReady();
    }

    public static function enabledForPartnerId(int $partnerId): bool
    {
        if ($partnerId <= 0 || !self::tablesReady()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT packages_visible FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && (int) ($row['packages_visible'] ?? 0) === 1;
    }

    // ── Reading ───────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForPartner(int $partnerId): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM packages WHERE partner_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$partnerId]);
        return array_map([self::class, 'decorate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Admin view: every partner's offers, optionally filtered on one partner.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(?int $partnerId = null): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $sql = 'SELECT p.*, pa.name AS partner_name, pa.subdomain AS partner_code FROM packages p
                INNER JOIN partners pa ON pa.id = p.partner_id';
        $params = [];
        if ($partnerId !== null && $partnerId > 0) {
            $sql .= ' WHERE p.partner_id = ?';
            $params[] = $partnerId;
        }
        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'decorate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Full offer (flights, selected properties, activities) or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0 || !self::tablesReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM packages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $package = self::decorate($row);
        $package['flights'] = self::flightsFor($id);
        $package['transports'] = self::transportsFor($id);
        $package['activities'] = self::activitiesFor($id);
        $package['meals'] = self::mealsFor($id);
        $package['property_ids'] = self::propertyIdsFor($id);
        return $package;
    }

    /** @return array<string, mixed>|null */
    public static function findForPartner(int $partnerId, int $id): ?array
    {
        $package = self::find($id);
        if ($package === null || (int) $package['partner_id'] !== $partnerId) {
            return null;
        }
        return $package;
    }

    /**
     * The offer, re-read with its row locked inside the caller's
     * transaction, so an "enough stock left" check and the reservation
     * INSERT that consumes that stock cannot interleave with a concurrent
     * submission of the same offer (two clients taking the last unit).
     * Returns null when the offer doesn't exist or isn't this partner's.
     *
     * @return array<string, mixed>|null
     */
    public static function lockForStock(PDO $pdo, int $partnerId, int $packageId): ?array
    {
        if ($packageId <= 0 || !self::tablesReady()) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM packages WHERE id = ? FOR UPDATE');
        $stmt->execute([$packageId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return self::findForPartner($partnerId, $packageId);
    }

    /** @return array<int, array<string, mixed>> */
    public static function flightsFor(int $packageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM package_flights WHERE package_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function activitiesFor(int $packageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM package_activities WHERE package_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * "Restauration" entries. Guarded on its own, because an install that
     * already had offers may not have run migration 065 yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mealsFor(int $packageId): array
    {
        if (!self::mealsTableReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM package_meals WHERE package_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * "Transport" entries (transfers, car hire…), proposed in their own step
     * between "Vol" and "Activités". Guarded on its own, because an install
     * that already had offers may not have run migration 066 yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function transportsFor(int $packageId): array
    {
        if (!self::transportsTableReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM package_transports WHERE package_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$packageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function transportsTableReady(): bool
    {
        return Database::tableExists('package_transports');
    }

    public static function mealsTableReady(): bool
    {
        return Database::tableExists('package_meals');
    }

    /** @return array<int, int> */
    public static function propertyIdsFor(int $packageId): array
    {
        $stmt = Database::connection()->prepare('SELECT property_id FROM package_properties WHERE package_id = ?');
        $stmt->execute([$packageId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Offers a client may actually see right now: active, not expired and
     * not out of stock.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publicList(int $partnerId): array
    {
        return array_values(array_filter(
            self::listForPartner($partnerId),
            static fn (array $package): bool => self::isBookable($package)
        ));
    }

    // ── Expiration / stock ────────────────────────────────────────────────

    public static function isExpired(array $package): bool
    {
        $expiresAt = (string) ($package['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * How much of the offer's stock is already consumed, in the unit the
     * partner chose: number of non-cancelled requests ('bookings') or number
     * of travellers on them ('persons').
     */
    public static function usedStock(int $packageId, string $stockMode): int
    {
        if ($stockMode === self::STOCK_NONE || !Database::columnExists('reservation_requests', 'package_id')) {
            return 0;
        }
        $expression = $stockMode === self::STOCK_PERSONS
            ? 'COALESCE(SUM(adults + children), 0)'
            : 'COUNT(*)';
        $stmt = Database::connection()->prepare(
            'SELECT ' . $expression . ' FROM reservation_requests WHERE package_id = ? AND status <> ?'
        );
        $stmt->execute([$packageId, 'cancelled']);
        return (int) $stmt->fetchColumn();
    }

    /** Remaining stock, or null when the offer is not stock-limited. */
    public static function remainingStock(array $package): ?int
    {
        $mode = (string) ($package['stock_mode'] ?? self::STOCK_NONE);
        $limit = $package['stock_limit'] ?? null;
        if ($mode === self::STOCK_NONE || $limit === null || (int) $limit <= 0) {
            return null;
        }
        return max(0, (int) $limit - self::usedStock((int) $package['id'], $mode));
    }

    /** Whether the offer can still be displayed/booked publicly. */
    public static function isBookable(array $package, int $requestedPersons = 0): bool
    {
        if ((string) ($package['status'] ?? '') !== self::STATUS_ACTIVE) {
            return false;
        }
        if (self::isExpired($package)) {
            return false;
        }
        $remaining = self::remainingStock($package);
        if ($remaining === null) {
            return true;
        }
        if ($remaining <= 0) {
            return false;
        }
        if ($requestedPersons > 0 && (string) $package['stock_mode'] === self::STOCK_PERSONS) {
            return $remaining >= $requestedPersons;
        }
        return true;
    }

    /**
     * The offer's expiry as a GMT+4 "Y-m-d\TH:i" string for the datetime-local
     * input on the partner form (stored in UTC like every other timestamp).
     */
    public static function expiresAtLocalInput(array $package): string
    {
        $raw = (string) ($package['expires_at'] ?? '');
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE))
                ->format('Y-m-d\TH:i');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Human-readable GMT+4 expiry, e.g. "31/12/2026 à 18:00 (GMT + 4)". */
    public static function expiresAtLabel(array $package): ?string
    {
        $raw = (string) ($package['expires_at'] ?? '');
        if ($raw === '') {
            return null;
        }
        try {
            $date = (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE));
        } catch (Throwable $e) {
            return null;
        }
        return $date->format('d/m/Y') . ' à ' . $date->format('H:i') . ' (GMT + 4)';
    }

    /** Converts a GMT+4 "Y-m-d\TH:i" form value to a UTC "Y-m-d H:i:s". */
    public static function expiresAtFromInput(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone(self::DISPLAY_TIMEZONE)))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── Writing ───────────────────────────────────────────────────────────

    /**
     * Creates or updates an offer with its flight options, property
     * selection and activities. Returns the offer id.
     *
     * @param array<string, mixed> $input raw $_POST payload
     */
    public static function save(int $partnerId, ?int $id, array $input, ?string $photoUrl): int
    {
        $pdo = Database::connection();
        $status = (string) ($input['status'] ?? self::STATUS_DRAFT) === self::STATUS_ACTIVE
            ? self::STATUS_ACTIVE
            : self::STATUS_DRAFT;
        $stockMode = in_array((string) ($input['stock_mode'] ?? ''), [self::STOCK_BOOKINGS, self::STOCK_PERSONS], true)
            ? (string) $input['stock_mode']
            : self::STOCK_NONE;
        $stockLimit = $stockMode === self::STOCK_NONE ? null : max(0, (int) ($input['stock_limit'] ?? 0));
        if ($stockLimit !== null && $stockLimit <= 0) {
            $stockMode = self::STOCK_NONE;
            $stockLimit = null;
        }
        $allProperties = (string) ($input['all_properties'] ?? '0') === '1' ? 1 : 0;

        $fields = [
            'title' => mb_substr(trim((string) ($input['title'] ?? '')), 0, 190),
            'title_en' => self::nullableText($input['title_en'] ?? null, 190),
            'description' => self::nullableText($input['description'] ?? null),
            'description_en' => self::nullableText($input['description_en'] ?? null),
            'status' => $status,
            'all_properties' => $allProperties,
            'expires_at' => self::expiresAtFromInput((string) ($input['expires_at'] ?? '')),
            'stock_mode' => $stockMode,
            'stock_limit' => $stockLimit,
        ];

        if ($id === null || $id <= 0) {
            $fields['partner_id'] = $partnerId;
            $fields['photo_url'] = $photoUrl;
            $columns = array_keys($fields);
            $stmt = $pdo->prepare(
                'INSERT INTO packages (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            $stmt->execute(array_values($fields));
            $id = (int) $pdo->lastInsertId();
        } else {
            if ($photoUrl !== null) {
                $fields['photo_url'] = $photoUrl;
            }
            $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($fields)));
            $params = array_values($fields);
            $params[] = $id;
            $params[] = $partnerId;
            $stmt = $pdo->prepare('UPDATE packages SET ' . $assignments . ' WHERE id = ? AND partner_id = ?');
            $stmt->execute($params);
        }

        self::replaceFlights($id, $input);
        self::replaceProperties($id, $allProperties === 1 ? [] : ($input['property_ids'] ?? []));
        self::replaceExtras($id, $input, 'package_activities', 'activities');
        if (self::transportsTableReady()) {
            self::replaceExtras($id, $input, 'package_transports', 'transports');
        }
        if (self::mealsTableReady()) {
            self::replaceExtras($id, $input, 'package_meals', 'meals');
        }

        return $id;
    }

    public static function delete(int $partnerId, int $id): bool
    {
        if (!self::tablesReady()) {
            return false;
        }
        $stmt = Database::connection()->prepare('DELETE FROM packages WHERE id = ? AND partner_id = ?');
        $stmt->execute([$id, $partnerId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $input */
    private static function replaceFlights(int $packageId, array $input): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM package_flights WHERE package_id = ?')->execute([$packageId]);
        $rows = is_array($input['flights'] ?? null) ? $input['flights'] : [];
        $stmt = $pdo->prepare(
            'INSERT INTO package_flights (package_id, label, airline, cabin_class, description, price_mode, price, is_default, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $position = 0;
        $defaultSeen = false;
        $defaultChoice = (string) ($input['flight_default'] ?? '');
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 190);
            if ($label === '') {
                continue;
            }
            $isDefault = !$defaultSeen && ((string) $key === $defaultChoice);
            if ($isDefault) {
                $defaultSeen = true;
            }
            $stmt->execute([
                $packageId,
                $label,
                self::nullableText($row['airline'] ?? null, 190),
                self::nullableText($row['cabin_class'] ?? null, 190),
                self::nullableText($row['description'] ?? null),
                self::priceMode($row['price_mode'] ?? null),
                self::money($row['price'] ?? 0),
                $isDefault ? 1 : 0,
                $position++,
            ]);
        }
    }

    /** @param mixed $propertyIds */
    private static function replaceProperties(int $packageId, mixed $propertyIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM package_properties WHERE package_id = ?')->execute([$packageId]);
        if (!is_array($propertyIds)) {
            return;
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO package_properties (package_id, property_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $propertyIds)) as $propertyId) {
            if ($propertyId > 0) {
                $stmt->execute([$packageId, $propertyId]);
            }
        }
    }

    /**
     * Rewrites the optional/mandatory extras of one block (transports,
     * activities or meals): the three tables share the same shape, so the
     * same routine handles "transports[...]", "activities[...]" and
     * "meals[...]".
     *
     * @param array<string, mixed> $input
     */
    private static function replaceExtras(int $packageId, array $input, string $table, string $inputKey): void
    {
        $pdo = Database::connection();
        $existingPhotos = [];
        $existing = match ($inputKey) {
            'meals' => self::mealsFor($packageId),
            'transports' => self::transportsFor($packageId),
            default => self::activitiesFor($packageId),
        };
        foreach ($existing as $extra) {
            $existingPhotos[(int) $extra['id']] = (string) ($extra['photo_url'] ?? '');
        }
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE package_id = ?')->execute([$packageId]);
        $rows = is_array($input[$inputKey] ?? null) ? $input[$inputKey] : [];
        $stmt = $pdo->prepare(
            'INSERT INTO ' . $table . ' (package_id, label, label_en, description, description_en, photo_url, price_mode, price, is_mandatory, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 190);
            if ($label === '') {
                continue;
            }
            $photoUrl = self::nullableText($row['photo_url'] ?? null, 500);
            if ($photoUrl === null) {
                $previousId = (int) ($row['id'] ?? 0);
                $photoUrl = $previousId > 0 && ($existingPhotos[$previousId] ?? '') !== ''
                    ? $existingPhotos[$previousId]
                    : null;
            }
            $uploaded = self::storeExtraPhoto($inputKey, (string) $key);
            if ($uploaded !== null) {
                $photoUrl = $uploaded;
            }
            $stmt->execute([
                $packageId,
                $label,
                self::nullableText($row['label_en'] ?? null, 190),
                self::nullableText($row['description'] ?? null),
                self::nullableText($row['description_en'] ?? null),
                $photoUrl,
                self::priceMode($row['price_mode'] ?? null),
                self::money($row['price'] ?? 0),
                (string) ($row['is_mandatory'] ?? '0') === '1' ? 1 : 0,
                $position++,
            ]);
        }
    }

    /**
     * Saves an uploaded extra photo ("activities[{key}][photo]" or
     * "meals[{key}][photo]" file input) under images/packages/ and returns
     * its public URL, or null when no (valid) file was sent.
     */
    private static function storeExtraPhoto(string $inputKey, string $key): ?string
    {
        $files = $_FILES[$inputKey] ?? null;
        if (!is_array($files) || !isset($files['tmp_name'][$key]['photo'])) {
            return null;
        }
        $file = [
            'name' => $files['name'][$key]['photo'] ?? '',
            'tmp_name' => $files['tmp_name'][$key]['photo'] ?? '',
            'error' => $files['error'][$key]['photo'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$key]['photo'] ?? 0,
        ];
        return self::storeUploadedImage($file);
    }

    /**
     * Shared image upload for the offer's main photo and each activity
     * photo: extension whitelist, size cap and a random filename, mirroring
     * PageController's own upload helpers.
     *
     * @param array{name?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     */
    public static function storeUploadedImage(?array $file): ?string
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }
        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new HttpException(400, 'Bad Request', 'La photo ne doit pas dépasser 8 Mo.');
        }
        $extension = strtolower(pathinfo(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))), PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            throw new HttpException(400, 'Bad Request', 'Format de photo non supporté (jpg, png, gif ou webp).');
        }
        $dir = BASE_PATH . '/images/packages';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible de créer le dossier des photos d\'offres.');
        }
        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        if (!move_uploaded_file($tmpName, $dir . '/' . $filename)) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible d\'enregistrer la photo.');
        }
        return '/images/packages/' . $filename;
    }

    // ── Pricing of the non-accommodation parts ────────────────────────────

    /**
     * Price of a flight option/activity for a given party size: either per
     * person or a flat price for the whole group.
     *
     * @param array<string, mixed> $row
     */
    public static function lineTotal(array $row, int $persons): float
    {
        $price = (float) ($row['price'] ?? 0);
        if ((string) ($row['price_mode'] ?? self::PRICE_PER_PERSON) === self::PRICE_PER_GROUP) {
            return round($price, 2);
        }
        return round($price * max(0, $persons), 2);
    }

    /**
     * The flight option + transports + activities + meals part of an offer's
     * total, for the client's current selection. Mandatory transports/
     * activities/meals are always counted, whatever the client ticked.
     *
     * 'base_total' is exposed on its own because the accommodation step
     * quotes, for each property, the price of everything the client gets
     * without choosing anything: the stay itself plus the offer's default
     * flight option and every mandatory transport/activity/meal. The running
     * total of the whole offer (that base plus the options ticked afterwards)
     * is shown in the page's floating recap, once the accommodation is
     * chosen.
     *
     * @param array<string, mixed> $package fully loaded offer (find())
     * @param array<int, int> $selectedActivityIds
     * @param array<int, int> $selectedMealIds
     * @param array<int, int> $selectedTransportIds
     * @return array{flight: array<string, mixed>|null, flight_total: float, base_total: float, transports: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>, meals: array<int, array<string, mixed>>, total: float}
     * @throws HttpException when $flightId is not one of this offer's flights
     */
    public static function extrasSelection(
        array $package,
        ?int $flightId,
        array $selectedActivityIds,
        array $selectedMealIds,
        int $persons,
        array $selectedTransportIds = []
    ): array {
        $flights = $package['flights'] ?? [];
        $flight = null;
        if ($flightId !== null) {
            foreach ($flights as $candidate) {
                if ((int) $candidate['id'] === $flightId) {
                    $flight = $candidate;
                    break;
                }
            }
            // A flight option that does not belong to this offer means a
            // tampered payload: refuse it instead of quoting (or creating)
            // the request without that option's price.
            if ($flight === null) {
                throw new HttpException(400, 'Bad Request', 'Option de vol invalide pour cette offre.');
            }
        } else {
            foreach ($flights as $candidate) {
                if ((int) ($candidate['is_default'] ?? 0) === 1) {
                    $flight = $candidate;
                    break;
                }
            }
            if ($flight === null && $flights !== []) {
                $flight = $flights[0];
            }
        }

        $flightTotal = $flight !== null ? self::lineTotal($flight, $persons) : 0.0;
        $total = $flightTotal;
        // Everything the client gets without picking anything: the default
        // flight option plus the mandatory lines of the other steps.
        $baseTotal = $flightTotal;
        $selection = ['transports' => [], 'activities' => [], 'meals' => []];
        $selectedIds = [
            'transports' => $selectedTransportIds,
            'activities' => $selectedActivityIds,
            'meals' => $selectedMealIds,
        ];
        foreach ($selection as $block => $_unused) {
            foreach (($package[$block] ?? []) as $extra) {
                $isMandatory = (int) ($extra['is_mandatory'] ?? 0) === 1;
                if (!$isMandatory && !in_array((int) $extra['id'], $selectedIds[$block], true)) {
                    continue;
                }
                $extra['line_total'] = self::lineTotal($extra, $persons);
                $total += $extra['line_total'];
                if ($isMandatory) {
                    $baseTotal += $extra['line_total'];
                }
                $selection[$block][] = $extra;
            }
        }

        return [
            'flight' => $flight,
            'flight_total' => round($flightTotal, 2),
            'base_total' => round($baseTotal, 2),
            'transports' => $selection['transports'],
            'activities' => $selection['activities'],
            'meals' => $selection['meals'],
            'total' => round($total, 2),
        ];
    }

    // ── Accommodation search (100% local: never calls the Lodgify API) ────

    /**
     * Finds the offer's accommodations available for the requested dates and
     * party size, using *only* the local database: the properties list, the
     * availability calendar and the nightly rates all come from lodgify_cache
     * (LodgifyClient::getPropertiesFromCache()/getAvailabilityFromCache()/
     * getRatesFromCache(), which slice whatever range the hourly cron warmed),
     * plus this app's own reservations table. No Lodgify API call is ever
     * issued here, so an offer page can never consume the API quota nor fail
     * when Lodgify is down.
     *
     * A property is only ever offered when the cache fully covers the stay
     * (every night known and available) *and* yields a usable rate: a
     * property with missing cache data is silently skipped, never shown
     * without a price.
     *
     * When nothing matches the requested dates, close-by alternatives are
     * proposed instead: the same stay shifted by a few days and/or shortened
     * by at most 2 nights, ranked by how close they stay to the original
     * request.
     *
     * A party too big for any single property isn't left empty-handed: every
     * property available for the exact requested dates is also grouped by its
     * manual "Emplacement" (see PageController::manualLodgifyColumnsByPropertyId()),
     * and any address whose properties together can host everyone is returned
     * as a "groups" entry, so the client can book several properties at that
     * same address in one go (see quoteSelection()).
     *
     * @param array<string, mixed> $package fully loaded offer (find())
     * @param array<string, mixed> $partner
     * @param string $nationality Nationality declared for the whole party
     * (offer page's "Nationalité" field), used to compute the tourist tax
     * exactly like the ordinary property pages instead of the conservative
     * "every adult is a foreigner" estimate (see guestsForNationality()).
     * @param array<int, array{type?: string, nationality?: string}> $guests Optional detailed guest list; when valid, it is used instead of a uniform nationality.
     * @return array{nights: int, matches: array<int, array<string, mixed>>, alternatives: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>}
     */
    public static function searchAccommodations(
        array $package,
        array $partner,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12,
        int $childrenUnder3,
        string $nationality = '',
        array $guests = []
    ): array {
        $empty = ['nights' => 0, 'matches' => [], 'alternatives' => [], 'groups' => []];
        try {
            $checkinDate = new \DateTimeImmutable($checkin);
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (Throwable $e) {
            return $empty;
        }
        $nights = (int) $checkinDate->diff($checkoutDate)->days;
        if ($nights < 1 || $checkoutDate <= $checkinDate) {
            return $empty;
        }
        $empty['nights'] = $nights;

        $countedGuests = $adults + $children3to12;
        $totalGuests = $countedGuests + $childrenUnder3;
        $guests = self::normalizeGuests($adults, $children3to12, $childrenUnder3, $nationality, $guests);
        if ($countedGuests < 1) {
            return $empty;
        }

        $client = new LodgifyClient();
        try {
            $properties = PageController::publicVisibleProperties($client->getPropertiesFromCache(), $partner);
        } catch (Throwable $e) {
            error_log('Packages: failed to read cached properties: ' . $e->getMessage());
            return $empty;
        }

        $allowedIds = (int) ($package['all_properties'] ?? 1) === 1
            ? null
            : array_map('intval', $package['property_ids'] ?? []);

        // Window scanned for the fallback: the requested stay widened by
        // FALLBACK_DAY_OFFSET on both sides. Everything (availability and
        // rates) is read once per property over this window, then evaluated
        // in memory, so scanning many date combinations stays cheap.
        $windowStart = $checkinDate->modify('-' . self::FALLBACK_DAY_OFFSET . ' days');
        $today = new \DateTimeImmutable('today');
        if ($windowStart < $today) {
            $windowStart = $today;
        }
        $windowEnd = $checkoutDate->modify('+' . self::FALLBACK_DAY_OFFSET . ' days');

        $matches = [];
        $alternativeCandidates = [];
        // Properties available for the *exact* requested dates, whatever
        // their own capacity: a party too big for any single one of them can
        // still be hosted by several properties sharing the same address.
        $groupCandidates = [];
        foreach ($properties as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }
            if ($allowedIds !== null && !in_array($propertyId, $allowedIds, true)) {
                continue;
            }
            $maxGuests = (int) ($property['max_guests'] ?? 0);
            // A single property must also respect the app-wide hard limit of
            // ReservationsController::MAX_BABIES_PER_PROPERTY babies, which
            // the reservation flow enforces at submission time: without it a
            // party with 3+ babies would be shown an accommodation (and a
            // request button) that every submission rejects. A bigger group
            // of babies can still be hosted by the same-address groups below.
            $fitsParty = ($maxGuests <= 0 || $countedGuests <= $maxGuests)
                && $childrenUnder3 <= ReservationsController::MAX_BABIES_PER_PROPERTY;

            $availabilityMap = [];
            foreach ($client->getAvailabilityFromCache($propertyId, $windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d')) as $day) {
                if (isset($day['date'])) {
                    $availabilityMap[(string) $day['date']] = !empty($day['available']);
                }
            }
            $rateRows = [];
            foreach ($client->getRatesFromCache($propertyId, $windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d')) as $rate) {
                if (isset($rate['date_from'])) {
                    $rateRows[(string) $rate['date_from']] = $rate;
                }
            }
            if ($availabilityMap === [] || $rateRows === []) {
                continue;
            }
            // This app's own confirmed reservations overlapping the scanned
            // window, read once per property: every date combination tested
            // below is then evaluated in memory, never with one SQL query
            // per combination.
            $reservedRanges = ReservationsController::localReservedRanges(
                $propertyId,
                $windowStart->format('Y-m-d'),
                $windowEnd->format('Y-m-d')
            );

            if (self::stayCoveredByCache($availabilityMap, $rateRows, $checkinDate, $nights)
                && ReservationsController::rangesFreeOf($reservedRanges, $checkin, $checkout)
            ) {
                $groupCandidates[] = [
                    'property' => $property,
                    'property_id' => $propertyId,
                    'max_guests' => $maxGuests,
                ];
                if ($fitsParty) {
                    $quote = ReservationsController::cacheOnlyStayQuote(
                        (int) $partner['id'],
                        $propertyId,
                        $property,
                        $checkin,
                        $checkoutDate,
                        $adults,
                        $totalGuests,
                        $countedGuests,
                        $guests,
                        self::stayRateRows($rateRows, $checkinDate, $nights)
                    );
                    if ($quote !== null) {
                        $matches[] = self::accommodationEntry($property, $quote, $checkin, $checkout, $nights, 0, 0);
                    }
                }
            }

            // Nearby-date alternatives are only ever proposed for a property
            // that could host the whole party on its own: a smaller one is
            // only relevant combined with others, which the same-address
            // groups below handle for the requested dates.
            if (!$fitsParty) {
                continue;
            }

            foreach (self::fallbackCombinations($checkinDate, $nights, $today) as $combination) {
                [$altCheckin, $altNights, $dayShift, $nightsLost] = $combination;
                if (!self::stayCoveredByCache($availabilityMap, $rateRows, $altCheckin, $altNights)) {
                    continue;
                }
                $altCheckinText = $altCheckin->format('Y-m-d');
                $altCheckoutDate = $altCheckin->modify('+' . $altNights . ' days');
                $altCheckoutText = $altCheckoutDate->format('Y-m-d');
                if (!ReservationsController::rangesFreeOf($reservedRanges, $altCheckinText, $altCheckoutText)) {
                    continue;
                }
                $alternativeCandidates[] = [
                    'property' => $property,
                    'property_id' => $propertyId,
                    'checkin' => $altCheckinText,
                    'checkout' => $altCheckoutText,
                    'checkout_date' => $altCheckoutDate,
                    'nights' => $altNights,
                    'day_shift' => $dayShift,
                    'nights_lost' => $nightsLost,
                    'rates' => self::stayRateRows($rateRows, $altCheckin, $altNights),
                ];
            }
        }

        if ($matches !== []) {
            usort($matches, static fn (array $a, array $b): int => $a['total_stay'] <=> $b['total_stay']);
            return ['nights' => $nights, 'matches' => $matches, 'alternatives' => [], 'groups' => []];
        }

        // No single property can host the party for those dates: propose the
        // addresses where several properties of the offer, taken together,
        // can. Prices are deliberately not computed here — the client first
        // picks which properties they want, then the whole selection is
        // quoted as one by quoteSelection().
        $groups = self::sameAddressGroups($groupCandidates, $countedGuests, $childrenUnder3, $checkin, $checkout, $nights);
        if ($groups !== []) {
            return ['nights' => $nights, 'matches' => [], 'alternatives' => [], 'groups' => $groups];
        }

        // Closest first: smallest date shift, then fewest nights lost, then
        // cheapest. The price tiebreak must compare the same all-in total the
        // results expose (markup, extra persons, cleaning and tourist tax
        // included), so candidates are quoted group by group — one group per
        // (date shift, nights lost) pair — and each group is ordered on its
        // authoritative total before being kept.
        $groupedCandidates = [];
        foreach ($alternativeCandidates as $candidate) {
            $groupedCandidates[abs($candidate['day_shift']) . ':' . $candidate['nights_lost']][] = $candidate;
        }
        uksort($groupedCandidates, static function (string $a, string $b): int {
            return array_map('intval', explode(':', $a)) <=> array_map('intval', explode(':', $b));
        });

        $alternatives = [];
        foreach ($groupedCandidates as $group) {
            if (count($alternatives) >= self::FALLBACK_MAX_RESULTS) {
                break;
            }
            $quoted = [];
            foreach ($group as $candidate) {
                $quote = ReservationsController::cacheOnlyStayQuote(
                    (int) $partner['id'],
                    $candidate['property_id'],
                    $candidate['property'],
                    $candidate['checkin'],
                    $candidate['checkout_date'],
                    $adults,
                    $totalGuests,
                    $countedGuests,
                    $guests,
                    $candidate['rates']
                );
                if ($quote === null) {
                    continue;
                }
                $quoted[] = self::accommodationEntry(
                    $candidate['property'],
                    $quote,
                    $candidate['checkin'],
                    $candidate['checkout'],
                    $candidate['nights'],
                    $candidate['day_shift'],
                    $candidate['nights_lost']
                );
            }
            usort($quoted, static fn (array $a, array $b): int => $a['total_stay'] <=> $b['total_stay']);
            foreach ($quoted as $entry) {
                if (count($alternatives) >= self::FALLBACK_MAX_RESULTS) {
                    break;
                }
                $alternatives[] = $entry;
            }
        }

        return ['nights' => $nights, 'matches' => [], 'alternatives' => $alternatives, 'groups' => []];
    }

    /**
     * Addresses ("Emplacement", the manual column of the "Biens Lodgify"
     * table) where several properties of the offer, all available for the
     * exact requested dates, can together host a party no single property
     * can take. Only addresses holding at least two such properties and
     * enough combined capacity are returned.
     *
     * @param array<int, array{property: array<string, mixed>, property_id: int, max_guests: int}> $candidates
     * @return array<int, array{location: string, capacity: int, properties: array<int, array<string, mixed>>}>
     */
    private static function sameAddressGroups(
        array $candidates,
        int $countedGuests,
        int $childrenUnder3,
        string $checkin,
        string $checkout,
        int $nights
    ): array {
        if (count($candidates) < 2) {
            return [];
        }
        $locations = PageController::manualLodgifyColumnsByPropertyId(
            array_map(static fn (array $candidate): int => $candidate['property_id'], $candidates)
        );

        $byLocation = [];
        foreach ($candidates as $candidate) {
            $location = trim((string) ($locations[$candidate['property_id']]['location'] ?? ''));
            if ($location === '') {
                continue;
            }
            $key = mb_strtolower($location);
            $byLocation[$key]['location'] = $byLocation[$key]['location'] ?? $location;
            $byLocation[$key]['candidates'][] = $candidate;
        }

        $groups = [];
        foreach ($byLocation as $entry) {
            $groupCandidates = $entry['candidates'];
            if (count($groupCandidates) < 2) {
                continue;
            }
            $capacity = 0;
            $unlimited = false;
            foreach ($groupCandidates as $candidate) {
                if ($candidate['max_guests'] <= 0) {
                    $unlimited = true;
                    continue;
                }
                $capacity += $candidate['max_guests'];
            }
            if (!$unlimited && $capacity < $countedGuests) {
                continue;
            }
            // Babies need a cot in a property of their own beyond two per
            // property, exactly like the ordinary multi-property flow.
            if ($childrenUnder3 > count($groupCandidates) * ReservationsController::MAX_BABIES_PER_PROPERTY) {
                continue;
            }
            usort(
                $groupCandidates,
                static fn (array $a, array $b): int => $b['max_guests'] <=> $a['max_guests']
            );
            $properties = [];
            foreach ($groupCandidates as $candidate) {
                $property = $candidate['property'];
                $properties[] = [
                    'property_id' => $candidate['property_id'],
                    'name' => View::localized($property, 'name'),
                    'image_url' => $property['images'][0]['url'] ?? null,
                    'bedrooms' => (int) ($property['bedrooms'] ?? 0),
                    'max_guests' => $candidate['max_guests'],
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                    'nights' => $nights,
                ];
            }
            $groups[] = [
                'location' => (string) $entry['location'],
                'capacity' => $unlimited ? 0 : $capacity,
                'properties' => $properties,
            ];
        }

        usort($groups, static fn (array $a, array $b): int => count($b['properties']) <=> count($a['properties']));
        return $groups;
    }

    /**
     * Prices a multi-property selection of an offer: the properties must all
     * belong to one same-address group returned by searchAccommodations()
     * for those dates, and together be able to host the whole party, which is
     * then spread over them (allocateParty()). Each property is quoted for
     * the guests it actually hosts — cache-only, like every other offer
     * lookup — and the accommodation totals are summed into one figure, the
     * offer being sold as a whole.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $partner
     * @param array<int, int> $propertyIds
     * @param array{groups: array<int, array<string, mixed>>}|null $search result of searchAccommodations() for the very same dates/party, reused instead of scanning again
     * @param array<int, array{type?: string, nationality?: string}> $guests Optional detailed guest list; when valid, it is spread across selected properties.
     * @return array{items: array<int, array<string, mixed>>, total_stay: float, currency: string, location: string}|null
     */
    public static function quoteSelection(
        array $package,
        array $partner,
        array $propertyIds,
        string $checkin,
        string $checkout,
        int $adults,
        int $children3to12,
        int $childrenUnder3,
        ?array $search = null,
        string $nationality = '',
        array $guests = []
    ): ?array {
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));
        if (count($propertyIds) < 2) {
            return null;
        }
        $search ??= self::searchAccommodations($package, $partner, $checkin, $checkout, $adults, $children3to12, $childrenUnder3, $nationality, $guests);
        $group = null;
        foreach ($search['groups'] as $candidateGroup) {
            $groupIds = array_map(
                static fn (array $property): int => (int) $property['property_id'],
                $candidateGroup['properties']
            );
            if (array_diff($propertyIds, $groupIds) === []) {
                $group = $candidateGroup;
                break;
            }
        }
        if ($group === null) {
            return null;
        }

        $capacities = [];
        $selected = [];
        foreach ($group['properties'] as $property) {
            $propertyId = (int) $property['property_id'];
            if (!in_array($propertyId, $propertyIds, true)) {
                continue;
            }
            $capacities[$propertyId] = (int) $property['max_guests'];
            $selected[$propertyId] = $property;
        }
        $allocation = self::allocateParty($capacities, $adults, $children3to12, $childrenUnder3);
        if ($allocation === null) {
            return null;
        }

        try {
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (Throwable $e) {
            return null;
        }
        $client = new LodgifyClient();
        $propertiesById = [];
        try {
            foreach ($client->getPropertiesFromCache() as $row) {
                $propertiesById[(int) ($row['id'] ?? 0)] = $row;
            }
        } catch (Throwable $e) {
            error_log('Packages: failed to read cached properties for a multi-property selection: ' . $e->getMessage());
            return null;
        }
        $items = [];
        $total = 0.0;
        $currency = 'EUR';
        $guestPool = self::guestPoolByType(
            self::normalizeGuests($adults, $children3to12, $childrenUnder3, $nationality, $guests)
        );
        foreach ($allocation as $propertyId => $share) {
            $property = $propertiesById[$propertyId] ?? null;
            $shareCounted = $share['adults'] + $share['children_3to12'];
            $quote = ReservationsController::cacheOnlyStayQuote(
                (int) $partner['id'],
                $propertyId,
                $property,
                $checkin,
                $checkoutDate,
                $share['adults'],
                $shareCounted + $share['children_under3'],
                $shareCounted,
                self::takeGuestShare($guestPool, $share)
            );
            if ($quote === null) {
                return null;
            }
            $stayTotal = round((float) ($quote['total_traveler'] ?? 0) + (float) ($quote['tourist_tax_total'] ?? 0), 2);
            $currency = (string) ($quote['currency'] ?? $currency);
            $total += $stayTotal;
            $items[] = [
                'property_id' => $propertyId,
                'name' => (string) $selected[$propertyId]['name'],
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => (int) $selected[$propertyId]['nights'],
                'adults' => $share['adults'],
                'children_3to12' => $share['children_3to12'],
                'children_under3' => $share['children_under3'],
                'quote' => $quote,
                'total_stay' => $stayTotal,
            ];
        }

        return [
            'items' => $items,
            'total_stay' => round($total, 2),
            'currency' => $currency,
            'location' => (string) $group['location'],
        ];
    }

    /**
     * Spreads a party over the selected properties: every property hosts at
     * least one person (and one adult whenever there are enough adults),
     * nobody exceeds a property's max_guests and no property takes more than
     * ReservationsController::MAX_BABIES_PER_PROPERTY babies. Returns null
     * when the selection simply cannot hold the party (too few or too many
     * properties).
     *
     * @param array<int, int> $capacities max_guests per property id (0 = unknown)
     * @return array<int, array{adults: int, children_3to12: int, children_under3: int}>|null
     */
    private static function allocateParty(array $capacities, int $adults, int $children3to12, int $childrenUnder3): ?array
    {
        $counted = $adults + $children3to12;
        $propertyCount = count($capacities);
        if ($propertyCount === 0 || $counted < $propertyCount) {
            return null;
        }
        // Biggest properties first, so the party is spread over as few
        // rooms as possible; an unknown capacity (0) is treated as "can take
        // whatever is left".
        $effective = [];
        foreach ($capacities as $propertyId => $capacity) {
            $effective[$propertyId] = $capacity > 0 ? $capacity : $counted;
        }
        arsort($effective);

        $seats = [];
        foreach ($effective as $propertyId => $capacity) {
            $seats[$propertyId] = 1;
        }
        $remaining = $counted - $propertyCount;
        foreach ($effective as $propertyId => $capacity) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $capacity - 1);
            if ($take <= 0) {
                continue;
            }
            $seats[$propertyId] += $take;
            $remaining -= $take;
        }
        if ($remaining > 0) {
            return null;
        }

        $allocation = [];
        foreach ($seats as $propertyId => $count) {
            $allocation[$propertyId] = ['adults' => 0, 'children_3to12' => 0, 'children_under3' => 0];
        }
        $adultsLeft = $adults;
        if ($adultsLeft >= $propertyCount) {
            foreach ($allocation as $propertyId => $share) {
                $allocation[$propertyId]['adults'] = 1;
                $adultsLeft--;
            }
        }
        foreach ($seats as $propertyId => $count) {
            $free = $count - $allocation[$propertyId]['adults'];
            $take = min($adultsLeft, $free);
            $allocation[$propertyId]['adults'] += $take;
            $adultsLeft -= $take;
        }
        $childrenLeft = $children3to12;
        foreach ($seats as $propertyId => $count) {
            $free = $count - $allocation[$propertyId]['adults'];
            $take = min($childrenLeft, $free);
            $allocation[$propertyId]['children_3to12'] += $take;
            $childrenLeft -= $take;
        }
        $babiesLeft = $childrenUnder3;
        foreach ($seats as $propertyId => $count) {
            if ($babiesLeft <= 0) {
                break;
            }
            $take = min($babiesLeft, ReservationsController::MAX_BABIES_PER_PROPERTY);
            $allocation[$propertyId]['children_under3'] = $take;
            $babiesLeft -= $take;
        }
        if ($adultsLeft > 0 || $childrenLeft > 0 || $babiesLeft > 0) {
            return null;
        }
        return $allocation;
    }

    /**
     * Every alternative (check-in date, length) pair worth testing: the stay
     * shifted by up to FALLBACK_DAY_OFFSET days either way and shortened by
     * at most 2 nights (never below one night, never in the past, and never
     * the originally requested stay itself).
     *
     * @return array<int, array{0: \DateTimeImmutable, 1: int, 2: int, 3: int}>
     */
    private static function fallbackCombinations(\DateTimeImmutable $checkinDate, int $nights, \DateTimeImmutable $today): array
    {
        $maxNightsLost = min(self::FALLBACK_MAX_NIGHTS_LOST, max(0, $nights - 1));
        $combinations = [];
        for ($shift = -self::FALLBACK_DAY_OFFSET; $shift <= self::FALLBACK_DAY_OFFSET; $shift++) {
            $altCheckin = $checkinDate->modify(($shift >= 0 ? '+' : '-') . abs($shift) . ' days');
            if ($altCheckin < $today) {
                continue;
            }
            for ($lost = 0; $lost <= $maxNightsLost; $lost++) {
                if ($shift === 0 && $lost === 0) {
                    continue;
                }
                $combinations[] = [$altCheckin, $nights - $lost, $shift, $lost];
            }
        }
        return $combinations;
    }

    /**
     * True when every night of the stay is known *and* available in the
     * cached calendar and has a cached nightly rate. Missing cache data
     * always means "not offered", never "availability to be confirmed".
     *
     * The arrival date's cached min_stay is enforced too — exactly like the
     * ordinary calendar (files/views/partials/calendar-body.php) — so an
     * offer can never propose (nor let a client request) a stay shorter than
     * the minimum Lodgify sets for that date.
     *
     * @param array<string, bool> $availabilityMap
     * @param array<string, array<string, mixed>> $rateRows raw cached rate rows keyed by date
     */
    private static function stayCoveredByCache(array $availabilityMap, array $rateRows, \DateTimeImmutable $checkinDate, int $nights): bool
    {
        for ($offset = 0; $offset < $nights; $offset++) {
            $day = $checkinDate->modify('+' . $offset . ' days')->format('Y-m-d');
            if (!array_key_exists($day, $availabilityMap) || $availabilityMap[$day] !== true) {
                return false;
            }
            if (!array_key_exists($day, $rateRows) || (float) ($rateRows[$day]['price_per_night'] ?? 0) <= 0) {
                return false;
            }
        }
        $arrival = $checkinDate->format('Y-m-d');
        $minStay = isset($rateRows[$arrival]['min_stay']) ? (int) $rateRows[$arrival]['min_stay'] : 0;
        if ($minStay > 0 && $nights < $minStay) {
            return false;
        }
        return true;
    }

    /**
     * Nightly rate rows of a stay, taken from the rows already read once for
     * the whole fallback window, so pricing a candidate never re-reads the
     * cache. Only meaningful for stays validated by stayCoveredByCache(): any
     * missing night is skipped, leaving fewer rows than nights so
     * ReservationsController::cacheOnlyStayQuote() refuses to price the stay.
     *
     * @param array<string, array<string, mixed>> $rateRows keyed by date_from
     * @return array<int, array<string, mixed>>
     */
    private static function stayRateRows(array $rateRows, \DateTimeImmutable $checkinDate, int $nights): array
    {
        $rows = [];
        for ($offset = 0; $offset < $nights; $offset++) {
            $day = $checkinDate->modify('+' . $offset . ' days')->format('Y-m-d');
            if (isset($rateRows[$day])) {
                $rows[] = $rateRows[$day];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $property
     * @param array<string, mixed> $quote breakdown from ReservationsController::cacheOnlyStayQuote()
     * @return array<string, mixed>
     */
    private static function accommodationEntry(
        array $property,
        array $quote,
        string $checkin,
        string $checkout,
        int $nights,
        int $dayShift,
        int $nightsLost
    ): array {
        return [
            'property_id' => (int) $property['id'],
            'name' => View::localized($property, 'name'),
            'image_url' => $property['images'][0]['url'] ?? null,
            'bedrooms' => (int) ($property['bedrooms'] ?? 0),
            'max_guests' => (int) ($property['max_guests'] ?? 0),
            'checkin' => $checkin,
            'checkout' => $checkout,
            'nights' => $nights,
            'day_shift' => $dayShift,
            'nights_lost' => $nightsLost,
            'currency' => (string) ($quote['currency'] ?? 'EUR'),
            'room_total' => (float) ($quote['room_total'] ?? 0),
            'extra_person_total' => (float) ($quote['extra_person_total'] ?? 0),
            'cleaning_total' => (float) ($quote['cleaning_total'] ?? 0),
            'tourist_tax_total' => (float) ($quote['tourist_tax_total'] ?? 0),
            // Stay total *without* the tourist tax: the tax is paid on-site
            // and must never be folded into a "Total" shown to the client
            // (see PackagesController::publicSearch()'s $baseIncludes/
            // total_base).
            'total_traveler' => (float) ($quote['total_traveler'] ?? 0),
            // "Tout compris" for the accommodation part: what the traveller
            // pays for the stay, tourist tax included.
            'total_stay' => round((float) ($quote['total_traveler'] ?? 0) + (float) ($quote['tourist_tax_total'] ?? 0), 2),
        ];
    }

    /**
     * Builds the {type, nationality} guest list computeItemQuote() expects,
     * so the offer's tourist tax is computed from the party's declared
     * nationality (offer page's "Nationalité" field) exactly like the
     * ordinary property pages, instead of the conservative "every adult is a
     * foreign, taxable guest" fallback used when no guest detail is given.
     *
     * @return array<int, array{type: string, nationality: string}>
     */
    private static function guestsForNationality(int $adults, int $children3to12, int $childrenUnder3, string $nationality): array
    {
        $nationality = trim($nationality);
        if ($nationality === '') {
            return [];
        }
        $guests = [];
        for ($i = 0; $i < $adults; $i++) {
            $guests[] = ['type' => 'adult', 'nationality' => $nationality];
        }
        for ($i = 0; $i < $children3to12; $i++) {
            $guests[] = ['type' => 'child', 'nationality' => $nationality];
        }
        for ($i = 0; $i < $childrenUnder3; $i++) {
            $guests[] = ['type' => 'child_under3', 'nationality' => $nationality];
        }
        return $guests;
    }

    /**
     * @param array<int, array{type?: string, nationality?: string}> $guests
     * @return array<int, array{type: string, nationality: string}>
     */
    private static function normalizeGuests(
        int $adults,
        int $children3to12,
        int $childrenUnder3,
        string $nationality,
        array $guests
    ): array {
        $adultGuests = [];
        $childGuests = [];
        $babyGuests = [];
        foreach ($guests as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $guestNationality = trim((string) ($guest['nationality'] ?? ''));
            if ($guestNationality === '') {
                continue;
            }
            $type = (string) ($guest['type'] ?? 'adult');
            if ($type === 'adult') {
                $adultGuests[] = ['type' => 'adult', 'nationality' => $guestNationality];
                continue;
            }
            if ($type === 'child_under3') {
                $babyGuests[] = ['type' => 'child_under3', 'nationality' => $guestNationality];
                continue;
            }
            $childGuests[] = ['type' => 'child', 'nationality' => $guestNationality];
        }
        if (count($adultGuests) < $adults || count($childGuests) < $children3to12 || count($babyGuests) < $childrenUnder3) {
            return self::guestsForNationality($adults, $children3to12, $childrenUnder3, $nationality);
        }
        return array_merge(
            array_slice($adultGuests, 0, $adults),
            array_slice($childGuests, 0, $children3to12),
            array_slice($babyGuests, 0, $childrenUnder3)
        );
    }

    /**
     * @param array<int, array{type: string, nationality: string}> $guests
     * @return array{adults: array<int, string>, children: array<int, string>, babies: array<int, string>}
     */
    private static function guestPoolByType(array $guests): array
    {
        $pool = ['adults' => [], 'children' => [], 'babies' => []];
        foreach ($guests as $guest) {
            $nationality = trim((string) ($guest['nationality'] ?? ''));
            if ($nationality === '') {
                continue;
            }
            $type = (string) ($guest['type'] ?? 'adult');
            if ($type === 'adult') {
                $pool['adults'][] = $nationality;
                continue;
            }
            if ($type === 'child_under3') {
                $pool['babies'][] = $nationality;
                continue;
            }
            $pool['children'][] = $nationality;
        }
        return $pool;
    }

    /**
     * @param array{adults: array<int, string>, children: array<int, string>, babies: array<int, string>} &$pool
     * @param array{adults: int, children_3to12: int, children_under3: int} $share
     * @return array<int, array{type: string, nationality: string}>
     */
    private static function takeGuestShare(array &$pool, array $share): array
    {
        $result = [];
        for ($i = 0; $i < (int) $share['adults']; $i++) {
            $nationality = (string) array_shift($pool['adults']);
            $result[] = ['type' => 'adult', 'nationality' => $nationality];
        }
        for ($i = 0; $i < (int) $share['children_3to12']; $i++) {
            $nationality = (string) array_shift($pool['children']);
            $result[] = ['type' => 'child', 'nationality' => $nationality];
        }
        for ($i = 0; $i < (int) $share['children_under3']; $i++) {
            $nationality = (string) array_shift($pool['babies']);
            $result[] = ['type' => 'child_under3', 'nationality' => $nationality];
        }
        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private static function decorate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['partner_id'] = (int) $row['partner_id'];
        $row['all_properties'] = (int) ($row['all_properties'] ?? 1);
        $row['stock_limit'] = $row['stock_limit'] === null ? null : (int) $row['stock_limit'];
        return $row;
    }

    /**
     * Localized text of an offer/flight/activity row ("title"/"title_en",
     * "description"/"description_en", …).
     *
     * View::localized() must not be used here: it also looks the row's id up
     * in the property_translations index, where the same id belongs to a
     * completely different property.
     *
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $field): string
    {
        if (I18n::current() === 'en') {
            $english = trim((string) ($row[$field . '_en'] ?? ''));
            if ($english !== '') {
                return $english;
            }
        }
        return (string) ($row[$field] ?? '');
    }

    private static function priceMode(mixed $value): string
    {
        return (string) $value === self::PRICE_PER_GROUP ? self::PRICE_PER_GROUP : self::PRICE_PER_PERSON;
    }

    private static function money(mixed $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], (string) $value);
        return max(0.0, round((float) $normalized, 2));
    }

    private static function nullableText(mixed $value, ?int $maxLength = null): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return $maxLength !== null ? mb_substr($trimmed, 0, $maxLength) : $trimmed;
    }
}
