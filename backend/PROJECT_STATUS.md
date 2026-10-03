# PROJECT STATUS: Nadabo Global Data

Last updated: 2026-10-03 · Stage: **Phase 2, authentication & user foundation complete** (awaiting approval for Phase 3)

## Current state
- Phase 1 foundation installed and verified with `scripts/bootstrap.sh`: Laravel 12.69.3, PHP 8.3.6, Node 22 / npm 10, MariaDB 10.11.
- Phase 2 adds customer and staff authentication, user types, account status, a profile page, a user dashboard shell, an admin login, and Sanctum token authentication for the future mobile app. 46 Pest tests pass. `/up` and `/api/v1/health` return 200.
- Repository layout: Laravel backend in `backend/`, Flutter app in `mobile/`.

## Technology stack (approved)
| Area | Choice |
|---|---|
| Framework | Laravel 12 |
| PHP | 8.3+ (cPanel host support to be confirmed) |
| Database | MySQL 8 / MariaDB 10.6+ (utf8mb4) |
| Frontend | Blade, Tailwind CSS 4, Alpine.js, Vite |
| Web auth | Custom session auth in the Breeze style (Breeze itself not installed, see decisions) |
| API auth | Laravel Sanctum 4 personal access tokens |
| Permissions | spatie/laravel-permission 6 |
| Queue / cache / sessions | database drivers |
| Testing | Pest 3 |

## Existing architecture
Action/Service layering, versioned API (`/api/v1`), thin controllers. See `docs/ARCHITECTURE.md`.

## Existing modules
- **Infrastructure:** `GET /up`, `GET /api/v1/health`
- **Customer auth (web):** `/register`, `/login` (email or phone), `POST /logout`, `/forgot-password`, `/reset-password/{token}`
- **Customer area:** `/dashboard`, `/profile` (name, email, phone, password change)
- **Admin auth:** `/admin/login`, `/admin` (placeholder page; the dashboard itself is Phase 3)
- **API auth:** `POST /api/v1/auth/token`, `DELETE /api/v1/auth/token`, `GET /api/v1/user`
- **Console:** `php artisan nadabo:create-admin {email} [--super]` (password typed interactively)

## Existing database structure
- Laravel defaults: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`
- `users` extra columns: `phone` (unique, nullable), `user_type` (subscriber, vendor, affiliate, api_user), `status` (active, disabled), `last_login_at`, `last_login_ip`
- spatie permission tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`
- Sanctum: `personal_access_tokens`
- Seeded (`php artisan db:seed`): roles `super-admin`, `admin`; permission `admin.access`. No users are seeded.

## Authentication and authorization rules
- Login by email or Nigerian phone number (stored as `0XXXXXXXXXX`; `+234…` accepted). Passwords hashed with bcrypt; minimum 8 characters, mixed case and a number.
- 5 failed attempts per login and IP lock that pair for 60 seconds. Sign-up, reset and token endpoints are rate limited.
- Disabled accounts cannot log in. Active sessions and API tokens of a disabled account stop working on the next request.
- Session regenerated on login and invalidated on logout. Password change signs out other devices and revokes API tokens. Password reset revokes API tokens.
- Forgot-password gives the same response whether or not the email exists.
- Public sign-ups are always **Subscriber**. `user_type` and `status` are never mass assignable; only server code or an admin (Phase 3) changes them.
- Customer tier = `users.user_type` (one per user, drives pricing later). Staff access = spatie roles and permissions. `super-admin` passes every check through `Gate::before`.

## Existing frontend structure
- `resources/css/theme.css` (navy/blue tokens), `resources/css/app.css`, `resources/js/app.js` (Alpine)
- Layouts: `layouts/base` (root), `layouts/guest` (auth card), `layouts/app` (signed-in shell with mobile menu)
- Components: `x-input`, `x-button`, `x-alert`
- Views: `auth/*`, `user/dashboard`, `user/profile`, `admin/auth/login`, `admin/dashboard`

## Existing backend structure
- `app/Actions/Auth/{RegisterUser, AuthenticateUser}`: shared by web, admin and API login
- `app/Support/Enums/{UserType, UserStatus}`, `app/Support/Validation/AccountRules`
- `app/Http/Middleware/{EnsureUserIsActive, EnsureUserCanAccessAdmin}` (aliases `active`, `admin`)
- Controllers: `Auth/*`, `User/*`, `Admin/{DashboardController, Auth/AdminSessionController}`, `Api/V1/{HealthController, Auth/*}`
- `app/Console/Commands/CreateAdminCommand`
- Tests: `tests/Feature/{Auth, User, Admin, Api/V1}`, `tests/Unit/UserPhoneTest`

## Not built (by instruction)
Admin dashboard (Phase 3), services, plans, providers/APIs, wallet, payment gateways, referral, KYC, business API endpoints, cPanel deployment.

## Problems / blockers
1. cPanel PHP 8.3 availability unconfirmed. Check before Phase 20, or earlier if you already have a host.
2. Password-reset email uses `MAIL_MAILER=log` locally. Real SMTP details are needed before launch.
3. Tests use in-memory SQLite, so `pdo_sqlite` must be enabled locally.

## Decisions awaiting approval
See the Phase 2 report: email verification, Sanctum token expiry, phone-based password reset, who may self-select Vendor/Affiliate/API User.

## Missing requirements (needed before the relevant phase)
- Chosen hosting plan and PHP version (Phase 20)
- Provider/API documentation for each service (Phase 7 and 10)
- Gateway accounts and docs for Monnify and Aspfiy (Phase 9)
- Business rules: pricing tiers, commission and referral rates, withdrawal limits and fees (Phases 6, 12, 14)
- Rules for upgrading a customer to Vendor, Affiliate or API User (Phase 3 or 6)
- Confirmed KYC rules per provider (Phase 13)
- SMTP / SMS provider for account emails and OTPs
- Logo and brand assets (Phase 3)
