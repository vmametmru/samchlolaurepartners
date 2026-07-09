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
        $this->baseUrl = rtrim(Env::get('LODGIFY_BASE_URL', 'https://api.lodgify.com/v2') ?? 'https://api.lodgify.com/v2', '/');
        $this->apiKey = (string) Env::get('LODGIFY_API_KEY', '');
    }

    public function getProperties(): array
    {
        return $this->remember('lodgify:properties', 3600, function (): array {
            $data = $this->request('/properties');
            $items = is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
            return array_map([$this, 'mapProperty'], $items);
        });
    }

    public function getProperty(int $propertyId): array
    {
        return $this->remember('lodgify:property:' . $propertyId, 3600, fn(): array => $this->mapProperty($this->request('/properties/' . $propertyId)));
    }

    public function getAvailability(int $propertyId, string $from, string $to): array
    {
        return $this->remember('lodgify:availability:' . $propertyId . ':' . $from . ':' . $to, 1800, function () use ($propertyId, $from, $to): array {
            $data = $this->request('/availability/' . $propertyId, ['startDate' => $from, 'endDate' => $to]);
            if (!is_array($data)) {
                return [];
            }
            return array_map(static function ($day): array {
                return [
                    'date' => (string) ($day['date'] ?? ''),
                    'available' => (bool) ($day['available'] ?? false),
                    'min_stay' => (int) ($day['min_nights'] ?? $day['minStay'] ?? 1),
                ];
            }, $data);
        });
    }

    public function getRates(int $propertyId, string $from, string $to, int $guests): array
    {
        return $this->remember('lodgify:rates:' . $propertyId . ':' . $from . ':' . $to . ':' . $guests, 1800, function () use ($propertyId, $from, $to, $guests): array {
            $data = $this->request('/rates/' . $propertyId, ['startDate' => $from, 'endDate' => $to, 'numberOfGuests' => $guests]);
            $periods = is_array($data['periods'] ?? null) ? $data['periods'] : (is_array($data) ? $data : []);
            return array_map(static function ($rate) use ($from, $to): array {
                return [
                    'date_from' => (string) ($rate['start_date'] ?? $rate['startDate'] ?? $from),
                    'date_to' => (string) ($rate['end_date'] ?? $rate['endDate'] ?? $to),
                    'price_per_night' => (float) ($rate['price_per_night'] ?? $rate['price'] ?? $rate['pricePerNight'] ?? 0),
                    'currency' => (string) ($rate['currency'] ?? 'EUR'),
                ];
            }, $periods);
        });
    }

    public function invalidate(string $prefix = 'lodgify:'): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM lodgify_cache WHERE cache_key LIKE ?');
        $stmt->execute([$prefix . '%']);
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
            throw new RuntimeException('Lodgify API error ' . $status . ': ' . $body);
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
