# Nadabo Global Data

Professional Nigerian digital-services platform (data, airtime, bills, KYC, wallet and more).
Laravel 12 · PHP 8.3+ · MySQL 8 / MariaDB 10.6+ · Blade · Tailwind CSS · Alpine.js · Vite.

Status: **foundation only**. No business features, provider/API or payment-gateway integrations exist yet.
See `PROJECT_STATUS.md`, `ROADMAP.md` and `docs/ARCHITECTURE.md`.

## Local setup

Requirements: PHP 8.3+, Composer, Node 20+, npm, rsync, perl, MySQL 8 or MariaDB 10.6+.

```bash
bash scripts/bootstrap.sh     # one time: Laravel skeleton + Pest + Alpine + build
# create an empty database and user, then edit .env (never commit it)
php artisan migrate
php artisan test
composer run dev              # or: php artisan serve  +  npm run dev
```

Checks: `/up` (framework) and `/api/v1/health` (API v1).

## Rules

- Everything is built and tested locally. cPanel is used only for the final deployment (Phase 20).
- Secrets live only in `.env`. `.env.example` holds blank placeholders.
- Business logic goes in `app/Actions` and `app/Services`, not controllers.
- Controllers only, no route closures (so `route:cache` works on cPanel).
