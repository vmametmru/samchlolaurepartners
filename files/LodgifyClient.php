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
            Settings::set('LODGIFY_LAST_SYNC_AT', gmdate('c'));
            return $properties;
        });
    }

    public function getProperty(int $propertyId): array
    {
        return $this->remember('lodgify:v2:property:' . $propertyId, 86400, fn(): array => $this->mapProperty($this->request('/properties/' . $propertyId)));
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

        $days = [];
        foreach ($data as $roomTypeCalendar) {
            if (!is_array($roomTypeCalendar)) {
                continue;
            }
            $periods = is_array($roomTypeCalendar['periods'] ?? null) ? $roomTypeCalendar['periods'] : [];
            foreach ($periods as $period) {
                if (!is_array($period)) {
                    continue;
                }
                $start = (string) ($period['start'] ?? '');
                $end = (string) ($period['end'] ?? '');
                if ($start === '' || $end === '') {
                    continue;
                }
                $available = (int) ($period['available'] ?? 0);
                $minStay = (int) ($period['min_stay'] ?? $period['minStay'] ?? 1);
                try {
                    $cursor = new \DateTimeImmutable($start);
                    $periodEnd = new \DateTimeImmutable($end);
                } catch (\Throwable) {
                    continue;
                }
                // A property is bookable for a given night if at least one of its
                // room types reports available units for that night.
                while ($cursor < $periodEnd) {
                    $day = $cursor->format('Y-m-d');
                    $wasAvailable = $days[$day]['available'] ?? false;
                    $days[$day] = [
                        'date' => $day,
                        'available' => $wasAvailable || $available > 0,
                        'min_stay' => $minStay,
                    ];
                    $cursor = $cursor->modify('+1 day');
                }
            }
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
            foreach ($prices as $price) {
                if (is_array($price) && isset($price['price_per_day'])) {
                    $pricePerNight = (float) $price['price_per_day'];
                    break;
                }
            }
            $rates[] = [
                'date_from' => $date,
                'date_to' => $date,
                'price_per_night' => $pricePerNight,
                'currency' => $currency,
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

        // The Lodgify API does not expose bedrooms/bathrooms/capacity as root-level
        // fields: they are only available per room-type inside the "rooms" array, so
        // we aggregate them from there when the root-level fields are absent.
        $roomsBedrooms = 0;
        $roomsBathrooms = 0;
        $roomsMaxPeople = 0;
        foreach (($item['rooms'] ?? []) as $room) {
            if (!is_array($room)) {
                continue;
            }
            $roomsBedrooms += (int) ($room['bedrooms'] ?? 0);
            $roomsBathrooms += (int) ($room['bathrooms'] ?? 0);
            $roomsMaxPeople += (int) ($room['max_people'] ?? $room['maxPeople'] ?? 0);
        }

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'images' => $images,
            'amenities' => $amenities,
            'latitude' => isset($item['latitude']) ? (float) $item['latitude'] : null,
            'longitude' => isset($item['longitude']) ? (float) $item['longitude'] : null,
            'max_guests' => (int) ($item['people_capacity'] ?? $item['max_guests'] ?? $item['maxGuests'] ?? $roomsMaxPeople),
            'bedrooms' => (int) ($item['rooms_count'] ?? $item['bedrooms'] ?? $roomsBedrooms),
            'bathrooms' => (int) ($item['bathrooms_count'] ?? $item['bathrooms'] ?? $roomsBathrooms),
        ];
    }
}
