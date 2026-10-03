# PROJECT STATUS: Nadabo Global Data

Last updated: 2026-10-03 · Stage: **Phase 2 complete, plus approved pre-Phase-3 changes** (separate staff accounts, email verification, token expiry). Awaiting approval for Phase 3

## Current state
- Phase 1 foundation installed and verified with `scripts/bootstrap.sh`: Laravel 12.69.3, PHP 8.3.6, Node 22 / npm 10, MariaDB 10.11.
- Phase 2 adds customer authentication, user types, account status, a profile page, a user dashboard shell, and Sanctum token authentication for the future mobile app.
- Pre-Phase-3 changes: staff (System Users) are fully separate from customers, with 5 roles on their own guard; configurable email verification (off); configurable API token expiry. 89 Pest tests pass. `/up` and `/api/v1/health` return 200.
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
| Permissions | spatie/laravel-permission 6 (staff only, `admin` guard) |
| Queue / cache / sessions | database drivers |
| Testing | Pest 3 |

## Existing architecture
Action/Service layering, versioned API (`/api/v1`), thin controllers. See `docs/ARCHITECTURE.md`.

## Existing modules
- **Infrastructure:** `GET /up`, `GET /api/v1/health`
- **Customer auth (web):** `/register`, `/login` (email or phone), `POST /logout`, `/forgot-password`, `/reset-password/{token}`
- **Customer area:** `/dashboard`, `/profile` (name, email, phone, password change)
- **Email verification (configurable, off):** `/verify-email`, `/verify-email/{id}/{hash}`, `POST /email/verification-notification`
- **Staff auth:** `/admin/login` (email), `POST /admin/logout`, `/admin` (placeholder page; the dashboard itself is Phase 3)
- **API auth:** `POST /api/v1/auth/token`, `DELETE /api/v1/auth/token`, `GET /api/v1/user`
- **Console:** `php artisan nadabo:create-system-user {email} --role=<super-admin|manager|support|finance|viewer>` (password typed interactively)
- **Customer type changes:** `App\Actions\Customers\ChangeUserType` (requires staff permission `customers.change-type`; UI in Phase 3)

## Existing database structure
- Laravel defaults: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`
- `users` extra columns: `phone` (unique, nullable), `user_type` (subscriber, vendor, affiliate, api_user), `status` (active, disabled), `last_login_at`, `last_login_ip`
- `system_users`: staff accounts (name, email, password, status, last login). `admin_sessions`: admin-area sessions.
- spatie permission tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (staff only)
- Sanctum: `personal_access_tokens` (customers only)
- Seeded (`php artisan db:seed`): staff roles and permissions below. No accounts are seeded.

## Authentication and authorization rules
- Login by email or Nigerian phone number (stored as `0XXXXXXXXXX`; `+234…` accepted). Passwords hashed with bcrypt; minimum 8 characters, mixed case and a number.
- 5 failed attempts per login and IP lock that pair for 60 seconds. Sign-up, reset and token endpoints are rate limited.
- Disabled accounts cannot log in. Active sessions and API tokens of a disabled account stop working on the next request.
- Session regenerated on login and invalidated on logout. Password change signs out other devices and revokes API tokens. Password reset revokes API tokens.
- Forgot-password gives the same response whether or not the email exists.
- Public sign-ups are always **Subscriber**. `user_type` and `status` are never mass assignable; only server code or an admin (Phase 3) changes them.
- Customer tier = `users.user_type` (one per user, drives pricing later). Only staff with `customers.change-type` can change it.
- Email verification: `NADABO_REQUIRE_EMAIL_VERIFICATION` (default false). Off: no emails, nobody blocked. On: verification email on sign-up and email change; unverified customers are sent to `/verify-email` (profile stays reachable).
- API tokens: lifetime from `SANCTUM_TOKEN_EXPIRATION` in minutes (empty/0 = no expiry); `expires_at` returned with each token; expired tokens pruned daily by the scheduler.

## Staff (System Users)
- Separate `system_users` table, `SystemUser` model, `admin` session guard. Customer accounts can never sign in at `/admin/login`; staff accounts can never sign in at `/login`.
- Separate session: cookie `nadabo_admin_session` limited to `/admin`, stored in `admin_sessions`. Signing out of one area does not affect the other.
- Separate login throttling (5 failures per email + IP).
- Disabled staff, or staff without a role, cannot sign in; a disabled account is signed out on its next request.
- Roles and permissions (spatie, `admin` guard). Super Admin passes every check while active.

| Permission | Super Admin | Manager | Support | Finance | Viewer |
|---|---|---|---|---|---|
| `admin.access` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.update-status` | ✓ | ✓ | ✓ | | |
| `customers.change-type` | ✓ | ✓ | | | |
| `system-users.manage` | ✓ | | | | |

## Existing frontend structure
- `resources/css/theme.css` (navy/blue tokens), `resources/css/app.css`, `resources/js/app.js` (Alpine)
- Layouts: `layouts/base` (root), `layouts/guest` (auth card), `layouts/app` (signed-in shell with mobile menu)
- Components: `x-input`, `x-button`, `x-alert`
- Layouts also include `layouts/admin` (staff area)
- Views: `auth/*` (incl. `verify-email`), `user/dashboard`, `user/profile`, `admin/auth/login`, `admin/dashboard`

## Existing backend structure
- `app/Actions/Auth/{RegisterUser, AuthenticateUser}` (customers, web and API), `app/Actions/Admin/Auth/AuthenticateSystemUser` (staff), `app/Actions/Customers/ChangeUserType`
- `app/Models/{User, SystemUser}`
- `app/Support/Enums/{UserType, UserStatus, SystemRole, SystemPermission}`, `app/Support/Validation/AccountRules`, `app/Support/Auth/LoginThrottle`
- `app/Http/Middleware/{UseAdminSession, EnsureUserIsActive, EnsureSystemUserIsActive, EnsureEmailIsVerifiedIfRequired}` (aliases `active`, `staff.active`, `verified.optional`; spatie `role`, `permission`)
- Controllers: `Auth/*`, `User/*`, `Admin/{DashboardController, Auth/AdminSessionController}`, `Api/V1/{HealthController, Auth/*}`
- `app/Console/Commands/CreateSystemUserCommand`
- Tests: `tests/Feature/{Auth, User, Admin, Api/V1}`, `tests/Unit/UserPhoneTest`

## Not built (by instruction)
Admin dashboard (Phase 3), services, plans, providers/APIs, wallet, payment gateways, referral, KYC, business API endpoints, cPanel deployment.

## Problems / blockers
1. cPanel PHP 8.3 availability unconfirmed. Check before Phase 20, or earlier if you already have a host.
2. Password-reset and verification emails use `MAIL_MAILER=log` locally. Real SMTP details are needed before launch or before switching verification on.
3. Tests use in-memory SQLite, so `pdo_sqlite` must be enabled locally.
4. Staff have no self-service password reset yet; a Super Admin (Phase 3 UI) or the console command manages staff accounts.
5. The scheduler (`php artisan schedule:run` every minute via cron) must be set up at deployment so expired API tokens are pruned.

## Approved decisions (pre-Phase 3)
1. No Breeze; keep the custom auth. 2. Public sign-up creates Subscribers only. 3. Vendor/Affiliate/API User assigned only by authorized staff. 4. Password reset by email only (no SMS). 5. Email verification configurable, default off. 6. Token expiry configurable, no hard-coded limit. 7. Staff fully separate from customers, 5 roles.

## Missing requirements (needed before the relevant phase)
- Chosen hosting plan and PHP version (Phase 20)
- Provider/API documentation for each service (Phase 7 and 10)
- Gateway accounts and docs for Monnify and Aspfiy (Phase 9)
- Business rules: pricing tiers, commission and referral rates, withdrawal limits and fees (Phases 6, 12, 14)
- Rules for upgrading a customer to Vendor, Affiliate or API User (Phase 3 or 6)
- Confirmed KYC rules per provider (Phase 13)
- SMTP / SMS provider for account emails and OTPs
- Logo and brand assets (Phase 3)
