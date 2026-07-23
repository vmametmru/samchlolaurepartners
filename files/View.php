<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        $viewsPath = BASE_PATH . '/files/views';
        $templatePath = $viewsPath . '/' . $template . '.php';
        $layoutPath = $viewsPath . '/' . $layout . '.php';
        if (!is_file($templatePath)) {
            throw new HttpException(500, 'Internal Server Error', 'View not found: ' . $template);
        }

        // The "/" gate page (pages/enter-code) must always render as if no
        // partner were active — title "Portail Partenaires" and no public
        // pages menu — even if a partner_code cookie is still set from a
        // previous session; a stale cookie must never leak partner branding
        // onto the code-entry gate. Pass 'suppressPartner' => true from the
        // controller to force that.
        $suppressPartner = !empty($data['suppressPartner']);
        unset($data['suppressPartner']);
        $partner = $suppressPartner ? null : Tenant::currentPublic();
        $user = Auth::user();
        $authDebug = $user === null ? Auth::debugStatus() : null;
        $flash = Flash::pull();
        $pageTitle = $data['pageTitle'] ?? 'samchlolaurepartners';
        $lang = I18n::current();
        $currentPath = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();
        require $layoutPath;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @var array<int, array<string, array<string, string>>>|null */
    private static ?array $manualTranslations = null;

    /**
     * Picks the French translation of a Lodgify-sourced field (e.g. "name",
     * "description") when the site is currently displayed in French. Checks,
     * in order: (1) a manual override entered on the admin "Traductions"
     * page (property_translations table) — used whenever Lodgify itself has
     * no French translation configured for that property/field; (2) the
     * "{$field}_fr" translation fetched live from Lodgify (see
     * LodgifyClient::fetchFrenchTranslation()); (3) the property's default
     * field, which is whatever language the Lodgify account itself is
     * configured in (English on this account).
     */
    public static function localized(array $property, string $field): string
    {
        $lang = I18n::current();
        $propertyId = (int) ($property['id'] ?? 0);
        if ($propertyId > 0) {
            $manual = trim((string) (self::manualTranslationsIndex()[$propertyId][$field][$lang] ?? ''));
            if ($manual !== '') {
                return $manual;
            }
        }
        if ($lang === 'fr') {
            $translated = trim((string) ($property[$field . '_fr'] ?? ''));
            if ($translated !== '') {
                return $translated;
            }
        }
        return (string) ($property[$field] ?? '');
    }

    /**
     * Loads every row of property_translations once per request (admin
     * "Traductions" manual overrides), indexed by property id / field /
     * language, so localized() can look them up with a plain array read
     * instead of one SQL query per property per field per page render.
     * Best-effort: if the table doesn't exist yet (migration not applied)
     * or the query fails for any reason, this must never break page
     * rendering — it simply returns no overrides.
     *
     * @return array<int, array<string, array<string, string>>>
     */
    private static function manualTranslationsIndex(): array
    {
        if (self::$manualTranslations !== null) {
            return self::$manualTranslations;
        }
        $index = [];
        try {
            $rows = Database::connection()->query('SELECT property_id, field, language, text_value FROM property_translations')->fetchAll();
            foreach ($rows as $row) {
                $index[(int) $row['property_id']][(string) $row['field']][(string) $row['language']] = (string) $row['text_value'];
            }
        } catch (\Throwable $e) {
            error_log('View: failed to load property_translations: ' . $e->getMessage());
        }
        self::$manualTranslations = $index;
        return self::$manualTranslations;
    }

    /**
     * Same as localized() but for the property's categorized amenities
     * ("Équipements"), which Lodgify never exposes as a plain string (see
     * amenitiesToText()/textToAmenities()) so localized() itself can't be
     * reused directly. Checks, in order: (1) a manual French translation
     * saved on the admin "Traductions" page (property_translations,
     * field="amenities") — Lodgify never provides its own French amenities
     * translation, unlike name/description; (2) the property's own
     * (default-language) categorized amenities.
     *
     * @return array<string, array<int, string>>
     */
    public static function localizedAmenities(array $property): array
    {
        $default = $property['amenities_by_category'] ?? [];
        if (!is_array($default) || $default === []) {
            $default = [];
            foreach (($property['amenities'] ?? []) as $amenity) {
                $name = is_array($amenity) ? (string) ($amenity['name'] ?? '') : (string) $amenity;
                if ($name !== '') {
                    $default['Équipements'][] = $name;
                }
            }
        }
        $lang = I18n::current();
        $propertyId = (int) ($property['id'] ?? 0);
        if ($propertyId > 0) {
            $manual = trim((string) (self::manualTranslationsIndex()[$propertyId]['amenities'][$lang] ?? ''));
            if ($manual !== '') {
                $parsed = self::textToAmenities($manual);
                if ($parsed !== []) {
                    return $parsed;
                }
            }
        }
        return self::humanizeAmenitiesByCategory($default);
    }

    /**
     * Splits a raw PascalCase/camelCase Lodgify code (e.g. "RoomsDiningRoom")
     * into its constituent words (["Rooms", "Dining", "Room"]), so amenity
     * category/item codes can be turned into readable labels without an
     * exhaustive per-code translation table.
     *
     * @return array<int, string>
     */
    private static function splitPascalWords(string $value): array
    {
        $spaced = preg_replace('/(?<!^)(?=[A-Z])/', ' ', trim($value)) ?? $value;
        $words = preg_split('/[\s_-]+/', trim($spaced)) ?: [];
        return array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
    }

    /**
     * Turns a category => [raw Lodgify amenity codes] map (e.g. "room" =>
     * ["RoomsBathroom", "RoomsBedroom", "RoomsDiningRoom", "RoomsLivingRoom"])
     * into a readable category => [readable names] map for the "Équipements"
     * tab, without needing an exhaustive per-code translation table: the
     * category label becomes the leading word(s) every code in that category
     * shares (here "Rooms"), and each item keeps only the remaining words
     * ("Bathroom", "Bedroom", "Dining Room", "Living Room"). Falls back to
     * humanizing the raw category key itself when its items don't share a
     * common prefix (e.g. a mixed-bag category), or when the category only
     * has a single item (too little signal to detect a shared prefix).
     *
     * @param array<string, array<int, string>> $amenitiesByCategory
     * @return array<string, array<int, string>>
     */
    public static function humanizeAmenitiesByCategory(array $amenitiesByCategory): array
    {
        $result = [];
        foreach ($amenitiesByCategory as $category => $names) {
            if (!is_array($names) || $names === []) {
                continue;
            }
            $wordLists = array_values(array_map(
                static fn ($n): array => self::splitPascalWords((string) $n),
                $names
            ));
            $prefixLen = 0;
            if (count($wordLists) > 1) {
                $first = $wordLists[0];
                foreach ($first as $i => $word) {
                    $matches = true;
                    foreach ($wordLists as $words) {
                        if (!isset($words[$i]) || strcasecmp($words[$i], $word) !== 0) {
                            $matches = false;
                            break;
                        }
                    }
                    if (!$matches) {
                        break;
                    }
                    $prefixLen++;
                }
                // Never strip an item down to nothing.
                foreach ($wordLists as $words) {
                    if (count($words) <= $prefixLen) {
                        $prefixLen = 0;
                        break;
                    }
                }
            }
            $label = $prefixLen > 0
                ? implode(' ', array_slice($wordLists[0], 0, $prefixLen))
                : implode(' ', array_map('ucfirst', self::splitPascalWords((string) $category)));
            $label = $label !== '' ? $label : (string) $category;
            $items = [];
            foreach ($wordLists as $index => $words) {
                $remaining = $prefixLen > 0 ? array_slice($words, $prefixLen) : $words;
                $items[] = $remaining !== [] ? implode(' ', $remaining) : (string) $names[$index];
            }
            if (isset($result[$label])) {
                $result[$label] = array_values(array_unique(array_merge($result[$label], $items)));
            } else {
                $result[$label] = $items;
            }
        }
        return $result;
    }

    /**
     * Formats a property's categorized amenities as plain, editable text —
     * one line per category ("Category: item1, item2, ...") — for the admin
     * "Traductions" page's "Anglais (Lodgify)" source textarea and manual
     * French translation textarea. See textToAmenities() for the inverse.
     *
     * @param array<string, array<int, string>> $amenitiesByCategory
     */
    public static function amenitiesToText(array $amenitiesByCategory): string
    {
        $lines = [];
        foreach ($amenitiesByCategory as $category => $names) {
            if (!is_array($names) || $names === []) {
                continue;
            }
            $lines[] = trim((string) $category) . ': ' . implode(', ', array_map('strval', $names));
        }
        return implode("\n", $lines);
    }

    /**
     * Parses text saved on the admin "Traductions" page's manual amenities
     * translation field back into a category => [names] map, the same
     * shape as Lodgify's own "amenities_by_category". See amenitiesToText()
     * for the format this expects (one "Category: item1, item2" per line).
     * Lines with no ":" are ignored (best-effort: a malformed manual
     * translation must never break the amenities tab).
     *
     * @return array<string, array<int, string>>
     */
    public static function textToAmenities(string $text): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$category, $namesPart] = explode(':', $line, 2);
            $category = trim($category);
            $names = array_values(array_filter(array_map('trim', explode(',', $namesPart))));
            if ($category !== '' && $names !== []) {
                $result[$category] = $names;
            }
        }
        return $result;
    }

    /**
     * Returns an inline SVG icon (as a "currentColor"-stroked <svg> string)
     * for an amenities category name, matched case/accent-insensitively by
     * keyword against Lodgify's usual category labels (in French or
     * English) — purely cosmetic, to make the "Équipements" tab scannable
     * at a glance instead of a wall of plain text. Falls back to a generic
     * checkmark-in-circle icon for any category that doesn't match a known
     * keyword, so a new/unexpected Lodgify category never breaks the tab.
     */
    public static function amenityCategoryIcon(string $category): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', self::stripAccents($category)));
        $icons = [
            'cuisine|kitchen|salle a manger|dining' => '<path d="M3 2v7a2 2 0 0 0 2 2h1v11"/><path d="M7 2v20"/><path d="M17 2v7a2 2 0 0 1-2 2"/><path d="M17 2v20"/>',
            'salle de bain|bathroom|toilette' => '<path d="M4 12h16v3a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5v-3Z"/><path d="M6 12V6a2 2 0 0 1 4 0"/><line x1="4" y1="20" x2="4" y2="22"/><line x1="20" y1="20" x2="20" y2="22"/>',
            'chambre|bedroom|linge de lit|room' => '<path d="M2 20v-7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7"/><path d="M2 13V8a2 2 0 0 1 2-2h6v5"/><path d="M22 13V8a2 2 0 0 0-2-2h-6v5"/><line x1="2" y1="20" x2="22" y2="20"/>',
            'exterieur|jardin|outdoor|garden|terrasse|balcon' => '<path d="M12 2v6"/><path d="M12 22v-8"/><path d="M5 12a7 7 0 0 1 14 0c0 3-3 4-3 4H8s-3-1-3-4Z"/>',
            'piscine|pool' => '<path d="M2 17c1.5 1.2 3 1.2 4.5 0s3-1.2 4.5 0 3 1.2 4.5 0 3-1.2 4.5 0"/><path d="M6 13V6a2 2 0 0 1 2-2h2"/><circle cx="16" cy="6" r="2"/>',
            'securite|safety|security' => '<path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/>',
            'internet|bureau|wifi|office' => '<path d="M5 12.6a11 11 0 0 1 14 0"/><path d="M8.5 15.9a6.5 6.5 0 0 1 7 0"/><line x1="12" y1="19" x2="12.01" y2="19"/>',
            'climatisation|chauffage|heating|air conditioning|air condition' => '<path d="M12 2v20"/><path d="M2 12h20"/><path d="m5 5 14 14"/><path d="m19 5-14 14"/>',
            'parking|garage' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 17V7h4a3 3 0 0 1 0 6H9"/>',
            'divertissement|entertainment|television|tv' => '<rect x="2" y="4" width="20" height="14" rx="2"/><line x1="8" y1="22" x2="16" y2="22"/><line x1="12" y1="18" x2="12" y2="22"/>',
            'vue|view' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'accessibilite|accessibility' => '<circle cx="12" cy="4" r="2"/><path d="M19 13v-2a3 3 0 0 0-3-3H9.5L5 12"/><path d="m9 8 1 8"/><path d="M12 22a4 4 0 0 1-3-6.6"/>',
            'famille|enfant|family|child' => '<circle cx="9" cy="7" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 21v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/><path d="M17 12a4 4 0 0 1 4 4v1"/>',
            'services|menage|cleaning|concierge' => '<path d="m3 21 9-9"/><path d="M12.5 5.5 18.5 11.5 21 9 15 3 12.5 5.5Z"/><path d="m9 6-4 4 6 6 4-4"/>',
        ];
        foreach ($icons as $pattern => $svgPath) {
            if (preg_match('/\b(' . $pattern . ')/', $normalized) === 1) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svgPath . '</svg>';
            }
        }
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
    }

    /**
     * Strips accents (é, è, à, ...) so amenityCategoryIcon() can match
     * category keywords regardless of accents (e.g. "Sécurité" and
     * "Securite" both match "securite").
     */
    private static function stripAccents(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        return $transliterated !== false ? $transliterated : $value;
    }

    /**
     * Converts a Lodgify rich-text description (which is raw HTML, e.g.
     * "<p>Welcome...</p><ul><li>...</li></ul>") into plain, safely-escaped
     * text for places like property cards/tables where only a short excerpt
     * is shown. Without this, htmlspecialchars() on the raw HTML just made
     * the tags themselves visible as literal text (e.g. "<p>...</p>").
     */
    public static function plainText(mixed $value, ?int $maxLength = null): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($maxLength !== null) {
            $text = mb_strimwidth($text, 0, $maxLength, '…');
        }
        return self::e($text);
    }

    /**
     * Same as plainText() but returns the raw (un-escaped) decoded text
     * instead of HTML-escaped output — used when the plain text needs to be
     * sent elsewhere (e.g. to a translation API), not printed into HTML.
     */
    public static function plainTextRaw(mixed $value, ?int $maxLength = null): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($maxLength !== null) {
            $text = mb_strimwidth($text, 0, $maxLength, '…');
        }
        return $text;
    }

    /**
     * Converts a Lodgify rich-text field (HTML paragraphs/line breaks/list
     * items) into plain text that keeps its original line breaks, for
     * display in a plain <textarea> (e.g. the admin "Traductions" page).
     * plainTextRaw()/plainText() collapse ALL whitespace — including the
     * newlines that would come from converting block tags — into a single
     * space, which is fine for a one-line card excerpt but turned a
     * multi-paragraph Lodgify description into one big wall of glued-together
     * text with no way to tell where paragraphs/bullets used to be, making it
     * very hard for an admin to type an accurate manual translation. This
     * instead turns </p>, <br>, and </li> into newlines *before* stripping
     * the remaining tags, so paragraphs and list items still read as
     * separate lines.
     */
    public static function plainTextWithLineBreaks(mixed $value): string
    {
        $html = (string) $value;
        $html = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        $html = (string) preg_replace('/<\s*\/\s*(p|li|div|h[1-6])\s*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Collapse spaces/tabs (but not newlines) on each line, then collapse
        // runs of 3+ newlines (e.g. an empty <p></p>) down to a single blank line.
        $text = implode("\n", array_map(
            static fn (string $line): string => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line),
            preg_split('/\r\n|\r|\n/', $text) ?: []
        ));
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Renders a Lodgify rich-text description as safe, limited HTML: strips
     * every tag except a small formatting allow-list and removes all
     * attributes from those (e.g. a stray "onclick"), so the markup Lodgify
     * hosts type in (paragraphs, bullet lists, bold text) still renders as
     * such instead of either showing raw "<p>" tags as text or executing
     * arbitrary attributes.
     */
    public static function safeHtml(mixed $value): string
    {
        $allowedTags = '<p><br><ul><ol><li><strong><b><em><i>';
        $html = strip_tags((string) $value, $allowedTags);
        return (string) preg_replace('/<(\w+)[^>]*>/', '<$1>', $html);
    }

    public static function badgeLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'En attente',
            'confirmed' => 'Confirmée',
            'cancelled' => 'Annulée',
            default => $status,
        };
    }
}
