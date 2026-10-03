# PROJECT STATUS: Nadabo Global Data

Last updated: 2026-10-03 · Stage: **Phase 1, foundation installed and verified**

## Current state
Foundation installed with `scripts/bootstrap.sh` and verified on 2026-10-03: Laravel 12.69.3, PHP 8.3.6, Composer dependencies, Node 22 / npm 10 with a successful `npm run build`, migrations on MariaDB 10.11, Pest tests (4 passed), `/up` returns 200, `/api/v1/health` returns 200. The repository holds the Laravel backend in `backend/` and the Flutter app in `mobile/`.

## Technology stack (approved)
| Area | Choice |
|---|---|
| Framework | Laravel 12 |
| PHP | 8.3+ (cPanel host support to be confirmed) |
| Database | MySQL 8 / MariaDB 10.6+ (utf8mb4) |
| Frontend | Blade, Tailwind CSS, Alpine.js, Vite |
| Auth (later) | Laravel Breeze (web), Laravel Sanctum (mobile API) |
| Permissions (later) | spatie/laravel-permission |
| Queue / cache / sessions | database drivers |
| Testing | Pest |

## Existing architecture
Action/Service layering, versioned API (`/api/v1`), thin controllers. See `docs/ARCHITECTURE.md`.

## Existing modules
None. Infrastructure only: `GET /api/v1/health`, plus Laravel's `/up`.

## Existing database structure
None yet. After `php artisan migrate` only the Laravel defaults exist (users, sessions, cache, jobs).

## Existing frontend structure
- `resources/css/theme.css` (navy/blue tokens), `resources/css/app.css`, `resources/js/app.js` (Alpine)
- `resources/views/layouts/base.blade.php`, empty `public/`, `user/`, `admin/`, `components/`

## Existing backend structure
- `app/Actions`, `app/Services`, `app/Support` (empty)
- `app/Http/Controllers/{Admin,User}` (empty), `Api/V1/HealthController`
- `routes/api.php`, `routes/api/v1.php`
- Tests: `tests/Feature/HealthCheckTest.php`, `tests/Feature/Api/V1/HealthTest.php`

## Not built (by instruction)
Business features, authentication, roles, providers/APIs, payment gateways, cPanel deployment.

## Problems / blockers
1. Resolved: foundation installed and verified. `scripts/bootstrap.sh` was fixed to create `tests/Pest.php` itself, because Pest 3 has no `pest:install` artisan command.
2. cPanel PHP 8.3 availability unconfirmed. Check before Phase 20, or earlier if you already have a host.
3. Breeze will rewrite `resources/css/app.css` and may expect a particular Tailwind version. Brand tokens are in `theme.css` so they survive; re-add the `@import './theme.css'` line afterwards and verify the build.
4. `scripts/bootstrap.sh` patches `bootstrap/app.php` to register `routes/api.php`. It stops with a clear message if the patch cannot apply.
5. Tests use Laravel's default in-memory SQLite, so `pdo_sqlite` must be enabled locally.

## Missing requirements (needed before the relevant phase)
- Chosen hosting plan and PHP version (Phase 20)
- Provider/API documentation for each service (Phase 7 and 10)
- Gateway accounts and docs for Monnify and Aspfiy (Phase 9)
- Business rules: pricing tiers, commission and referral rates, withdrawal limits and fees (Phases 6, 12, 14)
- Confirmed KYC rules per provider (Phase 13)
- Logo and brand assets (Phase 3)
