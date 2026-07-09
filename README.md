# samchlolaurepartners

Application PHP + MySQL pour la gestion multi-tenant de partenaires et de locations saisonnières.

## Structure

- `public/` — webroot (front controller, assets)
- `src/` — logique applicative PHP (contrôleurs, auth, vues, services)
- `bin/migrate.php` — applique les migrations SQL existantes
- `bin/run-scheduler.php` — traitement cron des emails planifiés
- `database/migrations/` et `database/seeds/` — schéma SQL d'origine réutilisé tel quel
- `install.php` — assistant d'installation pour hébergement mutualisé / cPanel

## Démarrage local

```bash
cp .env.example .env
php bin/migrate.php
php -S 127.0.0.1:8080 -t public
```

## Docker

```bash
docker compose up --build
```

Application: `http://localhost:8080`
MailHog: `http://localhost:8025`

## Déploiement cPanel

1. Déployez les fichiers sur l'hébergement.
2. Pointez le webroot vers `public/`.
3. Renseignez `.env` (ou lancez `install.php`).
4. Lancez `php bin/migrate.php` une première fois si l'assistant ne l'a pas déjà fait.
5. Ajoutez une tâche cron pour `php /chemin/vers/bin/run-scheduler.php`.
