# PROJECT STATUS: Nadabo Global Data

Last updated: 2026-10-03 · Stage: **Phase 4 Step 2 complete: Customer dashboard home** (Step 1 also complete; Phase 3 complete and closed). Phase 4 Steps 3–5 not started

## Current state
- Phase 1 foundation installed and verified with `scripts/bootstrap.sh`: Laravel 12.69.3, PHP 8.3.6, Node 22 / npm 10, MariaDB 10.11.
- Phase 2 adds customer authentication, user types, account status, a profile page, a user dashboard shell, and Sanctum token authentication for the future mobile app.
- Pre-Phase-3 changes: staff (System Users) are fully separate from customers, with 5 roles on their own guard; configurable email verification (off); configurable API token expiry. - Phase 3 Step 1: admin layout (sidebar, top bar, mobile drawer, profile menu, logout), dashboard home with foundation cards, and permission-guarded placeholder pages for every planned module.
- Phase 3 Step 2: database-backed Settings Store (typed, cached, optional encryption for future secrets) and a working `/admin/settings` screen. 152 Pest tests pass.
- Phase 3 Step 2 closing check (2026-10-03): desktop (1440px) and mobile (390px) visual check of `/admin/settings`; invalid save shows a summary banner and per-field errors (required name, 3-letter currency, valid timezone) and keeps the typed input; valid save shows "Settings saved." and the values persist after a fresh reload; no JavaScript errors or horizontal scrolling. 152 tests, Pint, build and route/config/view/event cache checks pass.
- Phase 3 Step 3: System Users (staff accounts) management at `/admin/system-users`: list with search and role/status filters, create, edit (optional password change), activate/deactivate, soft delete, one role per staff member, safety rules. 198 Pest tests pass.
- Phase 3 Step 3 closing check (2026-10-03): clean working tree, 198 tests passing, Pint, build and route/config/view/event cache checks pass; Step 3 diff reviewed (no unrelated changes, secrets, customer-view or mobile changes); temporary verification accounts and sessions removed from the local database.
- Phase 3 Step 4: Roles & Permissions management at `/admin/roles` (list with search/type filter, create, edit, delete custom roles) with a permission matrix grouped by 16 modules; 36-permission catalog; custom roles assignable to staff. 241 Pest tests pass.
- Phase 3 Step 5: Customer Users management at `/admin/users`: list with search (name, email, phone in any format, ID) and type/status filters, pagination, safe customer details, enable/disable (revokes API tokens), type change via `ChangeUserType`, profile edit, password-reset email. No deletion. 288 Pest tests pass.
- Phase 3 Step 5 closing check (2026-10-03): clean working tree in sync with GitHub, 288 tests passing, Pint, build and route/config/view/event cache checks pass, all 11 migrations ran; Step 5 diff reviewed (no unrelated changes, secrets, customer-view or mobile changes); no verification customers, staff, tokens, reset tokens, sessions or custom roles left in the local database.
- **Phase 3 closed (2026-10-03):** all roadmap goals met (admin layout and navigation, database settings store, System Users) plus Roles & Permissions and Customer Users; done-when criterion "admin can log in and manage settings without `.env`" met. Final check: clean tree in sync with GitHub, 288 tests passing, Pint, build and route/config/view/event caches pass, 11 migrations ran, `/up`, `/api/v1/health`, `/admin/login` and `/login` return 200, no secrets tracked, no verification data left. Open items carried forward are listed under "Carried forward from Phase 3".
- Phase 4 Step 1: mobile-first customer layout with the temporary text brand "Nadabo Global Data": desktop top bar (Dashboard, Account, account menu), mobile bottom bar (Dashboard, Account, Menu) and menu panel (Security, Email verification when enabled, Log out), all from one list `App\Support\Customer\CustomerNav`. Only implemented pages are listed; no future-module or coming-soon entries. Security points to the password section of the Account page until Step 4 adds a Security page. 306 Pest tests pass.
- Phase 4 Step 2: customer dashboard home at `/dashboard`: welcome by name, account summary (name, account type, status, member since, email verification status), Account and Security shortcuts, same layout for all customer types. Email verification status reads Verified / Not verified (verification on) / Not required (verification off). The verification prompt renders only when verification is on and the email is unverified; with the approved routing those customers are still redirected to `/verify-email` first. No wallet, money, transaction or service content. 325 Pest tests pass. `/up` and `/api/v1/health` return 200.
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
- **Staff auth:** `/admin/login` (email), `POST /admin/logout`
- **Admin dashboard:** `/admin` with cards Total Users (real customer count), Wallet Balance, Today's Sales, Today's Revenue, Pending Withdrawals, and a Recent Transactions panel. Modules without data show 0 / an empty state marked "Not live"; no figures are invented.
- **Admin settings:** `GET /admin/settings` (view, needs `settings.view`), `PUT /admin/settings` (save, needs `settings.view` + `settings.update`). Grouped, validated, success/error feedback.
- **System Users (staff):** `/admin/system-users` (list, search, filter), `/create`, `POST`, `/{id}/edit`, `PUT /{id}`, `PATCH /{id}/status`, `DELETE /{id}`. All need `admin.access` + `system-users.manage`.
- **Roles & Permissions:** `/admin/roles` (`roles.view`), `/create` + `POST` (`roles.create`), `/{id}/edit` (`roles.view`, read-only without `roles.update`), `PUT /{id}` (`roles.update`), `DELETE /{id}` (`roles.delete`). All also need `admin.access`.
- **Users (customers):** `/admin/users` and `/admin/users/{id}` (`customers.view`), `/{id}/edit` + `PUT /{id}` (`customers.update`), `PATCH /{id}/status` (`customers.update-status`), `PATCH /{id}/type` (`customers.change-type`), `POST /{id}/password-reset` (`customers.reset-password`, throttled). All also need `admin.access` + `customers.view`. No delete route.
- **Admin module placeholders (no business logic):** `/admin/{services, transactions, providers, payments, wallet, withdrawals, referrals, notifications, support, reports}`
- **API auth:** `POST /api/v1/auth/token`, `DELETE /api/v1/auth/token`, `GET /api/v1/user`
- **Console:** `php artisan nadabo:create-system-user {email} --role=<super-admin|manager|support|finance|viewer>` (password typed interactively)
- **Customer type changes:** `App\Actions\Customers\ChangeUserType` (requires staff permission `customers.change-type`), used by the admin Users page

## Existing database structure
- Laravel defaults: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`
- `users` extra columns: `phone` (unique, nullable), `user_type` (subscriber, vendor, affiliate, api_user), `status` (active, disabled), `last_login_at`, `last_login_ip`
- `system_users`: staff accounts (name, email, optional unique phone, password, status, last login, `deleted_at` for soft delete). `admin_sessions`: admin-area sessions.
- spatie permission tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (staff only)
- Sanctum: `personal_access_tokens` (customers only)
- `settings`: key (unique), value (text), type (string, text, integer, decimal, boolean, json), group, label, description, is_public, is_encrypted, updated_by (system user), timestamps
- Seeded (`php artisan db:seed`): every catalog permission and the built-in roles (default permissions apply only when a role is first created; later changes made in the admin area are kept; Super Admin always gets every permission), and default settings `app.name`, `app.currency` (NGN), `app.currency_symbol` (₦), `app.timezone` (Africa/Lagos), `app.maintenance_mode` (false). No accounts or credentials are seeded. The settings seeder only adds missing keys, never overwriting staff edits.

## Settings Store
- Read/write through `App\Services\Settings\SettingsStore` (singleton): `get`, `set`, `has`, `forget`, `all`, `group`, plus `publicValues` (public, non-encrypted only). Values are cast by type; decimals stay strings (no precision loss). One cache entry, cleared on every write.
- Known settings, their defaults, labels and extra validation live in `App\Support\Settings\SettingDefinitions`. Credentials are never defined there.
- Sensitive future settings (API keys, SMTP passwords) are marked `is_encrypted`: stored encrypted with APP_KEY, never sent to the browser, blank input keeps the saved value, excluded from `publicValues`.
- Nothing exposes settings to customers or the API.
- Stored values are not yet wired into runtime behaviour (e.g. `app.maintenance_mode`, `app.timezone`); each feature reads them when it is built.

## Authentication and authorization rules
- Login by email or Nigerian phone number (stored as `0XXXXXXXXXX`; `+234…` accepted). Passwords hashed with bcrypt; minimum 8 characters, mixed case and a number.
- 5 failed attempts per login and IP lock that pair for 60 seconds. Sign-up, reset and token endpoints are rate limited.
- Disabled accounts cannot log in. Active sessions and API tokens of a disabled account stop working on the next request.
- Session regenerated on login and invalidated on logout. Password change signs out other devices and revokes API tokens. Password reset revokes API tokens.
- Forgot-password gives the same response whether or not the email exists.
- Public sign-ups are always **Subscriber**. `user_type` and `status` are never mass assignable; only server code or an admin (Phase 3) changes them.
- Customer tier = `users.user_type` (one per user, drives pricing later). Only staff with `customers.change-type` can change it.

## Customer Users management (admin)
- Actions in `App\Actions\Customers`, each re-checking its permission: `ChangeUserType` (type, the only type path), `ChangeCustomerStatus` (the only status path), `UpdateCustomerProfile` (name, email, phone only; a changed email becomes unverified), `SendCustomerPasswordReset` (standard reset email; refused for disabled accounts).
- Disabling a customer revokes all their Sanctum API tokens and ends "remember me" logins; open web sessions are signed out by `EnsureUserIsActive` on the next request; login and new tokens are refused. Re-enabling does not restore old tokens.
- Details page shows safe fields only (name, email and verification, phone, type, status, joined, last login, number of API tokens). Passwords, hashes, remember/API tokens and last-login IP are never shown.
- Customers are never deleted (no route, no `customers.delete` permission); disable instead, so future wallet, transaction and referral records stay intact.
- No audit log yet (Phase 16); changes are reflected only in timestamps.
- Email verification: `NADABO_REQUIRE_EMAIL_VERIFICATION` (default false). Off: no emails, nobody blocked. On: verification email on sign-up and email change; unverified customers are sent to `/verify-email` (profile stays reachable).
- API tokens: lifetime from `SANCTUM_TOKEN_EXPIRATION` in minutes (empty/0 = no expiry); `expires_at` returned with each token; expired tokens pruned daily by the scheduler.

## Staff (System Users)
- Separate `system_users` table, `SystemUser` model, `admin` session guard. Customer accounts can never sign in at `/admin/login`; staff accounts can never sign in at `/login`.
- Separate session: cookie `nadabo_admin_session` limited to `/admin`, stored in `admin_sessions`. Signing out of one area does not affect the other.
- Separate login throttling (5 failures per email + IP).
- Disabled staff, or staff without a role, cannot sign in; a disabled account is signed out on its next request.
- Roles and permissions (spatie, `admin` guard). Super Admin passes every check while active.

## Roles & Permissions
- Permission catalog: `App\Support\Enums\SystemPermission` (38 permissions, `<module>.<action>`), grouped by `App\Support\Permissions\PermissionModule` (16 modules: Dashboard, System Users, Roles & Permissions, Settings, Users, Wallet, Services, Providers, Payments, Transactions, Withdrawals, Referral & Commission, Notifications, Support, Reports, Audit / Activity Logs). Modules not built yet have permissions defined so roles can be prepared; they take effect when the module is built. Routes use `SystemPermission::X->middleware()`.
- Built-in roles: Super Admin, Manager, Support, Finance, Viewer. Custom roles can be created. Each staff member has one role and gets only that role's permissions.
- Safety rules (`App\Actions\Admin\Roles\RoleRules`, enforced even for Super Admin): the Super Admin role is locked (always every permission, cannot be edited or deleted); built-in role names are fixed and built-in roles cannot be deleted; a role still held by any staff member (including soft-deleted) cannot be deleted; staff other than Super Admin cannot edit the role they hold and can only grant or remove permissions they hold themselves. The last-active-Super-Admin protection from System Users still applies.
- Limitation: spatie's `roles` table has no status, so roles cannot be enabled/disabled. To retire a custom role, move its staff to another role and delete it.
- Management (`/admin/system-users`, permission `system-users.manage`, Super Admin only by default): one role per staff member; passwords set by the admin are hashed and never shown (edit leaves password blank to keep it). Safety rules in `App\Actions\Admin\SystemUsers\SystemUserRules`, enforced even for Super Admin: no self-deactivation, self-deletion or own-role change; the last active Super Admin cannot be deactivated, deleted or demoted; only a Super Admin can grant the Super Admin role or change a Super Admin account.
- Delete is a soft delete: the account is disabled, hidden from the list, cannot sign in (an open session ends on the next request), and its email stays reserved. There is no restore screen yet.
- No audit/activity log exists yet (Phase 16); staff-account changes are not recorded beyond timestamps.

| Permission | Super Admin | Manager | Support | Finance | Viewer |
|---|---|---|---|---|---|
| `admin.access` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.update-status` | ✓ | ✓ | ✓ | | |
| `customers.change-type` | ✓ | ✓ | | | |
| `customers.update` (edit details) | ✓ | ✓ | | | |
| `customers.reset-password` | ✓ | ✓ | | | |
| `system-users.manage` | ✓ | | | | |
| `settings.view` | ✓ | | | | |
| `settings.update` | ✓ | | | | |
| `roles.view` / `roles.create` / `roles.update` / `roles.delete` | ✓ | | | | |
| All other catalog permissions (future modules) | ✓ | | | | |

These are the default grants; staff with `roles.update` can change them in the admin area.

Admin navigation (`App\Support\Admin\AdminModule`): each sidebar item and its page require the same permission. Dashboard = `admin.access`; Users = `customers.view`; Settings = `settings.view`; System Users = `system-users.manage`; Roles & Permissions = `roles.view`. Every other module uses its `<module>.view` catalog permission (`services.view`, `transactions.view`, `providers.view`, `payments.view`, `wallet.view`, `withdrawals.view`, `referrals.view`, `notifications.view`, `support.view`, `reports.view`), stored but not granted to any built-in role by default, so only Super Admin sees those modules and the financial dashboard cards until a role is given access. Result today: Super Admin sees all 15 items and all cards; Manager, Support, Finance and Viewer see Dashboard and Users, and only the Total Users card.

## Existing frontend structure
- `resources/css/theme.css` (navy/blue tokens), `resources/css/app.css`, `resources/js/app.js` (Alpine)
- Layouts: `layouts/base` (root), `layouts/guest` (auth card), `layouts/app` (signed-in shell with mobile menu)
- Components: `x-input`, `x-button`, `x-alert`
- Layouts also include `layouts/admin` (staff area: sidebar, top bar, mobile drawer, profile menu) with partials `admin/partials/{sidebar, topbar}`
- Customer layout `layouts/app` (Phase 4): sticky top bar with text brand, desktop links and account menu; mobile bottom navigation and menu panel; partials `layouts/partials/customer/{brand, avatar, menu-items}`; navigation from `App\Support\Customer\CustomerNav` (add future customer pages there in their own phase)
- Views: `auth/*` (incl. `verify-email`), `user/dashboard`, `user/profile`, `admin/auth/login`, `admin/dashboard`, `admin/placeholder`, `admin/settings/{index, field}`

## Existing backend structure
- `app/Actions/Auth/{RegisterUser, AuthenticateUser}` (customers, web and API), `app/Actions/Admin/Auth/AuthenticateSystemUser` (staff), `app/Actions/Customers/ChangeUserType`
- `app/Models/{User, SystemUser}`
- `app/Support/Enums/{UserType, UserStatus, SystemRole, SystemPermission}`, `app/Support/Validation/AccountRules`, `app/Support/Auth/LoginThrottle`
- `app/Http/Middleware/{UseAdminSession, EnsureUserIsActive, EnsureSystemUserIsActive, EnsureEmailIsVerifiedIfRequired}` (aliases `active`, `staff.active`, `verified.optional`; spatie `role`, `permission`)
- Controllers: `Auth/*`, `User/*`, `Admin/{DashboardController, Auth/AdminSessionController}`, `Api/V1/{HealthController, Auth/*}`
- `app/Support/Admin/AdminModule` (admin navigation registry), `app/Services/Admin/DashboardMetrics`, `app/Support/Money`
- `app/Http/Controllers/Admin/{DashboardController, ModulePlaceholderController, SettingsController, SystemUserController}`
- System Users: `app/Actions/Admin/SystemUsers/{SystemUserRules, CreateSystemUser, UpdateSystemUser, ChangeSystemUserStatus, DeleteSystemUser}`, `app/Http/Requests/Admin/SystemUsers/*`, views `admin/system-users/{index, create, edit, form}`
- Customer Users: `app/Http/Controllers/Admin/CustomerController`, `app/Actions/Customers/{ChangeUserType, ChangeCustomerStatus, UpdateCustomerProfile, SendCustomerPasswordReset}`, `app/Http/Requests/Admin/Customers/UpdateCustomerRequest`, views `admin/users/{index, show, edit}`; migration `2026_10_03_100000_grant_customer_management_permissions_to_manager` (one-time grant of the two new permissions to an existing Manager role)
- Roles & Permissions: `app/Http/Controllers/Admin/RoleController`, `app/Actions/Admin/Roles/{RoleRules, CreateRole, UpdateRole, DeleteRole}`, `app/Http/Requests/Admin/Roles/RoleRequest`, `app/Support/Permissions/PermissionModule`, views `admin/roles/{index, create, edit, matrix}`
- Settings: `app/Models/Setting`, `app/Services/Settings/SettingsStore`, `app/Support/Enums/SettingType`, `app/Support/Settings/SettingDefinitions`, `app/Actions/Settings/UpdateSettings`, `app/Http/Requests/Admin/UpdateSettingsRequest`, `database/seeders/SettingsSeeder`
- `app/Console/Commands/CreateSystemUserCommand`
- Tests: `tests/Feature/{Auth, User, Admin, Api/V1}`, `tests/Unit/UserPhoneTest`

## Not built (by instruction)
Business modules: services, plans, providers/APIs, wallet, payment gateways, referral, KYC, business API endpoints, cPanel deployment.

## Problems / blockers
1. cPanel PHP 8.3 availability unconfirmed. Check before Phase 20, or earlier if you already have a host.
2. Password-reset and verification emails use `MAIL_MAILER=log` locally. Real SMTP details are needed before launch or before switching verification on.
3. Tests use in-memory SQLite, so `pdo_sqlite` must be enabled locally.
4. Staff have no self-service password reset yet; a Super Admin (System Users page) or the console command manages staff passwords.
5. The scheduler (`php artisan schedule:run` every minute via cron) must be set up at deployment so expired API tokens are pruned.

## Carried forward from Phase 3 (not blocking)
1. Logo and brand assets: still needed from the business; apply when provided.
2. Business rules for upgrading customers to Vendor, Affiliate or API User (fees, approval): staff can change type now; rules due by Phase 6.
3. Staff self-service password reset (email): not built; staff passwords are managed by a Super Admin in System Users or the console command.
4. Audit log of staff actions: planned for Phase 16; changes are reflected only in timestamps until then.

## Approved decisions (pre-Phase 3)
1. No Breeze; keep the custom auth. 2. Public sign-up creates Subscribers only. 3. Vendor/Affiliate/API User assigned only by authorized staff. 4. Password reset by email only (no SMS). 5. Email verification configurable, default off. 6. Token expiry configurable, no hard-coded limit. 7. Staff fully separate from customers, 5 roles.

## Missing requirements (needed before the relevant phase)
- Chosen hosting plan and PHP version (Phase 20)
- Provider/API documentation for each service (Phase 7 and 10)
- Gateway accounts and docs for Monnify and Aspfiy (Phase 9)
- Business rules: pricing tiers, commission and referral rates, withdrawal limits and fees (Phases 6, 12, 14)
- Rules for upgrading a customer to Vendor, Affiliate or API User (Phase 6)
- Confirmed KYC rules per provider (Phase 13)
- SMTP / SMS provider for account emails and OTPs
- Logo and brand assets (carried forward from Phase 3)
