# Centralink

Centralink est mon projet Workshop : une application Symfony de gestion de transactions et de demandes, avec un espace d'administration (EasyAdmin) protégé par rôles (`ROLE_USER` / `ROLE_ADMIN`).

## Stack

- Symfony 7 / PHP 8.4 (FrankenPHP + Caddy)
- PostgreSQL 16
- EasyAdminBundle pour le back-office
- Déploiement Railway (build Nixpacks, voir `nixpacks.toml` / `railway.json`)

## Variables d'environnement

- `.env` : valeurs par défaut génériques, committées (convention Symfony), sans secret réel.
- `.env.local` : overrides locaux (non commités, ignorés par git) — votre propre `DATABASE_URL`, etc.
- En production (Railway), les variables réelles (`DATABASE_URL`, `APP_SECRET`, `APP_ENV`, `MESSENGER_TRANSPORT_DSN`, `MAILER_DSN`) sont injectées directement dans les Variables du service et prennent toujours le dessus sur les fichiers `.env`.

## Lancer le projet (développement local)

```bash
composer install
php bin/console doctrine:migrations:migrate
symfony serve
```

Nécessite une instance PostgreSQL locale et un `.env.local` avec votre propre `DATABASE_URL`.

## Tests

```bash
php bin/phpunit
```
