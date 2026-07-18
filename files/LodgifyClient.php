<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class LodgifyClient
{
    private string $baseUrl;
    private string $apiKey;

    /**
     * TTL (10 years) used for "fiche" data — property name, description,
     * photo gallery, rooms, capacity, amenities, room-type id, sofa-bed
     * counts, ... — which must NOT be refreshed automatically anymore.
     * Automatic refresh caused reservation emails and detail pages to be
     * slowed down or broken by live Lodgify calls, and made it impossible to
     * keep photos stable/normalized on the server. This data is now only
     * ever refreshed by the manual "Synchroniser maintenant" admin action
     * (PageController::adminSync() -> Scheduler::syncLodgify()), which
     * explicitly invalidates these cache keys before refetching. Prices and
     * availability are unaffected: they are always fetched live at search
     * time (see getAvailability()/getRates(), never cached) or refreshed on
     * their own short TTL (getPriceStatusSnapshot(), 30 min).
     */
    private const FICHE_TTL = 315360000;

    public function __construct()
    {
        $baseUrl = trim((string) (Settings::get('LODGIFY_BASE_URL') ?? ''));
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.lodgify.com/v2', '/');
        $this->apiKey = trim((string) (Settings::get('LODGIFY_API_KEY', '') ?? ''));
    }

    public function getProperties(): array
    {
        return $this->remember('lodgify:v2:properties', self::FICHE_TTL, function (): array {
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
        return $this->remember('lodgify:v2:property:' . $propertyId, self::FICHE_TTL, function () use ($propertyId): array {
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
            // Numbered sequentially across every room's photos (not reset per
            // room) so the manual sync produces predictable, stable filenames
            // per property: photo1.jpg = first photo, photo2.jpg = second, ...
            // matching the order the gallery is displayed in.
            $photoIndex = 0;
            foreach ($rooms as $room) {
                $roomImages = [];
                foreach ($room['images'] as $image) {
                    $photoIndex++;
                    $roomImages[] = [
                        'url' => ImageCache::cache($image['url'], $propertyId, $photoIndex),
                        'text' => $image['text'],
                    ];
                }
                if ($roomImages !== []) {
                    $photoRooms[] = ['id' => $room['id'], 'name' => $room['name'], 'images' => $roomImages];
                    array_push($allImages, ...$roomImages);
                }
            }

            if ($allImages === []) {
                // No room photos available: fall back to the single property image
                // (still cached locally) so the gallery is never completely empty.
                foreach ($property['images'] as $image) {
                    $photoIndex++;
                    $allImages[] = [
                        'url' => ImageCache::cache($image['url'], $propertyId, $photoIndex),
                        'text' => $image['text'],
                    ];
                }
            }

            $property['images'] = $allImages;
            $property['photo_rooms'] = $photoRooms;
            $property['room_details'] = $rooms;

            // As with getProperties(), the plain property payload's "rooms" only
            // carries id/name (RoomSummaryDto), never capacity — aggregate the
            // real numbers from the RoomDetailsDto rooms already fetched above.
            $property = $this->sumRoomCapacity($property, $rooms, $propertyId);

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
     * Returns the local "photo1" URL for a property (the normalized first
     * photo produced by the manual Lodgify sync). Fiche data (photos,
     * description, ...) is only refreshed through the manual admin sync
     * action, never automatically/live — so building a reservation email can
     * never be slowed down or broken by the full getProperty() pipeline
     * (per-room photo galleries, rates, amenities, ...), and the photo it
     * links to is always a stable local file instead of a hotlinked remote
     * URL that some webmail clients refuse to load (showing a placeholder).
     *
     * If that property has never been synced yet (no local photo1.* file),
     * a single lightweight raw "/properties/{id}" call (no room/gallery
     * fetch, no cache write) is made to grab its primary photo and cache it
     * locally as photo1, so the very first reservation request for a
     * not-yet-synced property still ships a photo instead of leaving the
     * email without one until an admin remembers to run the manual sync.
     * Any failure here is swallowed (returns '') so a Lodgify hiccup never
     * breaks the reservation email.
     */
    public function getPropertyPhotoUrl(int $propertyId): string
    {
        if ($propertyId <= 0) {
            return '';
        }

        $dir = BASE_PATH . '/images/listings/' . $propertyId;
        foreach (ImageCache::ALLOWED_EXTENSIONS as $extension) {
            $path = $dir . '/photo1.' . $extension;
            if (is_file($path) && filesize($path) > 0) {
                return '/images/listings/' . $propertyId . '/photo1.' . $extension;
            }
        }

        try {
            $remoteUrl = $this->fetchPrimaryPhotoUrl($propertyId);
        } catch (\Throwable $e) {
            error_log('Lodgify: failed to fetch fallback photo for property ' . $propertyId . ': ' . $e->getMessage());
            return '';
        }
        if ($remoteUrl === '') {
            return '';
        }

        $cachedPath = ImageCache::cache($remoteUrl, $propertyId, 1);
        // ImageCache::cache() falls back to returning the original remote URL
        // untouched when the download itself fails: only accept a genuine
        // local path here, never hotlink Lodgify's CDN directly.
        return str_starts_with($cachedPath, '/images/listings/') ? $cachedPath : '';
    }

    /**
     * Uncached, lightweight fetch of a property's primary photo URL directly
     * from Lodgify's plain "/properties/{id}" endpoint (no per-room gallery
     * fetch via "/properties/{id}/rooms", unlike getProperty()), used only as
     * a one-time backfill by getPropertyPhotoUrl() when a property has never
     * been synced yet.
     */
    private function fetchPrimaryPhotoUrl(int $propertyId): string
    {
        $mapped = $this->mapProperty($this->request('/properties/' . $propertyId));
        $images = $mapped['images'] ?? [];
        return trim((string) ($images[0]['url'] ?? ''));
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
        return $this->remember('lodgify:v2:rooms:' . $propertyId, self::FICHE_TTL, function () use ($propertyId): array {
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
            $sofaBedCount = $this->countSofaBeds($item);
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
                'sofa_bed_count' => $sofaBedCount,
            ];
        }
        return $rooms;
    }

    /**
     * Counts sofa beds ("Canapé-lit") declared on a RoomDetailsDto item (or,
     * recursively, on any of its nested room levels). Per Lodgify's actual
     * response shape, a room-type item nests its physical sub-rooms under
     * "rooms" (or "sub_rooms" in some responses), and — depending on the
     * endpoint/payload — those sub-rooms can themselves nest another level
     * of physical rooms before finally exposing a "beds" array of {"type":
     * "SofaBed"|"DoubleSofaBed", "count": n} (Lodgify also uses "quantity"/
     * "bed_types"/"bedTypes" in some payloads). This recurses through every
     * nesting level instead of stopping after one, so a sofa bed declared
     * two (or more) levels down isn't missed. Bed "type" values are matched
     * case-insensitively against "sofabed"/"doublesofabed", with a
     * defensive fallback to any label containing "sofa" or "canap" (French)
     * in case of other bed-type variants.
     */
    private function countSofaBeds(array $item): int
    {
        $beds = $item['beds'] ?? $item['bed_types'] ?? $item['bedTypes'] ?? null;
        $count = $this->countSofaBedsInBedsArray($beds);

        $subRooms = $item['rooms'] ?? $item['sub_rooms'] ?? $item['subRooms']
            ?? $item['type_rooms'] ?? $item['typeRooms'] ?? $item['roomTypes'] ?? $item['room_types'] ?? [];
        if (is_array($subRooms)) {
            foreach ($subRooms as $subRoom) {
                if (is_array($subRoom)) {
                    $count += $this->countSofaBeds($subRoom);
                }
            }
        }
        // Some hosts configure the sofa bed under Lodgify's "Amenities"
        // section (e.g. "Canapé-lit") rather than the room's bed
        // composition, which the checks above never see. Count it too, as
        // a last-resort fallback, so the admin page doesn't show "Non" for
        // a property that genuinely has one configured in Lodgify.
        if ($count === 0) {
            $count += $this->countSofaBedsInAmenities($item['amenities'] ?? null);
        }
        return $count;
    }

    /**
     * Defensive fallback: counts amenity entries (as returned by Lodgify,
     * either a flat list or a map of category => list) whose name matches a
     * sofa-bed label. Amenities never carry a quantity, so each match counts
     * as 1.
     */
    private function countSofaBedsInAmenities(mixed $amenities): int
    {
        if (!is_array($amenities)) {
            return 0;
        }
        $count = 0;
        foreach ($amenities as $amenityOrList) {
            $list = is_array($amenityOrList) && array_is_list($amenityOrList) ? $amenityOrList : [$amenityOrList];
            foreach ($list as $amenity) {
                $name = is_array($amenity) ? (string) ($amenity['name'] ?? $amenity['text'] ?? '') : (string) $amenity;
                if ($name !== '' && $this->isSofaBedLabel($name)) {
                    $count += 1;
                }
            }
        }
        return $count;
    }

    /**
     * Sums the sofa-bed "count"/"quantity" across a single bed[] array,
     * matching entries whose "type" (or "name"/"bed_type") is "SofaBed" or
     * "DoubleSofaBed" (case/spacing-insensitive), with a defensive fallback
     * to any label containing "sofa" or "canap" (French) for other variants.
     * Handles several payload shapes actually seen from Lodgify, since the
     * v1/v2 endpoints aren't fully consistent:
     *  - a plain string entry (e.g. "beds": ["Sofa Bed", "Double"]),
     *  - an object whose "type"/"bedType" is itself a nested object
     *    (e.g. {"id": 5, "name": "Sofa Bed"}) instead of a flat string,
     *  - a boolean flag on the bed object itself (e.g. "is_sofa_bed": true /
     *    "isSofaBed": true) instead of (or in addition to) a type label.
     */
    private function countSofaBedsInBedsArray(mixed $beds): int
    {
        if (!is_array($beds)) {
            return 0;
        }
        $count = 0;
        foreach ($beds as $bed) {
            if (is_string($bed)) {
                if ($this->isSofaBedLabel($bed)) {
                    $count += 1;
                }
                continue;
            }
            if (!is_array($bed)) {
                continue;
            }
            $typeValue = $bed['type'] ?? $bed['bed_type'] ?? $bed['bedType'] ?? $bed['name'] ?? '';
            $label = is_array($typeValue)
                ? (string) ($typeValue['name'] ?? $typeValue['label'] ?? $typeValue['text'] ?? '')
                : (string) $typeValue;
            $sofaFlag = filter_var(
                $bed['is_sofa_bed'] ?? $bed['isSofaBed'] ?? $bed['sofa_bed'] ?? $bed['sofaBed'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            if ($sofaFlag || $this->isSofaBedLabel($label)) {
                $count += (int) ($bed['count'] ?? $bed['quantity'] ?? $bed['amount'] ?? 1);
            }
        }
        return $count;
    }

    /**
     * True when a bed-type label (case/spacing-insensitive) denotes a sofa
     * bed: "SofaBed"/"DoubleSofaBed" exactly, or any label containing "sofa"
     * or "canap" (French "canapé-lit") as a defensive fallback for other
     * variants Lodgify might use.
     */
    private function isSofaBedLabel(string $label): bool
    {
        $normalized = mb_strtolower(str_replace([' ', '-', '_'], '', $label));
        return $normalized === 'sofabed'
            || $normalized === 'doublesofabed'
            || str_contains($normalized, 'sofa')
            || str_contains($normalized, 'canap');
    }

    /**
     * Sofa-bed composition was expected to be exposed by Lodgify's legacy v1
     * API ("/v1/properties/{id}" and "/v1/rooms"), not by the v2
     * "/properties/{id}/rooms" endpoint used everywhere else in this class
     * (its "beds"/"rooms" nesting used by countSofaBeds() is empty in
     * practice for every property, so the count silently stayed 0 even
     * after a full resync). In practice, for this Lodgify account neither
     * v1 call reliably returns it either: "/v1/properties/{id}" comes back
     * without any "beds" field at all (its "rooms" only carry
     * bedrooms/bathrooms/max_people), and "/v1/rooms?propertyId=" 404s
     * outright. A v1 call that merely *succeeds* with zero sofa beds found
     * must therefore NOT be treated as authoritative — only a v1 count
     * greater than zero overrides the v2-derived $fallback (which itself
     * already falls back to amenity-based detection via countSofaBeds()).
     * Otherwise a v1 hiccup, or a v1 payload that simply lacks bed data,
     * would silently clobber an already-working v2/amenity-derived count
     * down to a false "Non".
     */
    private function fetchSofaBedCountFromV1(int $propertyId, int $fallback): int
    {
        $result = $this->remember('lodgify:v1:sofabeds:' . $propertyId, self::FICHE_TTL, function () use ($propertyId, $fallback): array {
            $count = 0;

            try {
                $property = $this->requestV1('/properties/' . $propertyId);
                if (is_array($property)) {
                    $count += $this->countSofaBeds($property);
                }
            } catch (\Throwable $e) {
                error_log('Lodgify: v1 property request failed for sofa bed count, property ' . $propertyId . ': ' . $e->getMessage());
            }

            try {
                $rooms = $this->requestV1('/rooms', ['propertyId' => $propertyId]);
                $items = is_array($rooms['items'] ?? null) ? $rooms['items'] : (is_array($rooms) ? $rooms : []);
                foreach ($items as $room) {
                    if (is_array($room)) {
                        $count += $this->countSofaBeds($room);
                    }
                }
            } catch (\Throwable $e) {
                error_log('Lodgify: v1 rooms request failed for sofa bed count, property ' . $propertyId . ': ' . $e->getMessage());
            }

            return ['count' => $count > 0 ? $count : $fallback];
        });
        return (int) ($result['count'] ?? $fallback);
    }

    /**
     * Uncached diagnostic helper: returns the raw v1 "/properties/{id}" and
     * "/rooms?propertyId=" payloads Lodgify actually returns for a property,
     * alongside the sofa-bed count countSofaBeds() derives from each, so an
     * admin can see exactly why detection says "Non" when Lodgify's own
     * back-office shows a sofa bed configured (e.g. a bed-composition shape
     * countSofaBeds()/countSofaBedsInBedsArray() don't yet recognize).
     */
    public function getSofaBedDebug(int $propertyId): array
    {
        $result = [
            'property_id' => $propertyId,
            'v1_property' => null,
            'v1_property_error' => null,
            'v1_property_sofa_count' => 0,
            'v1_rooms' => null,
            'v1_rooms_error' => null,
            'v1_rooms_sofa_count' => 0,
        ];
        try {
            $property = $this->requestV1('/properties/' . $propertyId);
            $result['v1_property'] = $property;
            if (is_array($property)) {
                $result['v1_property_sofa_count'] = $this->countSofaBeds($property);
            }
        } catch (\Throwable $e) {
            $result['v1_property_error'] = $e->getMessage();
        }
        try {
            $rooms = $this->requestV1('/rooms', ['propertyId' => $propertyId]);
            $result['v1_rooms'] = $rooms;
            $items = is_array($rooms['items'] ?? null) ? $rooms['items'] : (is_array($rooms) ? $rooms : []);
            $count = 0;
            foreach ($items as $room) {
                if (is_array($room)) {
                    $count += $this->countSofaBeds($room);
                }
            }
            $result['v1_rooms_sofa_count'] = $count;
        } catch (\Throwable $e) {
            $result['v1_rooms_error'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * Fetches "/properties/{id}/rooms" for the given property and overwrites
     * bedrooms/bathrooms/max_guests/sofa_bed_count on the mapped property
     * with the sum of each room's real capacity (see sumRoomCapacity()).
     * Used by getProperties() where room details haven't already been
     * fetched.
     */
    private function applyRoomCapacity(array $property, int $propertyId): array
    {
        try {
            $rooms = $this->getPropertyRoomsDetails($propertyId);
        } catch (\Throwable $e) {
            error_log('Lodgify: failed to compute room capacity for property ' . $propertyId . ': ' . $e->getMessage());
            return $property;
        }
        return $this->sumRoomCapacity($property, $rooms, $propertyId);
    }

    /**
     * Aggregates bedrooms/bathrooms/max_guests/sofa_bed_count from a
     * property's room details (RoomDetailsDto[], from
     * "/properties/{id}/rooms"). This is the only Lodgify v2 endpoint that
     * actually exposes these numbers: the "rooms" array embedded in
     * "/properties" and "/properties/{id}" (RoomSummaryDto) only ever
     * contains "id" and "name".
     */
    private function sumRoomCapacity(array $property, array $rooms, ?int $propertyId = null): array
    {
        $bedrooms = 0;
        $bathrooms = 0;
        $maxGuests = 0;
        $sofaBeds = 0;
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $bedrooms += (int) ($room['bedrooms'] ?? 0);
            $bathrooms += (int) ($room['bathrooms'] ?? 0);
            $maxGuests += (int) ($room['max_people'] ?? 0);
            $sofaBeds += (int) ($room['sofa_bed_count'] ?? 0);
        }
        // The v2 "/properties/{id}/rooms" (RoomDetailsDto) payload doesn't
        // reliably expose bed composition (it's always been empty/absent in
        // practice, so $sofaBeds computed above from it stays 0), even though
        // Lodgify's own back-office shows sofa beds correctly. That data is
        // only available from the legacy v1 API ("/v1/properties/{id}" or
        // "/v1/rooms"), so fetch and prefer that count when a propertyId is
        // available.
        if ($propertyId !== null) {
            $sofaBeds = $this->fetchSofaBedCountFromV1($propertyId, $sofaBeds);
        }
        $property['sofa_bed_count'] = $sofaBeds;
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
            // No calendar entries at all for this property (no room type
            // returned any data, e.g. it has never had a single booking and
            // Lodgify omitted even the usual degenerate sentinel period).
            // Returning an empty result here used to leave every day of
            // $merged at its "false" default with nothing to OR it against,
            // so the whole property rendered as "unknown"/unbookable —
            // functionally identical to fully blocked in the booking widget
            // — which is why most properties (having no calendar data
            // returned at all) looked entirely unavailable while only the
            // rare property with real periods displayed correctly. Fall back
            // to the same "default to available" policy used per room type
            // below instead of leaving it blocked.
            foreach ($merged as $day => $isAvailable) {
                $merged[$day] = true;
            }
            $days = [];
            foreach ($merged as $day => $isAvailable) {
                $days[] = ['date' => $day, 'available' => $isAvailable, 'single_night' => false];
            }
            ksort($days);
            return array_values($days);
        }

        // Days occupied by an isolated 1-night reservation. Such a booking has
        // no interior night (both its arrival and departure day are turnover
        // days), so under the "free turnover day" rule it would leave no
        // occupied day at all and the night would silently look bookable. We
        // therefore keep the single booked night visible ("jaune pâle" in the
        // calendar) and unbookable, while the surrounding days stay free.
        $singleAny = array_fill_keys(array_keys($merged), false);
        $multiAny = array_fill_keys(array_keys($merged), false);

        foreach ($roomTypeCalendars as $roomTypeCalendar) {
            // Per room type, days default to available=true and only flip to
            // false where a period explicitly reports zero available units.
            $roomDays = array_fill_keys(array_keys($merged), true);
            $roomSingle = array_fill_keys(array_keys($merged), false);
            $roomMulti = array_fill_keys(array_keys($merged), false);
            $periods = is_array($roomTypeCalendar['periods'] ?? null) ? $roomTypeCalendar['periods'] : [];
            foreach ($periods as $period) {
                if (!is_array($period)) {
                    continue;
                }
                $available = (int) ($period['available'] ?? 0);
                try {
                    $periodStart = new \DateTimeImmutable((string) ($period['start'] ?? ''));
                    $periodEnd = new \DateTimeImmutable((string) ($period['end'] ?? ''));
                } catch (\Throwable) {
                    continue;
                }
                if ($available > 0) {
                    // Still bookable units left for this period: not a
                    // reservation/closed-period, so it blocks nothing.
                    continue;
                }
                if ((int) $periodStart->format('Y') <= 1 || $periodEnd < $periodStart) {
                    // Lodgify's sentinel "nothing booked" period
                    // ({"start":"0001-01-01","end":"0001-01-01"}) or a malformed
                    // inverted period. It carries no real occupied nights, so it
                    // must be ignored: days default to available and only ever
                    // flip to unavailable via a real overlapping period below.
                    continue;
                }
                // Lodgify availability periods report their occupied nights as an
                // INCLUSIVE [start, end] range: "start" is the reservation's
                // arrival date and "end" is the last occupied night, i.e. the
                // departure date minus one day (NOT the exclusive checkout). The
                // exclusive checkout boundary is therefore end + 1 day. Both
                // turnover days stay free/bookable — the arrival day (a new guest
                // can check in the same day the previous one leaves) and the
                // checkout day — so only the interior nights are blocked. E.g.
                // arrival 25/07, departure 27/07 arrives as start=25/07 end=26/07
                // and blocks only the night of 26/07 (25/07 and 27/07 free);
                // arrival 14/07, departure 23/07 arrives as start=14/07 end=22/07
                // and blocks the nights 15–22.
                $checkoutExclusive = $periodEnd->modify('+1 day');
                $occupiedStart = $periodStart->modify('+1 day');
                if ($occupiedStart > $periodEnd) {
                    // 1-night reservation (start === end): no interior night
                    // remains once both turnover days are excluded. Rather than
                    // leaving the whole thing invisible/bookable, keep the single
                    // booked night (the arrival day) occupied and flag it as a
                    // "single night" so the calendar can paint it pale yellow. On
                    // that day a guest may only arrive or depart (never sleep the
                    // booked night), so it must not be bookable.
                    $day = $periodStart->format('Y-m-d');
                    if (array_key_exists($day, $roomDays)) {
                        $roomDays[$day] = false;
                        $roomSingle[$day] = true;
                    }
                    continue;
                }
                if ($checkoutExclusive <= $rangeStart || $occupiedStart >= $rangeEnd) {
                    // Entirely out-of-range real period (a past booking that
                    // already checked out, or a future one beyond the visible
                    // calendar window): it has real dates that simply don't
                    // overlap what was requested, so it must be ignored rather
                    // than blocking the whole range.
                    continue;
                }
                $cursor = max($occupiedStart, $rangeStart);
                $limit = min($checkoutExclusive, $rangeEnd);
                while ($cursor < $limit) {
                    $roomDays[$cursor->format('Y-m-d')] = false;
                    $roomMulti[$cursor->format('Y-m-d')] = true;
                    $cursor = $cursor->modify('+1 day');
                }
            }
            // A property is bookable for a given night if at least one of its
            // room types is available that night (OR merge across room types).
            foreach ($roomDays as $day => $isAvailable) {
                $merged[$day] = $merged[$day] || $isAvailable;
            }
            foreach ($roomSingle as $day => $isSingle) {
                if ($isSingle) {
                    $singleAny[$day] = true;
                }
            }
            foreach ($roomMulti as $day => $isMulti) {
                if ($isMulti) {
                    $multiAny[$day] = true;
                }
            }
        }

        $days = [];
        foreach ($merged as $day => $isAvailable) {
            // A day is "single night" (pale yellow) only when the property is
            // fully booked that night solely because of 1-night reservations
            // (no room free, and no multi-night interior block on that day).
            $single = !$isAvailable && ($singleAny[$day] ?? false) && !($multiAny[$day] ?? false);
            $days[] = ['date' => $day, 'available' => $isAvailable, 'single_night' => $single];
        }
        ksort($days);
        return array_values($days);
    }

    /**
     * Returns the raw "active" reservations (real bookings / closed periods)
     * that overlap the requested [$from, $to) window, so they can be listed in
     * a table under the calendar for verification. Unlike getAvailability(),
     * which collapses everything down to a per-day available/blocked flag, this
     * keeps each booking as its own row with its real arrival ("start") and
     * departure ("end") dates plus the room type it belongs to.
     *
     * Only real, overlapping, occupied periods are returned: Lodgify's
     * degenerate sentinel period ("0001-01-01", start === end) that stands for
     * "nothing booked" is skipped, as is any real period whose dates fall
     * entirely outside the window, and any period still reporting available
     * units (> 0), which isn't a full reservation.
     *
     * @return list<array{start:string,end:string,nights:int,room_type_id:int,room_type_name:string,single_night:bool}>
     */
    public function getReservations(int $propertyId, string $from, string $to): array
    {
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

        $reservations = [];
        $roomTypeCalendars = array_values(array_filter($data, static fn($item): bool => is_array($item)));
        foreach ($roomTypeCalendars as $roomTypeCalendar) {
            $roomTypeId = (int) ($roomTypeCalendar['room_type_id'] ?? $roomTypeCalendar['id'] ?? 0);
            $roomTypeName = (string) ($roomTypeCalendar['name'] ?? '');
            $periods = is_array($roomTypeCalendar['periods'] ?? null) ? $roomTypeCalendar['periods'] : [];
            foreach ($periods as $period) {
                if (!is_array($period)) {
                    continue;
                }
                $available = (int) ($period['available'] ?? 0);
                if ($available > 0) {
                    // Still bookable units left: not a full reservation.
                    continue;
                }
                try {
                    $periodStart = new \DateTimeImmutable((string) ($period['start'] ?? ''));
                    $periodEnd = new \DateTimeImmutable((string) ($period['end'] ?? ''));
                } catch (\Throwable) {
                    continue;
                }
                if ((int) $periodStart->format('Y') <= 1 || $periodEnd < $periodStart) {
                    // Sentinel "nothing booked" period (0001-01-01) or malformed.
                    continue;
                }
                // Lodgify reports occupied nights as an inclusive [start, end]
                // range where "end" is the last occupied night (departure minus
                // one). Recover the real (exclusive) checkout date as end + 1 so
                // the table shows the actual departure and night count.
                $checkoutExclusive = $periodEnd->modify('+1 day');
                if ($checkoutExclusive <= $rangeStart || $periodStart >= $rangeEnd) {
                    // Real booking entirely outside the requested window.
                    continue;
                }
                $nights = (int) $periodStart->diff($checkoutExclusive)->days;
                $reservations[] = [
                    'start' => $periodStart->format('Y-m-d'),
                    'end' => $checkoutExclusive->format('Y-m-d'),
                    'nights' => $nights,
                    'room_type_id' => $roomTypeId,
                    'room_type_name' => $roomTypeName,
                    'single_night' => $nights === 1,
                ];
            }
        }

        usort($reservations, static function (array $a, array $b): int {
            return [$a['start'], $a['end']] <=> [$b['start'], $b['end']];
        });

        return $reservations;
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
     * Fetches a lightweight nightly-rate sample for the given property (the
     * next 7 nights, 2 guests) and caches it for 30 minutes under
     * "lodgify:v2:pricestatus:{id}". Availability/rates are otherwise always
     * queried live at search time (never cached) since they depend on the
     * visitor's chosen dates/guests, but the admin "Biens Lodgify" page needs
     * a single "Statut Prix" freshness indicator per property, refreshed
     * every 30 minutes regardless of visitor searches — this cached snapshot
     * (and its cache_key's created_at, read via getCacheStatus()) is what
     * powers that column.
     */
    public function getPriceStatusSnapshot(int $propertyId): array
    {
        return $this->remember('lodgify:v2:pricestatus:' . $propertyId, 1800, function () use ($propertyId): array {
            $from = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $to = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
            $samplePrice = null;
            $currency = null;
            try {
                $rates = $this->getRates($propertyId, $from, $to, 2);
                foreach ($rates as $rate) {
                    if (($rate['price_per_night'] ?? 0.0) > 0.0) {
                        $samplePrice = (float) $rate['price_per_night'];
                        $currency = (string) ($rate['currency'] ?? '');
                        break;
                    }
                }
            } catch (\Throwable) {
                // Swallowed: a Lodgify hiccup here should only leave the price
                // status stale, never break the whole admin page.
            }
            return ['sample_price' => $samplePrice, 'currency' => $currency];
        });
    }

    /**
     * Reports, for a single property, when its "fiche" (photos/description/
     * capacity — from getProperties()/getProperty()) and its price snapshot
     * (getPriceStatusSnapshot()) were each last refreshed, straight from the
     * lodgify_cache table's created_at/expires_at, plus whether each is still
     * within its expected refresh cadence: the fiche is manual-only (never
     * auto-expires; "fresh" just means it has been synced at least once),
     * price auto-refreshes every 30 min. Used by the "Biens Lodgify" admin
     * page.
     *
     * @return array{
     *   fiche_updated_at: ?\DateTimeImmutable, fiche_fresh: bool,
     *   price_updated_at: ?\DateTimeImmutable, price_fresh: bool
     * }
     */
    public function getCacheStatus(int $propertyId): array
    {
        $ficheRow = $this->cacheMeta(['lodgify:v2:property:' . $propertyId, 'lodgify:v2:properties']);
        $priceRow = $this->cacheMeta(['lodgify:v2:pricestatus:' . $propertyId]);
        return [
            'fiche_updated_at' => $ficheRow['created_at'],
            'fiche_fresh' => $ficheRow['expires_at'] !== null && $ficheRow['expires_at'] > new \DateTimeImmutable(),
            'price_updated_at' => $priceRow['created_at'],
            'price_fresh' => $priceRow['expires_at'] !== null && $priceRow['expires_at'] > new \DateTimeImmutable(),
        ];
    }

    /**
     * Returns the most recent created_at/expires_at among the given cache
     * keys (nulls if none of them exist yet).
     *
     * @param array<int, string> $keys
     * @return array{created_at: ?\DateTimeImmutable, expires_at: ?\DateTimeImmutable}
     */
    private function cacheMeta(array $keys): array
    {
        if ($keys === []) {
            return ['created_at' => null, 'expires_at' => null];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT created_at, expires_at FROM lodgify_cache WHERE cache_key IN (' . $placeholders . ') ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute($keys);
        $row = $stmt->fetch();
        if (!$row) {
            return ['created_at' => null, 'expires_at' => null];
        }
        return [
            'created_at' => new \DateTimeImmutable((string) $row['created_at']),
            'expires_at' => new \DateTimeImmutable((string) $row['expires_at']),
        ];
    }

    /**
     * Resolves the primary room type id for a property, required by the Lodgify
     * rates/calendar endpoint. Most rentals on Lodgify have a single room type.
     */
    private function getPrimaryRoomTypeId(int $propertyId): ?int
    {
        $cached = $this->remember('lodgify:v2:roomtype:' . $propertyId, self::FICHE_TTL, function () use ($propertyId): array {
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
        return $this->httpRequest($this->baseUrl, $path, $params);
    }

    /**
     * Same as request() but against Lodgify's legacy v1 API base URL,
     * needed for endpoints (like sofa bed / bed composition data) that v2
     * doesn't reliably expose. Derived from LODGIFY_BASE_URL by swapping a
     * trailing "/v2" for "/v1", or defaults to the standard v1 host.
     */
    private function requestV1(string $path, array $params = []): array
    {
        return $this->httpRequest($this->v1BaseUrl(), $path, $params);
    }

    private function v1BaseUrl(): string
    {
        $swapped = preg_replace('#/v2$#', '/v1', $this->baseUrl);
        return $swapped !== null && $swapped !== $this->baseUrl ? $swapped : 'https://api.lodgify.com/v1';
    }

    private function httpRequest(string $baseUrl, string $path, array $params = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('LODGIFY_API_KEY is not set');
        }

        $url = $baseUrl . $path;
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
        [$latitude, $longitude] = $this->extractCoordinates($item);
        $city = trim((string) ($item['city'] ?? ''));
        $propertyId = (int) ($item['id'] ?? 0);
        // Most listings on this account simply never had precise GPS filled
        // in on Lodgify's side, so extractCoordinates() returns null for
        // them far more often than not. The "/properties" overview map
        // (map-board.php) filtered those out entirely, so it only ever
        // showed the one or two properties that happen to have real
        // coordinates — looking like an empty map with just a stray pin or
        // two. Give every property an approximate "map_*" position (real
        // coordinates when known, else a per-city estimate) so the overview
        // map always shows every property; admin diagnostics and the
        // property-detail page keep using the exact/possibly-null
        // "latitude"/"longitude" fields untouched, since those claim to be
        // precise and link out to OpenStreetMap.
        $hasExactCoordinates = $latitude !== null && $longitude !== null;
        [$mapLatitude, $mapLongitude] = $hasExactCoordinates
            ? [$latitude, $longitude]
            : $this->estimateCoordinatesFromCity($city, $propertyId);
        return [
            'id' => $propertyId,
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'images' => $images,
            'amenities' => $amenities,
            'city' => $city,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'map_latitude' => $mapLatitude,
            'map_longitude' => $mapLongitude,
            'map_position_is_estimated' => !$hasExactCoordinates,
            'max_guests' => (int) ($item['people_capacity'] ?? $item['max_guests'] ?? $item['maxGuests'] ?? 0),
            'bedrooms' => (int) ($item['rooms_count'] ?? $item['bedrooms'] ?? 0),
            'bathrooms' => (int) ($item['bathrooms_count'] ?? $item['bathrooms'] ?? 0),
        ];
    }

    /**
     * Northern Mauritius coastal towns/villages (this account's market,
     * "Grand Baie") mapped to their approximate centre coordinates, used to
     * place a property on the "/properties" overview map when Lodgify
     * doesn't provide exact GPS for it. Matched case/accent-insensitively
     * against the property's "city" field; falls back to Grand Baie itself
     * — the area most of this account's properties are actually in — when
     * the city is unset or unrecognized.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const CITY_COORDINATES = [
        'grandbaie' => [-20.0186, 57.5807],
        'pereybere' => [-19.9926, 57.5822],
        'perebere' => [-19.9926, 57.5822],
        'troiletauxbiches' => [-20.0339, 57.5453],
        'trouauxbiches' => [-20.0339, 57.5453],
        'montchoisy' => [-20.0286, 57.5553],
        'pointeauxcanonniers' => [-20.0067, 57.5647],
        'capmalheureux' => [-19.9822, 57.6136],
        'grandgaube' => [-20.0064, 57.6539],
        'pointeauxpiments' => [-20.0511, 57.5219],
        'triolet' => [-20.0333, 57.5500],
        'bainboeuf' => [-19.9958, 57.5928],
        'pamplemousses' => [-20.1075, 57.5697],
    ];

    /**
     * Normalizes a city name for matching against CITY_COORDINATES: lower-
     * cases it and strips accents/spaces/hyphens/apostrophes, so "Pereybère",
     * "Péreybere" and "pereybere" all match the same key.
     */
    private function normalizeCityName(string $city): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $city);
        $normalized = $transliterated !== false ? $transliterated : $city;
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $normalized) ?? '');
    }

    /**
     * Estimates a "good enough for an overview map" position for a property
     * without exact GPS: looks up its city in CITY_COORDINATES (defaulting
     * to Grand Baie when unset/unmatched), then applies a small deterministic
     * per-property offset (derived from its id) so several properties in the
     * same city don't render as a single overlapping dot.
     *
     * @return array{0: float, 1: float}
     */
    private function estimateCoordinatesFromCity(string $city, int $propertyId): array
    {
        [$baseLat, $baseLng] = self::CITY_COORDINATES[$this->normalizeCityName($city)] ?? self::CITY_COORDINATES['grandbaie'];
        $angle = ($propertyId % 12) * (M_PI / 6);
        $radius = 0.006;
        return [$baseLat + sin($angle) * $radius, $baseLng + cos($angle) * $radius];
    }

    /**
     * Reads a property's GPS coordinates from Lodgify's PropertyDto. Lodgify
     * actually nests these under "address" (address.lat / address.lng, e.g.
     * {"address":{"lat":52.52,"lng":13.40,...}}), not as root-level
     * "latitude"/"longitude" fields — using only the root fields left every
     * property without coordinates, so the "/properties" map never showed any
     * marker. Root-level "latitude"/"longitude"/"lat"/"lng" and a "location"
     * sub-object are also checked as fallbacks in case Lodgify changes shape
     * or an older/alternate response format is used.
     *
     * @param array<string, mixed> $item
     * @return array{0: ?float, 1: ?float}
     */
    private function extractCoordinates(array $item): array
    {
        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $location = is_array($item['location'] ?? null) ? $item['location'] : [];
        $candidates = [
            [$address['lat'] ?? null, $address['lng'] ?? null],
            [$address['latitude'] ?? null, $address['longitude'] ?? null],
            [$location['lat'] ?? null, $location['lng'] ?? null],
            [$location['latitude'] ?? null, $location['longitude'] ?? null],
            [$item['latitude'] ?? null, $item['longitude'] ?? null],
            [$item['lat'] ?? null, $item['lng'] ?? null],
        ];
        foreach ($candidates as [$lat, $lng]) {
            if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
                return [(float) $lat, (float) $lng];
            }
        }
        return [null, null];
    }
}
