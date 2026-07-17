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
     * Counts sofa beds ("Canapé-lit") declared on a RoomDetailsDto item.
     * Per Lodgify's actual response shape, each room-type item nests its
     * physical sub-rooms under "rooms" (or "sub_rooms" in some responses),
     * and each sub-room carries a "beds" array of {"type": "SofaBed"|
     * "DoubleSofaBed", "count": n} (Lodgify also uses "quantity" for the
     * count in some payloads). Bed "type" values are matched case-
     * insensitively against "sofabed"/"doublesofabed", with a defensive
     * fallback to any label containing "sofa" or "canap" (French) in case
     * of other bed-type variants.
     */
    private function countSofaBeds(array $item): int
    {
        $count = $this->countSofaBedsInBedsArray($item['beds'] ?? null);

        $subRooms = $item['rooms'] ?? $item['sub_rooms'] ?? $item['subRooms']
            ?? $item['type_rooms'] ?? $item['typeRooms'] ?? $item['roomTypes'] ?? $item['room_types'] ?? [];
        if (is_array($subRooms)) {
            foreach ($subRooms as $subRoom) {
                if (!is_array($subRoom)) {
                    continue;
                }
                $beds = $subRoom['beds'] ?? $subRoom['bed_types'] ?? $subRoom['bedTypes'] ?? null;
                $count += $this->countSofaBedsInBedsArray($beds);
            }
        }
        return $count;
    }

    /**
     * Sums the sofa-bed "count"/"quantity" across a single bed[] array,
     * matching entries whose "type" (or "name"/"bed_type") is "SofaBed" or
     * "DoubleSofaBed" (case/spacing-insensitive), with a defensive fallback
     * to any label containing "sofa" or "canap" (French) for other variants.
     */
    private function countSofaBedsInBedsArray(mixed $beds): int
    {
        if (!is_array($beds)) {
            return 0;
        }
        $count = 0;
        foreach ($beds as $bed) {
            if (!is_array($bed)) {
                continue;
            }
            $label = (string) ($bed['type'] ?? $bed['name'] ?? $bed['bed_type'] ?? '');
            $normalized = mb_strtolower(str_replace([' ', '-', '_'], '', $label));
            if (
                $normalized === 'sofabed'
                || $normalized === 'doublesofabed'
                || str_contains($normalized, 'sofa')
                || str_contains($normalized, 'canap')
            ) {
                $count += (int) ($bed['count'] ?? $bed['quantity'] ?? $bed['amount'] ?? 1);
            }
        }
        return $count;
    }

    /**
     * Sofa-bed composition is only reliably exposed by Lodgify's legacy v1
     * API ("/v1/properties/{id}" and "/v1/rooms"), not by the v2
     * "/properties/{id}/rooms" endpoint used everywhere else in this class
     * (its "beds"/"rooms" nesting used by countSofaBeds() is empty in
     * practice for every property, so the count silently stayed 0 even
     * after a full resync). Falls back to the v2-derived $fallback count if
     * the v1 call fails or returns nothing, so a v1 hiccup never regresses
     * an already-working value.
     */
    private function fetchSofaBedCountFromV1(int $propertyId, int $fallback): int
    {
        $result = $this->remember('lodgify:v1:sofabeds:' . $propertyId, 86400, function () use ($propertyId, $fallback): array {
            $count = 0;
            $found = false;

            try {
                $property = $this->requestV1('/properties/' . $propertyId);
                if (is_array($property)) {
                    $count += $this->countSofaBeds($property);
                    $found = true;
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
                        $found = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('Lodgify: v1 rooms request failed for sofa bed count, property ' . $propertyId . ': ' . $e->getMessage());
            }

            return ['count' => $found ? $count : $fallback];
        });
        return (int) ($result['count'] ?? $fallback);
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
     * within its expected refresh cadence (24h for the fiche, 30min for
     * price). Used by the "Biens Lodgify" admin page.
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
        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'images' => $images,
            'amenities' => $amenities,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'max_guests' => (int) ($item['people_capacity'] ?? $item['max_guests'] ?? $item['maxGuests'] ?? 0),
            'bedrooms' => (int) ($item['rooms_count'] ?? $item['bedrooms'] ?? 0),
            'bathrooms' => (int) ($item['bathrooms_count'] ?? $item['bathrooms'] ?? 0),
        ];
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
