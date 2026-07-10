<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class LodgifyClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $baseUrl = trim((string) (Settings::get('LODGIFY_BASE_URL') ?? ''));
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.lodgify.com/v2', '/');
        $this->apiKey = trim((string) (Settings::get('LODGIFY_API_KEY', '') ?? ''));
    }

    public function getProperties(): array
    {
        return $this->remember('lodgify:v2:properties', 86400, function (): array {
            $data = $this->request('/properties');
            $items = is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
            $properties = array_map([$this, 'mapProperty'], $items);
            // Neither "/properties" nor "/properties/{id}" ever populate bedrooms/
            // bathrooms/max_guests: their "rooms" array is only a RoomSummaryDto
            // (id + name), never the RoomDetailsDto that carries those numbers.
            // Without this, capacity always mapped to 0, which let the booking
            // form and server-side quote/request checks accept any party size.
            foreach ($properties as &$property) {
                $propertyId = (int) ($property['id'] ?? 0);
                if ($propertyId > 0) {
                    $property = $this->applyRoomCapacity($property, $propertyId);
                }
            }
            unset($property);
            Settings::set('LODGIFY_LAST_SYNC_AT', gmdate('c'));
            return $properties;
        });
    }

    public function getProperty(int $propertyId): array
    {
        return $this->remember('lodgify:v2:property:' . $propertyId, 86400, function () use ($propertyId): array {
            $property = $this->mapProperty($this->request('/properties/' . $propertyId));

            // Lodgify's "/properties/{id}" endpoint (PropertyDto) never returns an
            // "images" array — only a single "image_url" string — so the previous
            // code always fell back to one photo, no matter how many times the
            // cache was refreshed. The real multi-photo galleries only exist per
            // room type, via "/properties/{id}/rooms" (RoomDetailsDto.images), which
            // is also how Lodgify itself groups photos by room in its own listing
            // page. Fetch and cache those here so the detail page can show every
            // photo, grouped by room, from local copies.
            $rooms = $this->getPropertyRoomsDetails($propertyId);
            $photoRooms = [];
            $allImages = [];
            foreach ($rooms as $room) {
                $roomImages = array_map(
                    static fn(array $image): array => [
                        'url' => ImageCache::cache($image['url'], $propertyId),
                        'text' => $image['text'],
                    ],
                    $room['images']
                );
                if ($roomImages !== []) {
                    $photoRooms[] = ['id' => $room['id'], 'name' => $room['name'], 'images' => $roomImages];
                    array_push($allImages, ...$roomImages);
                }
            }

            if ($allImages === []) {
                // No room photos available: fall back to the single property image
                // (still cached locally) so the gallery is never completely empty.
                $allImages = array_map(
                    static fn(array $image): array => [
                        'url' => ImageCache::cache($image['url'], $propertyId),
                        'text' => $image['text'],
                    ],
                    $property['images']
                );
            }

            $property['images'] = $allImages;
            $property['photo_rooms'] = $photoRooms;
            $property['room_details'] = $rooms;

            // As with getProperties(), the plain property payload's "rooms" only
            // carries id/name (RoomSummaryDto), never capacity — aggregate the
            // real numbers from the RoomDetailsDto rooms already fetched above.
            $property = $this->sumRoomCapacity($property, $rooms);

            $rateSettings = $this->getRateSettingsFor($propertyId);
            $property['checkin_hour'] = $rateSettings['check_in_hour'];
            $property['checkout_hour'] = $rateSettings['check_out_hour'];
            $property['fees'] = $rateSettings['fees'];

            // Merge per-room categorized amenities (e.g. "Cuisine et salle à manger" =>
            // [...]) since the property-level "amenities" field doesn't exist on
            // Lodgify's PropertyDto either; only rooms expose amenities, grouped by
            // category, matching how Lodgify displays them on its own listing page.
            $amenitiesByCategory = [];
            foreach ($rooms as $room) {
                foreach ($room['amenities_by_category'] as $category => $names) {
                    $amenitiesByCategory[$category] = array_values(array_unique(array_merge($amenitiesByCategory[$category] ?? [], $names)));
                }
            }
            $property['amenities_by_category'] = $amenitiesByCategory;
            if ($property['amenities'] === [] && $amenitiesByCategory !== []) {
                foreach ($amenitiesByCategory as $names) {
                    foreach ($names as $name) {
                        $property['amenities'][] = ['name' => $name];
                    }
                }
            }

            return $property;
        });
    }

    /**
     * Fetches and maps "/properties/{id}/rooms" (RoomDetailsDto[]), the only
     * Lodgify v2 endpoint that actually returns per-room photo galleries and
     * categorized amenities — none of which are present on the plain property
     * detail response. Failures are swallowed (returns []) so a single Lodgify
     * hiccup never breaks the whole property page.
     */
    private function getPropertyRoomsDetails(int $propertyId): array
    {
        return $this->remember('lodgify:v2:rooms:' . $propertyId, 86400, function () use ($propertyId): array {
            return $this->fetchPropertyRoomsDetails($propertyId);
        });
    }

    /**
     * Uncached diagnostic helper: reports why room-capacity (bedrooms/
     * bathrooms/max_guests) came back empty for a property, e.g. because
     * "/properties/{id}/rooms" itself failed. applyRoomCapacity()/
     * sumRoomCapacity() silently swallow that failure (falling back to 0) so
     * a single Lodgify hiccup never breaks the whole property page, which
     * otherwise leaves an admin with no way to tell "genuinely 0 capacity"
     * apart from "the rooms endpoint failed".
     */
    public function getRoomCapacityDebug(int $propertyId): array
    {
        try {
            $raw = $this->request('/properties/' . $propertyId . '/rooms');
            $count = is_array($raw) ? count($raw) : 0;
            return ['room_count' => $count, 'error' => null];
        } catch (\Throwable $e) {
            return ['room_count' => 0, 'error' => $e->getMessage()];
        }
    }

    private function fetchPropertyRoomsDetails(int $propertyId): array
    {
        try {
            $raw = $this->request('/properties/' . $propertyId . '/rooms');
        } catch (\Throwable $e) {
            error_log('Lodgify: failed to fetch rooms for property ' . $propertyId . ': ' . $e->getMessage());
            return [];
        }
        $items = is_array($raw) ? $raw : [];
        $rooms = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $images = [];
            foreach (($item['images'] ?? []) as $image) {
                if (is_array($image)) {
                    $url = (string) ($image['url'] ?? '');
                    if ($url !== '') {
                        $images[] = ['url' => $url, 'text' => isset($image['text']) ? (string) $image['text'] : null];
                    }
                }
            }
            $amenitiesByCategory = [];
            foreach (($item['amenities'] ?? []) as $category => $amenityList) {
                if (!is_array($amenityList)) {
                    continue;
                }
                $names = [];
                foreach ($amenityList as $amenity) {
                    $name = is_array($amenity) ? (string) ($amenity['name'] ?? $amenity['text'] ?? '') : (string) $amenity;
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
                if ($names !== []) {
                    $amenitiesByCategory[(string) $category] = $names;
                }
            }
            $rooms[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'images' => $images,
                'bedrooms' => (int) ($item['bedrooms'] ?? 0),
                'bathrooms' => (int) ($item['bathrooms'] ?? 0),
                'max_people' => (int) ($item['max_people'] ?? 0),
                'has_parking' => (bool) ($item['has_parking'] ?? false),
                'has_wifi' => (bool) ($item['has_wifi'] ?? false),
                'pets_allowed' => $item['pets_allowed'] ?? null,
                'adults_only' => (bool) ($item['adults_only'] ?? false),
                'amenities_by_category' => $amenitiesByCategory,
            ];
        }
        return $rooms;
    }

    /**
     * Fetches "/properties/{id}/rooms" for the given property and overwrites
     * bedrooms/bathrooms/max_guests on the mapped property with the sum of
     * each room's real capacity (see sumRoomCapacity()). Used by getProperties()
     * where room details haven't already been fetched.
     */
    private function applyRoomCapacity(array $property, int $propertyId): array
    {
        try {
            $rooms = $this->getPropertyRoomsDetails($propertyId);
        } catch (\Throwable $e) {
            error_log('Lodgify: failed to compute room capacity for property ' . $propertyId . ': ' . $e->getMessage());
            return $property;
        }
        return $this->sumRoomCapacity($property, $rooms);
    }

    /**
     * Aggregates bedrooms/bathrooms/max_guests from a property's room details
     * (RoomDetailsDto[], from "/properties/{id}/rooms"). This is the only
     * Lodgify v2 endpoint that actually exposes these numbers: the "rooms"
     * array embedded in "/properties" and "/properties/{id}" (RoomSummaryDto)
     * only ever contains "id" and "name".
     */
    private function sumRoomCapacity(array $property, array $rooms): array
    {
        $bedrooms = 0;
        $bathrooms = 0;
        $maxGuests = 0;
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $bedrooms += (int) ($room['bedrooms'] ?? 0);
            $bathrooms += (int) ($room['bathrooms'] ?? 0);
            $maxGuests += (int) ($room['max_people'] ?? 0);
        }
        if ($bedrooms > 0) {
            $property['bedrooms'] = $bedrooms;
        }
        if ($bathrooms > 0) {
            $property['bathrooms'] = $bathrooms;
        } elseif ($bedrooms > 0 || $maxGuests > 0) {
            // Lodgify's per-room "bathrooms" field is optional and is very
            // often left unset by hosts even though bedrooms/max_people are
            // always filled in, which made the detail page permanently show
            // "0 salle(s) de bain" for otherwise fully configured properties.
            // Every bookable accommodation has at least one bathroom, so
            // default to 1 instead of displaying an obviously wrong 0.
            $property['bathrooms'] = 1;
        }
        if ($maxGuests > 0) {
            $property['max_guests'] = $maxGuests;
        }
        return $property;
    }

    /**
     * Fetches "/rates/settings" for a property's house id, which is the only
     * place Lodgify exposes check-in/check-out hours and per-guest/per-stay fees
     * (e.g. the extra-guest fee shown on Lodgify's own Tarifs tab).
     */
    private function getRateSettingsFor(int $propertyId): array
    {
        $default = ['check_in_hour' => null, 'check_out_hour' => null, 'fees' => []];
        try {
            $data = $this->request('/rates/settings', ['houseId' => $propertyId]);
        } catch (\Throwable $e) {
            error_log('Lodgify: failed to fetch rate settings for property ' . $propertyId . ': ' . $e->getMessage());
            return $default;
        }
        $fees = [];
        foreach (($data['fees'] ?? []) as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $fees[] = [
                'name' => (string) ($fee['fee_name'] ?? ''),
                'charge_type' => (string) ($fee['charge_type'] ?? ''),
                'frequency' => (string) ($fee['frequency'] ?? ''),
                'amount' => isset($fee['price']['amount']) ? (float) $fee['price']['amount'] : null,
            ];
        }
        return [
            'check_in_hour' => isset($data['check_in_hour']) ? (int) $data['check_in_hour'] : null,
            'check_out_hour' => isset($data['check_out_hour']) ? (int) $data['check_out_hour'] : null,
            'fees' => $fees,
        ];
    }

    public function getAvailability(int $propertyId, string $from, string $to): array
    {
        // Lodgify's real v2 endpoint expects "start"/"end" query params (not
        // "startDate"/"endDate") and returns an array of per-room-type calendars,
        // each with a "periods" list of { start, end, available } — "available" is
        // the number of bookable units for that period, not a per-day boolean.
        // Using the wrong param names made Lodgify ignore the requested range
        // (returning unrelated/empty data), which is why searches always showed
        // "0 hébergements disponibles" regardless of real availability.
        $data = $this->request('/availability/' . $propertyId, [
            'start' => $from,
            'end' => $to,
            'includeDetails' => 'false',
        ]);
        if (!is_array($data)) {
            return [];
        }

        try {
            $rangeStart = new \DateTimeImmutable($from);
            $rangeEnd = new \DateTimeImmutable($to);
        } catch (\Throwable) {
            return [];
        }
        if ($rangeStart >= $rangeEnd) {
            return [];
        }

        // Every day in the requested window defaults to available: real Lodgify
        // responses only carry a period for a night when a booking/closed_period
        // actually restricts it. When a room type has nothing booked in range at
        // all, Lodgify returns a single degenerate period stamped with the
        // sentinel date "0001-01-01" (start === end, entirely outside the
        // requested window) instead of one spanning [from, to). Previously that
        // meant no day of that room type ever got an explicit "available" entry,
        // so every gap between two bookings (and everything after the last one)
        // rendered as "unknown"/unclickable instead of bookable.
        $merged = [];
        for ($cursor = $rangeStart; $cursor < $rangeEnd; $cursor = $cursor->modify('+1 day')) {
            $merged[$cursor->format('Y-m-d')] = false;
        }

        $roomTypeCalendars = array_values(array_filter($data, static fn($item): bool => is_array($item)));
        if ($roomTypeCalendars === []) {
            return [];
        }

        foreach ($roomTypeCalendars as $roomTypeCalendar) {
            // Per room type, days default to available=true and only flip to
            // false where a period explicitly reports zero available units.
            $roomDays = array_fill_keys(array_keys($merged), true);
            $periods = is_array($roomTypeCalendar['periods'] ?? null) ? $roomTypeCalendar['periods'] : [];
            foreach ($periods as $period) {
                if (!is_array($period)) {
                    continue;
                }
                $available = (int) ($period['available'] ?? 0);
                try {
                    $periodStart = new \DateTimeImmutable((string) ($period['start'] ?? ''));
                    $periodEndRaw = new \DateTimeImmutable((string) ($period['end'] ?? ''));
                } catch (\Throwable) {
                    continue;
                }
                if ($periodStart >= $periodEndRaw) {
                    // Degenerate (zero-width) period: Lodgify uses this to convey
                    // "this status applies to the whole request" rather than a
                    // specific sub-range, so apply it across
                    // [rangeStart, rangeEnd) instead of skipping it.
                    $cursor = $rangeStart;
                    $limit = $rangeEnd;
                } else {
                    // For a genuine period, Lodgify's "end" is the last actually
                    // unavailable night (inclusive), not an exclusive checkout
                    // boundary: treating it as exclusive left the last blocked
                    // night of every booking (e.g. the night before checkout)
                    // showing as free, shrinking each booking's unavailable
                    // range by one day and shifting the following free gap
                    // one day earlier than reality. Extend by one day to get
                    // the exclusive boundary our day-by-day loop expects.
                    $periodEnd = $periodEndRaw->modify('+1 day');
                    if ($periodEnd <= $rangeStart || $periodStart >= $rangeEnd) {
                        // Entirely out-of-range period: same "applies to the
                        // whole request" convention as the degenerate case.
                        $cursor = $rangeStart;
                        $limit = $rangeEnd;
                    } else {
                        $cursor = max($periodStart, $rangeStart);
                        $limit = min($periodEnd, $rangeEnd);
                    }
                }
                if ($available > 0) {
                    continue;
                }
                while ($cursor < $limit) {
                    $roomDays[$cursor->format('Y-m-d')] = false;
                    $cursor = $cursor->modify('+1 day');
                }
            }
            // A property is bookable for a given night if at least one of its
            // room types is available that night (OR merge across room types).
            foreach ($roomDays as $day => $isAvailable) {
                $merged[$day] = $merged[$day] || $isAvailable;
            }
        }

        $days = [];
        foreach ($merged as $day => $isAvailable) {
            $days[] = ['date' => $day, 'available' => $isAvailable];
        }
        ksort($days);
        return array_values($days);
    }

    /**
     * Whether the given property is available for every night of [$from, $to).
     * Used to filter home-page search results down to properties genuinely
     * bookable for the requested dates, instead of returning every property
     * regardless of availability.
     */
    public function isAvailableForRange(int $propertyId, string $from, string $to): bool
    {
        try {
            $days = $this->getAvailability($propertyId, $from, $to);
        } catch (\Throwable $e) {
            return false;
        }
        if ($days === []) {
            return false;
        }
        $nights = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor < $end) {
            $nights[$cursor->format('Y-m-d')] = false;
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($days as $day) {
            if (array_key_exists($day['date'], $nights) && $day['available']) {
                $nights[$day['date']] = true;
            }
        }
        return $nights !== [] && !in_array(false, $nights, true);
    }

    public function getRates(int $propertyId, string $from, string $to, int $guests): array
    {
        // Lodgify's real endpoint for nightly rates is /v2/rates/calendar, keyed by
        // houseId + roomTypeId (not /v2/rates/{propertyId} with numberOfGuests,
        // which doesn't exist and previously made this call silently fail/return
        // nothing usable).
        $roomTypeId = $this->getPrimaryRoomTypeId($propertyId);
        if ($roomTypeId === null) {
            return [];
        }
        $data = $this->request('/rates/calendar', [
            'houseId' => $propertyId,
            'roomTypeId' => $roomTypeId,
            'startDate' => $from,
            'endDate' => $to,
        ]);
        $items = is_array($data['calendar_items'] ?? null) ? $data['calendar_items'] : [];
        $currency = (string) ($data['rate_settings']['currency_code'] ?? 'EUR');

        $rates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $date = (string) ($item['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $prices = is_array($item['prices'] ?? null) ? $item['prices'] : [];
            $pricePerNight = 0.0;
            $minStay = null;
            foreach ($prices as $price) {
                if (is_array($price)) {
                    if (isset($price['price_per_day']) && $pricePerNight === 0.0) {
                        $pricePerNight = (float) $price['price_per_day'];
                    }
                    // Lodgify only exposes the minimum-stay restriction here
                    // (CalendarPrice.min_stay), never on the Availability endpoint.
                    if ($minStay === null && isset($price['min_stay'])) {
                        $minStay = (int) $price['min_stay'];
                    }
                    if ($pricePerNight !== 0.0 && $minStay !== null) {
                        break;
                    }
                }
            }
            $rates[] = [
                'date_from' => $date,
                'date_to' => $date,
                'price_per_night' => $pricePerNight,
                'currency' => $currency,
                'min_stay' => $minStay,
            ];
        }
        return $rates;
    }

    /**
     * Resolves the primary room type id for a property, required by the Lodgify
     * rates/calendar endpoint. Most rentals on Lodgify have a single room type.
     */
    private function getPrimaryRoomTypeId(int $propertyId): ?int
    {
        $cached = $this->remember('lodgify:v2:roomtype:' . $propertyId, 86400, function () use ($propertyId): array {
            $roomTypeId = null;
            try {
                $raw = $this->request('/properties/' . $propertyId);
                $rooms = is_array($raw['rooms'] ?? null) ? $raw['rooms'] : [];
                foreach ($rooms as $room) {
                    if (is_array($room) && isset($room['id'])) {
                        $roomTypeId = (int) $room['id'];
                        break;
                    }
                }
            } catch (\Throwable) {
                $roomTypeId = null;
            }
            return ['room_type_id' => $roomTypeId];
        });
        return $cached['room_type_id'] ?? null;
    }

    public function invalidate(string $prefix = 'lodgify:'): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM lodgify_cache WHERE cache_key LIKE ?');
        $stmt->execute([$prefix . '%']);
    }

    /**
     * Refreshes the per-property detail cache (name, description, full images
     * gallery, ...) for every known property. getProperties() only fetches the
     * compact list endpoint, which Lodgify limits to a single image per
     * property, so the "sync" action needs this extra pass to actually reload
     * and re-cache all the gallery photos shown on each property detail page —
     * otherwise a resync only refreshes property cards and leaves stale/empty
     * detail-page photos until someone happens to open that property's page.
     */
    public function refreshAllPropertyDetails(): int
    {
        $refreshed = 0;
        foreach ($this->getProperties() as $property) {
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }
            try {
                $this->getProperty($propertyId);
                $refreshed++;
            } catch (\Throwable $e) {
                error_log('Lodgify sync: failed to refresh property ' . $propertyId . ': ' . $e->getMessage());
            }
        }
        return $refreshed;
    }

    public function getRawProperties(): array
    {
        $data = $this->request('/properties');
        $items = is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
        return $items;
    }

    /**
     * Lightweight, independent connectivity check against the Lodgify API.
     * Unlike getProperties()/getProperty(), this never reads or writes the local
     * cache and never attempts to map the response, so it can pinpoint whether a
     * failure is caused by DNS/network issues, an invalid API key, or an HTTP
     * error from Lodgify itself — regardless of any bug in the mapping layer.
     */
    public function testConnectivity(): array
    {
        $result = [
            'ok' => false,
            'base_url' => $this->baseUrl,
            'api_key_set' => $this->apiKey !== '',
            'resolved_ip' => null,
            'http_status' => null,
            'duration_ms' => null,
            'error' => null,
        ];

        if ($this->apiKey === '') {
            $result['error'] = 'LODGIFY_API_KEY n\'est pas configurée.';
            return $result;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $ip = gethostbyname($host);
            $result['resolved_ip'] = $ip !== $host ? $ip : null;
            if ($ip === $host) {
                $result['error'] = 'Résolution DNS impossible pour l\'hôte : ' . $host;
                return $result;
            }
        }

        $url = $this->baseUrl . '/properties?' . http_build_query(['page' => 1, 'size' => 1]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-ApiKey: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $start = microtime(true);
        $body = curl_exec($ch);
        $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result['http_status'] = $status ?: null;

        if ($body === false || $curlErrno !== 0) {
            $result['error'] = 'Erreur cURL (' . $curlErrno . ') : ' . ($curlError !== '' ? $curlError : 'connexion impossible');
            return $result;
        }
        if ($status >= 400) {
            $result['error'] = 'HTTP ' . $status . ' : ' . mb_strimwidth((string) $body, 0, 300, '…');
            return $result;
        }

        $result['ok'] = true;
        return $result;
    }

    private function request(string $path, array $params = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('LODGIFY_API_KEY is not set');
        }

        $url = $this->baseUrl . $path;
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-ApiKey: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Lodgify request failed: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status >= 400) {
            throw new LodgifyApiException($status, 'Lodgify API error ' . $status . ': ' . $body);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function remember(string $key, int $ttl, callable $callback): array
    {
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        $this->cacheSet($key, $value, $ttl);
        return $value;
    }

    private function cacheGet(string $key): ?array
    {
        $stmt = Database::connection()->prepare('SELECT data FROM lodgify_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) $row['data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function cacheSet(string $key, array $value, int $ttl): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO lodgify_cache (cache_key, data, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at), created_at = NOW()'
        );
        $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ttl]);
    }

    private function mapProperty(array $item): array
    {
        $images = [];
        foreach (($item['images'] ?? []) as $image) {
            if (is_array($image)) {
                $images[] = [
                    'url' => (string) ($image['url'] ?? $image['src'] ?? ''),
                    'text' => isset($image['text']) ? (string) $image['text'] : null,
                ];
            }
        }
        // Fallback: list endpoint returns a single image_url string instead of an images array
        if ($images === [] && isset($item['image_url']) && (string) $item['image_url'] !== '') {
            $images[] = ['url' => (string) $item['image_url'], 'text' => null];
        }

        $amenities = [];
        foreach (($item['amenities'] ?? []) as $amenity) {
            $amenities[] = ['name' => is_array($amenity) ? (string) ($amenity['name'] ?? '') : (string) $amenity];
        }

        // Lodgify's PropertyDto ("/properties" and "/properties/{id}") never
        // exposes bedrooms/bathrooms/capacity as root-level fields, and its
        // embedded "rooms" array is only a RoomSummaryDto (id + name) — the real
        // numbers only exist per room-type via "/properties/{id}/rooms"
        // (RoomDetailsDto), aggregated afterwards in applyRoomCapacity()/
        // sumRoomCapacity(). These root-level fallbacks are kept in case Lodgify
        // ever starts returning them directly.
        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'images' => $images,
            'amenities' => $amenities,
            'latitude' => isset($item['latitude']) ? (float) $item['latitude'] : null,
            'longitude' => isset($item['longitude']) ? (float) $item['longitude'] : null,
            'max_guests' => (int) ($item['people_capacity'] ?? $item['max_guests'] ?? $item['maxGuests'] ?? 0),
            'bedrooms' => (int) ($item['rooms_count'] ?? $item['bedrooms'] ?? 0),
            'bathrooms' => (int) ($item['bathrooms_count'] ?? $item['bathrooms'] ?? 0),
        ];
    }
}
