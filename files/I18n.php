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

    /**
     * Full month names (1-indexed), localized for the active site language.
     * Used by the property-detail calendar (files/views/partials/calendar-body.php).
     *
     * @return array<int, string>
     */
    public static function monthNames(): array
    {
        return self::current() === 'en'
            ? [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
            : [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    }

    /**
     * Short month abbreviations (1-indexed), localized for the active site
     * language. Used by the multi-property "Calendrier" board
     * (files/views/pages/calendar.php).
     *
     * @return array<int, string>
     */
    public static function monthNamesShort(): array
    {
        return self::current() === 'en'
            ? [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            : [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    }

    /**
     * Single-letter weekday headers (Sun..Sat), localized for the active
     * site language. Used by the property-detail calendar grid.
     *
     * @return array<int, string>
     */
    public static function weekdayLettersSundayFirst(): array
    {
        return self::current() === 'en'
            ? ['S', 'M', 'T', 'W', 'T', 'F', 'S']
            : ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
    }

    /**
     * Short weekday abbreviations (Sun..Sat), localized for the active site
     * language. Used by the multi-property "Calendrier" board day headers.
     *
     * @return array<int, string>
     */
    public static function weekdaysShortSundayFirst(): array
    {
        return self::current() === 'en'
            ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
            : ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
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
            'nav.dashboard' => ['fr' => 'Tableau de Bord', 'en' => 'Dashboard'],
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

            'footer.privacy_policy' => ['fr' => 'Politique de confidentialité', 'en' => 'Privacy policy'],
            'privacy.page_title' => ['fr' => 'Politique de confidentialité', 'en' => 'Privacy policy'],

            'calendar.title' => ['fr' => 'Calendrier', 'en' => 'Calendar'],
            'calendar.help' => ['fr' => 'Aide', 'en' => 'Help'],
            'calendar.help_close' => ['fr' => 'Fermer', 'en' => 'Close'],
            'calendar.help_intro' => ['fr' => "Vue d'ensemble des disponibilités et tarifs de tous les biens. Approchez la souris du bord gauche ou droit du tableau pour faire défiler les dates.", 'en' => 'Overview of the availability and rates of all properties. Move the mouse close to the left or right edge of the table to scroll through the dates.'],
            'calendar.help_multi' => ['fr' => "Réservez plusieurs biens en quelques clics : cliquez une date d'arrivée puis une date de départ sur un bien, puis recommencez sur un autre bien (mêmes dates ou dates différentes) pour l'ajouter à votre sélection.", 'en' => 'Book several properties in a few clicks: click a check-in date then a check-out date on a property, then repeat on another property (same or different dates) to add it to your selection.'],
            'calendar.search_period' => ['fr' => 'Période de recherche.', 'en' => 'Search period.'],
            'calendar.from' => ['fr' => 'Du', 'en' => 'From'],
            'calendar.to' => ['fr' => 'Au', 'en' => 'To'],
            'calendar.adults' => ['fr' => 'Adultes', 'en' => 'Adults'],
            'calendar.children_under3' => ['fr' => 'Enfants (<3ans)', 'en' => 'Children (<3yo)'],
            'calendar.children_3to12' => ['fr' => 'Enfants (3-11ans)', 'en' => 'Children (3-11yo)'],
            'calendar.show_availability' => ['fr' => 'Afficher les disponibilités', 'en' => 'Show availability'],
            'calendar.loading' => ['fr' => 'Chargement des disponibilités…', 'en' => 'Loading availability…'],
            'calendar.guest_required_hint' => ['fr' => 'Veuillez renseigner le nombre de personnes ci-dessus puis cliquer sur « Afficher les disponibilités » pour voir les biens et leurs dates disponibles.', 'en' => 'Please enter the number of people above then click "Show availability" to see the properties and their available dates.'],
            'calendar.no_properties' => ['fr' => 'Aucun hébergement à afficher.', 'en' => 'No accommodation to display.'],
            'calendar.click_dates_hint' => ['fr' => 'Cliquez sur les dates que vous souhaitez afin de renseigner votre demande.', 'en' => 'Click the dates you want to fill in your request.'],
            'calendar.show_property_name' => ['fr' => 'Afficher le nom du bien', 'en' => 'Show property name'],
            'calendar.legend_available' => ['fr' => 'Disponible', 'en' => 'Available'],
            'calendar.legend_unavailable' => ['fr' => 'Indisponible', 'en' => 'Unavailable'],
            'calendar.legend_blocked' => ['fr' => 'Bloquée', 'en' => 'Blocked'],
            'calendar.legend_single_night' => ['fr' => "Réservation d'1 nuit (arrivée ou départ uniquement)", 'en' => '1-night booking (arrival or departure only)'],
            'calendar.legend_not_bookable' => ['fr' => 'Non réservable / Non renseigné', 'en' => 'Not bookable / Not set'],
            'calendar.legend_not_bookable_full' => ['fr' => 'Non réservable (séjour minimum non atteint) / Non renseigné / Date passée', 'en' => 'Not bookable (minimum stay not reached) / Not set / Past date'],
            'calendar.legend_price_currency' => ['fr' => 'Tarif en Euros', 'en' => 'Price in Euros'],
            'calendar.view_selection' => ['fr' => 'Voir votre sélection', 'en' => 'View your selection'],
            'calendar.col_photo' => ['fr' => 'Photo', 'en' => 'Photo'],
            'calendar.col_property' => ['fr' => 'Bien', 'en' => 'Property'],
            'calendar.col_max_guests' => ['fr' => 'Pers. max', 'en' => 'Max guests'],
            'calendar.col_bedrooms' => ['fr' => 'Chambres', 'en' => 'Bedrooms'],
            'calendar.col_sofa_beds' => ['fr' => 'Canapé-lit(s)', 'en' => 'Sofa bed(s)'],
            'calendar.load_failed' => ['fr' => 'Disponibilités temporairement indisponibles — réessayez dans quelques instants.', 'en' => 'Availability temporarily unavailable — please try again shortly.'],
            'calendar.restricted_note' => ['fr' => 'Merci de contacter votre agence pour ce bien.', 'en' => 'Please contact your agency for this property.'],
            'calendar.your_selection' => ['fr' => 'Votre sélection', 'en' => 'Your selection'],
            'calendar.clear_selection' => ['fr' => 'Effacer les sélections', 'en' => 'Clear selections'],
            'calendar.full_name' => ['fr' => 'Nom et prénom complet *', 'en' => 'Full name *'],
            'calendar.email' => ['fr' => 'Email *', 'en' => 'Email *'],
            'calendar.message_optional' => ['fr' => 'Message (optionnel)', 'en' => 'Message (optional)'],
            'calendar.send_requests' => ['fr' => 'Envoyer mes demandes de réservation', 'en' => 'Send my booking requests'],
            'calendar.request_sent' => ['fr' => 'Vos demandes de réservation ont été envoyées ! Vous recevrez un email de confirmation.', 'en' => 'Your booking requests have been sent! You will receive a confirmation email.'],
            'calendar.spam_note' => ['fr' => 'Vérifiez vos courriers indésirables, il arrive que les emails soient catégorisés comme indésirables.', 'en' => 'Please check your spam folder, emails are sometimes flagged as spam.'],
            'calendar.close' => ['fr' => 'Fermer', 'en' => 'Close'],
            'calendar.total_amount' => ['fr' => 'Montant Total :', 'en' => 'Total amount:'],
            'calendar.euros' => ['fr' => 'Euros', 'en' => 'Euros'],
            'calendar.tourist_tax_included' => ['fr' => 'Dont taxe touristique :', 'en' => 'Including tourist tax:'],
            'calendar.tourist_tax_included_suffix' => ['fr' => 'Euros (incluse dans le total)', 'en' => 'Euros (included in the total)'],
            'calendar.capacity_summary' => ['fr' => 'Capacité cumulée du/des bien(s) sélectionné(s) :', 'en' => 'Combined capacity of the selected property/properties:'],
            'calendar.price_info_title' => ['fr' => 'Information sur les prix affichés', 'en' => 'Information on displayed prices'],
            'calendar.price_info_max' => ['fr' => 'Les prix affichés sont pour un maximum de :', 'en' => 'The displayed prices are for a maximum of:'],
            'calendar.price_info_empty' => ['fr' => 'Aucune information tarifaire disponible pour le moment.', 'en' => 'No pricing information available at the moment.'],
            'calendar.price_info_people' => ['fr' => 'personnes', 'en' => 'people'],
            'calendar.price_info_extra_fee' => ['fr' => 'Frais additionnel de %s Euros par nuit par personne', 'en' => 'Additional fee of %s Euros per night per person'],
            'calendar.price_info_babies' => ['fr' => '+ 2 enfants de moins de 3 ans (Gratuitement)', 'en' => '+ 2 children under 3 years old (Free)'],
            'calendar.price_note' => ['fr' => 'Tarif de la nuité en Euros. Le tarif exclus les frais de nettoyage%s (2 fois par semaine), qui sont ajoutés séparément au total.', 'en' => 'Nightly rate in Euros. The rate excludes the cleaning fee%s (twice a week), which is added separately to the total.'],
            'calendar.price_note_cleaning_fee' => ['fr' => ' de %s Euros par personne par nuit', 'en' => ' of %s Euros per person per night'],
            'calendar.tourist_tax_note' => ['fr' => "La taxe touristique de %s Euros par étranger (d'au moins 12 ans) et par nuit sera rajoutée au total et affichée dans le résumé.", 'en' => 'The tourist tax of %s Euros per foreigner (aged 12 and over) per night will be added to the total and displayed in the summary.'],

            'property.rooms_max_guests' => ['fr' => '%d chambre(s) · %d personnes max', 'en' => '%d bedroom(s) · %d guests max'],
            'property.check_availability' => ['fr' => 'Vérifier les disponibilités', 'en' => 'Check availability'],
            'property.link_copied' => ['fr' => 'Lien copié', 'en' => 'Link copied'],
            'property.share' => ['fr' => 'Partager', 'en' => 'Share'],
            'property.tab_description' => ['fr' => 'Description', 'en' => 'Description'],
            'property.tab_amenities' => ['fr' => 'Équipements', 'en' => 'Amenities'],
            'property.tab_location' => ['fr' => 'Emplacement', 'en' => 'Location'],
            'property.tab_rates_availability' => ['fr' => 'Tarifs & Disponibilités', 'en' => 'Rates & Availability'],
            'property.checkin' => ['fr' => 'Arrivée', 'en' => 'Check-in'],
            'property.checkout' => ['fr' => 'Départ', 'en' => 'Check-out'],
            'property.no_amenities' => ['fr' => 'Aucun équipement listé.', 'en' => 'No amenities listed.'],
            'property.no_location' => ['fr' => 'Emplacement non disponible.', 'en' => 'Location not available.'],
            'property.view_on_osm' => ['fr' => 'Voir sur OpenStreetMap', 'en' => 'View on OpenStreetMap'],
            'property.clear_dates' => ['fr' => 'Effacer les dates sélectionnées', 'en' => 'Clear selected dates'],
            'property.contact_agency' => ['fr' => 'Merci de contacter votre agence pour ce bien.', 'en' => 'Please contact your agency for this property.'],
            'property.rates_unavailable' => ['fr' => 'Tarifs non disponibles pour le moment.', 'en' => 'Rates not available at the moment.'],
            'property.price_min_people' => ['fr' => 'Les tarifs affichés sont pour %d personne(s).', 'en' => 'The displayed rates are for %d guest(s).'],
            'property.price_extra_person_fee' => ['fr' => 'Un frais additionnel de %s Euros par nuit par personne', 'en' => 'An additional fee of %s Euros per night per person'],
            'property.price_babies_and_tax' => ['fr' => '+ 2 enfants de moins de 3 ans (Gratuitement)', 'en' => '+ 2 children under 3 years old (Free)'],
            'property.tourist_tax_note' => ['fr' => 'et/ou la taxe touristique de %s Euros par personne par nuit pour les étrangers à partir de 12ans seront rajoutés au total.', 'en' => 'and/or the tourist tax of %s Euros per person per night for foreigners aged 12 and over will be added to the total.'],
            'property.select_dates_hint' => ['fr' => "Cliquez sur une date disponible du calendrier pour renseigner votre date d'arrivée, puis cliquez sur une seconde date pour la date de départ.", 'en' => 'Click an available date on the calendar to enter your check-in date, then click a second date for your check-out date.'],
            'property.booking_policy_title' => ['fr' => 'Politique de réservation', 'en' => 'Booking policy'],
            'property.stay_dates' => ['fr' => 'Dates du séjour *', 'en' => 'Stay dates *'],
            'property.select_dates_in_calendar' => ['fr' => 'Sélectionnez vos dates dans le calendrier (Tarifs &amp; Disponibilités) : 1er clic = arrivée, 2e clic = départ.', 'en' => 'Select your dates in the calendar (Rates &amp; Availability): 1st click = check-in, 2nd click = check-out.'],
            'property.nights_count' => ['fr' => 'Nuits', 'en' => 'Nights'],
            'property.click_other_date_for_checkout' => ['fr' => 'Cliquez sur une autre date du calendrier pour le départ', 'en' => 'Click another date on the calendar for check-out'],
            'property.min_stay_hint' => ['fr' => 'séjour minimum : %d nuits', 'en' => 'minimum stay: %d nights'],
            'property.number_of_travelers' => ['fr' => 'Nombre de Voyageur(s)', 'en' => 'Number of Traveler(s)'],
            'property.max_capacity' => ['fr' => 'Capacité maximum : %d personne(s).', 'en' => 'Maximum capacity: %d guest(s).'],
            'property.adults' => ['fr' => 'Adulte(s)', 'en' => 'Adult(s)'],
            'property.children_under3' => ['fr' => 'Enfant(s) -3 ans', 'en' => 'Child(ren) under 3 yo'],
            'property.children_3to12' => ['fr' => 'Enfant(s) 3-12 ans', 'en' => 'Child(ren) 3-12 yo'],
            'property.rate_nights' => ['fr' => 'Tarif (%s nuit(s))', 'en' => 'Rate (%s night(s))'],
            'property.extra_guests' => ['fr' => 'Personne(s) supplémentaire(s)', 'en' => 'Extra guest(s)'],
            'property.cleaning' => ['fr' => 'Nettoyage', 'en' => 'Cleaning'],
            'property.tourist_tax' => ['fr' => 'Taxe touristique', 'en' => 'Tourist tax'],
            'property.total' => ['fr' => 'Total', 'en' => 'Total'],
            'property.force_nightly_price' => ['fr' => 'Forcer le prix total des nuit(s) (TTC)', 'en' => 'Force the total price for the night(s) (tax incl.)'],
            'property.force_nightly_price_adjusted' => ['fr' => 'Le prix saisi était inférieur au minimum autorisé : il a été ajusté au tarif minimum payé à Sam Chlo Laure.', 'en' => 'The entered price was below the allowed minimum: it was adjusted to the minimum rate paid to Sam Chlo Laure.'],
            'property.force_price_edit' => ['fr' => 'Modifier le prix', 'en' => 'Edit price'],
            'property.force_price_save' => ['fr' => 'Enregistrer', 'en' => 'Save'],
            'property.force_price_cancel' => ['fr' => 'Annuler', 'en' => 'Cancel'],
            'property.force_price_current_label' => ['fr' => 'Tarif actuel (%s nuit(s)) :', 'en' => 'Current rate (%s night(s)):'],
            'property.force_price_lodgify_label' => ['fr' => 'Prix SCL (TVA incl., hors com.) :', 'en' => 'Lodgify price (VAT incl., before commission):'],
            'property.force_price_vat_label' => ['fr' => 'TVA (%s%%)', 'en' => 'VAT (%s%%)'],
            'property.force_price_commission_label' => ['fr' => 'Commission', 'en' => 'Commission'],
            'property.force_extra_person_price' => ['fr' => 'Forcer le prix des personne(s) supplémentaire(s) (TTC)', 'en' => 'Force the price for the extra guest(s) (tax incl.)'],
            'property.force_extra_person_price_adjusted' => ['fr' => 'Le prix saisi était inférieur au minimum autorisé : il a été ajusté au tarif minimum payé à Sam Chlo Laure.', 'en' => 'The entered price was below the allowed minimum: it was adjusted to the minimum rate paid to Sam Chlo Laure.'],
            'property.force_extra_person_current_label' => ['fr' => 'Tarif actuel (%s pers. supp.) :', 'en' => 'Current rate (%s extra guest(s)):'],
            'property.traveler_details' => ['fr' => 'Détails des Voyageurs', 'en' => 'Traveler Details'],
            'property.full_name' => ['fr' => 'Nom et prénom complet *', 'en' => 'Full name *'],
            'property.email' => ['fr' => 'Email *', 'en' => 'Email *'],
            'property.message_optional' => ['fr' => 'Message (optionnel)', 'en' => 'Message (optional)'],
            'property.booking_policy_override' => ['fr' => 'Politique de réservation (pour cette demande)', 'en' => 'Booking policy (for this request)'],
            'property.booking_policy_override_hint' => ['fr' => 'Ce texte sera utilisé dans l\'email envoyé au client et dans votre copie pour cette demande uniquement.', 'en' => 'This text will be used in the email sent to the client and in your own copy, for this request only.'],
            'property.send_request' => ['fr' => 'Envoyer ma demande', 'en' => 'Send my request'],
            'property.name_required' => ['fr' => 'Nom et prénom non renseignés', 'en' => 'Full name not provided'],
            'property.email_required' => ['fr' => 'Email non renseigné', 'en' => 'Email not provided'],
            'property.nationality_required' => ['fr' => 'Nationalité non renseignée', 'en' => 'Nationality not provided'],
            'property.request_sent' => ['fr' => 'Demande envoyée ! Vous recevrez un email de confirmation.', 'en' => 'Request sent! You will receive a confirmation email.'],
            'property.spam_note' => ['fr' => 'Vérifiez vos courriers indésirables, il arrive que les emails soient catégorisés comme indésirables.', 'en' => 'Please check your spam folder, emails are sometimes flagged as spam.'],
            'property.close' => ['fr' => 'Fermer', 'en' => 'Close'],
            'property.hide' => ['fr' => 'Masquer', 'en' => 'Hide'],
            'property.decrease_adults' => ['fr' => "Diminuer le nombre d'adultes", 'en' => 'Decrease the number of adults'],
            'property.increase_adults' => ['fr' => "Augmenter le nombre d'adultes", 'en' => 'Increase the number of adults'],
            'property.decrease_children_under3' => ['fr' => "Diminuer le nombre d'enfants de moins de 3 ans", 'en' => 'Decrease the number of children under 3'],
            'property.increase_children_under3' => ['fr' => "Augmenter le nombre d'enfants de moins de 3 ans", 'en' => 'Increase the number of children under 3'],
            'property.decrease_children_3to12' => ['fr' => "Diminuer le nombre d'enfants de 3 à 12 ans", 'en' => 'Decrease the number of children aged 3 to 12'],
            'property.increase_children_3to12' => ['fr' => "Augmenter le nombre d'enfants de 3 à 12 ans", 'en' => 'Increase the number of children aged 3 to 12'],
            'property.children_under3_label' => ['fr' => 'Enfants (moins de 3 ans)', 'en' => 'Children (under 3 years old)'],
            'property.children_3to12_label' => ['fr' => 'Enfants (3 à 12 ans)', 'en' => 'Children (3 to 12 years old)'],

            'update.title' => ['fr' => "Mise à jour de l'application", 'en' => 'Application update'],
            'update.deploy_title' => ['fr' => 'Déployer une nouvelle version', 'en' => 'Deploy a new version'],
            'update.deploy_desc' => ['fr' => "Uploadez le fichier ZIP généré par GitHub Actions pour mettre à jour l'application. Ces éléments ne seront pas écrasés :", 'en' => 'Upload the ZIP file generated by GitHub Actions to update the application. These items will not be overwritten:'],
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
