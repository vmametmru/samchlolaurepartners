<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal FR/EN site-language handling.
 *
 * On a visitor's first request (no "site_lang" cookie yet) the language is
 * guessed from the browser's "Accept-Language" header — this is a reliable,
 * offline-capable proxy for "is this visitor from a francophone country"
 * (no external GeoIP/IP-lookup service is available in this environment,
 * and browsers virtually always report the OS/browser language, which
 * correlates strongly with the visitor's country). Once a language has been
 * picked (auto-detected or via the navbar flag toggle), it is remembered in
 * a long-lived cookie so it "sticks" across the whole visit/site.
 */
final class I18n
{
    public const SUPPORTED = ['fr', 'en'];
    public const DEFAULT_LANGUAGE = 'fr';
    private const COOKIE_NAME = 'site_lang';
    private const COOKIE_TTL = 60 * 60 * 24 * 365;

    private static ?string $current = null;

    /**
     * Returns the active site language ('fr' or 'en') for the current
     * request, detecting and persisting it via cookie on first visit.
     */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $cookieValue = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if (in_array($cookieValue, self::SUPPORTED, true)) {
            self::$current = $cookieValue;
            return self::$current;
        }

        $detected = self::detectFromAcceptLanguage((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        self::$current = $detected;
        self::persist($detected);
        return self::$current;
    }

    /**
     * Explicitly switches the site language (navbar flag toggle) and
     * remembers the choice for future visits.
     */
    public static function set(string $language): string
    {
        $language = in_array($language, self::SUPPORTED, true) ? $language : self::DEFAULT_LANGUAGE;
        self::$current = $language;
        self::persist($language);
        return $language;
    }

    /**
     * The other supported language, used to render the navbar's flag
     * toggle (e.g. shows the English flag while the site is in French).
     */
    public static function other(): string
    {
        return self::current() === 'fr' ? 'en' : 'fr';
    }

    /**
     * Translates a short UI string key for the active site language, or the
     * $default text itself when the key has not been translated yet (so
     * templates can be migrated to t() incrementally without ever showing a
     * blank/placeholder string).
     */
    public static function t(string $key, ?string $default = null): string
    {
        $lang = self::current();
        $dictionary = self::dictionary();
        if (isset($dictionary[$key][$lang])) {
            return $dictionary[$key][$lang];
        }
        if (isset($dictionary[$key][self::DEFAULT_LANGUAGE])) {
            return $dictionary[$key][self::DEFAULT_LANGUAGE];
        }
        return $default ?? $key;
    }

    private static function detectFromAcceptLanguage(string $header): string
    {
        if (trim($header) === '') {
            return self::DEFAULT_LANGUAGE;
        }
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0] ?? ''));
            if ($tag === '') {
                continue;
            }
            $primary = explode('-', $tag)[0];
            if ($primary === 'fr') {
                return 'fr';
            }
            if ($primary !== '') {
                // First explicit non-French language preference: treat as English.
                return in_array($primary, self::SUPPORTED, true) ? $primary : 'en';
            }
        }
        return self::DEFAULT_LANGUAGE;
    }

    private static function persist(string $language): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE_NAME, $language, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return array<string, array{fr: string, en: string}>
     */
    private static function dictionary(): array
    {
        return [
            'nav.dashboard' => ['fr' => 'Dashboard', 'en' => 'Dashboard'],
            'nav.public_pages' => ['fr' => 'Pages Publiques', 'en' => 'Public Pages'],
            'nav.properties' => ['fr' => 'Hébergements', 'en' => 'Properties'],
            'nav.calendar' => ['fr' => 'Calendrier', 'en' => 'Calendar'],
            'nav.contact' => ['fr' => 'Contact', 'en' => 'Contact'],
            'nav.settings' => ['fr' => 'Paramêtres', 'en' => 'Settings'],
            'nav.account' => ['fr' => 'Mon compte', 'en' => 'My account'],
            'nav.view_profile' => ['fr' => 'Voir profil', 'en' => 'View profile'],
            'nav.logout' => ['fr' => 'Se déconnecter', 'en' => 'Log out'],
            'nav.login' => ['fr' => 'Connexion', 'en' => 'Log in'],
            'nav.switch_to_en' => ['fr' => 'Switch to English', 'en' => 'Passer en Français'],
            'nav.session_invalid' => ['fr' => 'Session invalide ou expirée — reconnectez-vous.', 'en' => 'Invalid or expired session — please log in again.'],

            'home.hero_default_title' => ['fr' => 'Trouvez votre hébergement idéal', 'en' => 'Find your ideal accommodation'],
            'home.hero_partner_title' => ['fr' => 'Bienvenue chez ', 'en' => 'Welcome to '],
            'home.hero_subtitle' => ['fr' => "Séjours exceptionnels à l'île Maurice", 'en' => 'Exceptional stays in Mauritius'],
            'home.search_button' => ['fr' => 'Rechercher', 'en' => 'Search'],
            'home.checkin' => ['fr' => "Date d'arrivée", 'en' => 'Check-in date'],
            'home.checkout' => ['fr' => 'Date de départ', 'en' => 'Check-out date'],
            'home.adults' => ['fr' => 'Adultes', 'en' => 'Adults'],
            'home.children_under3' => ['fr' => 'Enfants (<3ans)', 'en' => 'Children (<3yo)'],
            'home.children_3to12' => ['fr' => 'Enfants (3-11ans)', 'en' => 'Children (3-11yo)'],
            'home.use_search_above' => ['fr' => 'Utilisez la recherche ci-dessus pour trouver des hébergements disponibles.', 'en' => 'Use the search above to find available accommodations.'],
            'home.no_results' => ['fr' => 'Aucun hébergement disponible pour ces dates.', 'en' => 'No accommodation available for these dates.'],
            'home.search_multi' => ['fr' => 'Rechercher avec plusieurs biens', 'en' => 'Search with multiple properties'],
            'home.available' => ['fr' => 'disponible', 'en' => 'available'],
            'home.property' => ['fr' => 'hébergement', 'en' => 'property'],
            'home.properties' => ['fr' => 'hébergements', 'en' => 'properties'],

            'properties.title' => ['fr' => 'Nos Hébergements', 'en' => 'Our Properties'],
            'properties.empty' => ['fr' => 'Aucun hébergement disponible pour le moment.', 'en' => 'No accommodation available at the moment.'],
            'properties.all_title' => ['fr' => 'Tous les hébergements', 'en' => 'All properties'],
            'properties.search_placeholder' => ['fr' => 'Rechercher...', 'en' => 'Search...'],
            'properties.no_result' => ['fr' => 'Aucun résultat.', 'en' => 'No results.'],

            'contact.title' => ['fr' => 'Contactez-nous', 'en' => 'Contact us'],
            'contact.page_title' => ['fr' => 'Nous contacter', 'en' => 'Contact us'],
            'contact.subtitle' => ['fr' => 'Une question ? Un projet de séjour ? Écrivez-nous, nous vous répondrons dans les plus brefs délais.', 'en' => 'A question? A stay in mind? Write to us, we will get back to you as soon as possible.'],
            'contact.success_message' => ['fr' => 'Message envoyé ! Nous vous contacterons très prochainement.', 'en' => 'Message sent! We will contact you very soon.'],
            'contact.name' => ['fr' => 'Nom *', 'en' => 'Name *'],
            'contact.email' => ['fr' => 'Email *', 'en' => 'Email *'],
            'contact.phone' => ['fr' => 'Téléphone', 'en' => 'Phone'],
            'contact.checkin' => ['fr' => 'Arrivée souhaitée', 'en' => 'Desired check-in'],
            'contact.checkout' => ['fr' => 'Départ souhaité', 'en' => 'Desired check-out'],
            'contact.adults' => ['fr' => 'Adultes', 'en' => 'Adults'],
            'contact.children' => ['fr' => 'Enfants (<12)', 'en' => 'Children (<12)'],
            'contact.message' => ['fr' => 'Message *', 'en' => 'Message *'],
            'contact.send' => ['fr' => 'Envoyer le message', 'en' => 'Send message'],

            'calendar.title' => ['fr' => 'Calendrier', 'en' => 'Calendar'],

            'update.title' => ['fr' => "Mise à jour de l'application", 'en' => 'Application update'],
            'update.deploy_title' => ['fr' => 'Déployer une nouvelle version', 'en' => 'Deploy a new version'],
            'update.deploy_desc' => ['fr' => "Uploadez le fichier ZIP généré par GitHub Actions pour mettre à jour l'application. Les répertoires images/ et files/storage/ ainsi que le fichier .env ne seront pas écrasés.", 'en' => 'Upload the ZIP file generated by GitHub Actions to update the application. The images/ and files/storage/ directories, as well as the .env file, will not be overwritten.'],
            'update.zip_label' => ['fr' => 'Fichier ZIP de déploiement', 'en' => 'Deployment ZIP file'],
            'update.submit' => ['fr' => '🚀 Mettre à Jour', 'en' => '🚀 Update'],
            'update.progress_uploading' => ['fr' => 'Envoi du fichier…', 'en' => 'Uploading file…'],
            'update.progress_applying' => ['fr' => 'Application de la mise à jour…', 'en' => 'Applying update…'],
            'update.progress_done' => ['fr' => 'Terminé', 'en' => 'Done'],
            'update.restore_title' => ['fr' => 'Restauration', 'en' => 'Restore'],
            'update.no_backup' => ['fr' => 'Aucune sauvegarde disponible. Une sauvegarde automatique est créée avant chaque mise à jour.', 'en' => 'No backup available. A backup is automatically created before each update.'],
            'update.last_backup' => ['fr' => 'Dernière sauvegarde disponible :', 'en' => 'Latest available backup:'],
            'update.restore_button' => ['fr' => '↩ Restaurer la version précédente', 'en' => '↩ Restore previous version'],
            'update.restore_confirm' => ['fr' => "Restaurer la version précédente ? Cette action écrasera les fichiers actuels de l'application.", 'en' => 'Restore the previous version? This will overwrite the application\'s current files.'],
            'update.all_backups' => ['fr' => 'Toutes les sauvegardes', 'en' => 'All backups'],
        ];
    }
}
