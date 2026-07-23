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
