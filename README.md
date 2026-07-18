# samchlolaurepartners

Application PHP + MySQL pour la gestion multi-tenant de partenaires et de locations saisonnières.

## Structure

Le webroot du projet est la **racine** du dépôt (pas de sous-dossier `public/`) :

- `index.php` — front controller (point d'entrée unique)
- `.htaccess` — réécriture d'URL + protection des dossiers internes
- `assets/` — CSS/JS publics
- `images/logo/`, `images/listings/`, `images/others/` — médias téléversés (logos, photos de logements, autres visuels)
- `files/` — logique applicative PHP (contrôleurs, auth, vues, services, cache/logs internes). Accès web direct bloqué via `.htaccess`.
- `db/migrations/` et `db/seeds/` — schéma SQL d'origine réutilisé tel quel. Accès web direct bloqué via `.htaccess`.
- `db/config.php` — identifiants de connexion MySQL (à copier depuis `db/config.example.php`). C'est le **seul** réglage qui vit hors de la base de données : il faut bien pouvoir se connecter à MySQL avant de pouvoir y lire quoi que ce soit d'autre. Toute la configuration applicative (clés API, SMTP, JWT, etc.) est stockée dans la table `settings`.
- `bin/migrate.php` — applique les migrations SQL existantes
- `bin/run-scheduler.php` — traitement cron des emails planifiés (réservations à venir). La fiche Lodgify (photos, description, capacité…) n'est plus synchronisée automatiquement : utilisez le bouton « Synchroniser maintenant » sur `/admin/sync`. Les prix/disponibilités restent toujours interrogés en direct auprès de Lodgify à chaque recherche.
- `install/install.php` — assistant d'installation pour hébergement mutualisé / cPanel (à supprimer après usage)

## Démarrage local

```bash
cp db/config.example.php db/config.php
# éditez db/config.php avec vos identifiants MySQL
php bin/migrate.php
php -S 127.0.0.1:8080
```

⚠️ Le serveur intégré de PHP n'applique pas les règles `.htaccess` : en local, `files/` et `db/` restent techniquement accessibles. Utilisez Apache (Docker) pour tester la protection réelle.

## Docker

```bash
docker compose up --build
```

Application: `http://localhost:8080`
MailHog: `http://localhost:8025`

## Déploiement cPanel

1. Déployez l'ensemble des fichiers directement à la racine du webroot du domaine (ex. `home/mcherpco/grand-baie-maurice.com/`), sans sous-dossier `public/`.
2. Le webroot du domaine reste la racine du projet : `index.php` et `.htaccess` doivent s'y trouver directement.
3. Copiez `db/config.example.php` vers `db/config.php` et renseignez vos identifiants MySQL réels (ou lancez `install/install.php` via `https://votre-domaine/install/install.php`, qui le lit directement).
4. Lancez `php bin/migrate.php` une première fois si l'assistant ne l'a pas déjà fait.
5. Ajoutez une tâche cron pour `php /chemin/vers/bin/run-scheduler.php`.
6. Supprimez le dossier `install/` une fois l'installation vérifiée.

