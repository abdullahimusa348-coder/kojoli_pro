# Architecture

## Layers
- **Controllers** (`app/Http/Controllers/{Admin,User,Api/V1}`): thin. Validate (Form Requests), call an Action, return a view or resource.
- **Actions** (`app/Actions`): one business operation each, e.g. a single purchase or a single wallet credit.
- **Services** (`app/Services`): shared capabilities that Actions use (wallet ledger, provider engine, pricing, KYC, notifications).
- **Support** (`app/Support`): small helpers, value objects, enums.
- Web and the mobile API call the **same Actions**, so rules live in one place.

## Routing
- `routes/web.php`: web UI. `routes/api.php` mounts `routes/api/v1.php` under `/api/v1`. A future v2 gets its own file.

## Frontend
- Blade layouts in `resources/views/layouts`; areas `public`, `user`, `admin`; reusable parts in `components`.
- Tailwind tokens in `resources/css/theme.css` (navy, brand blue, white). Mobile-first. Alpine.js for small interactions.

## Runtime choices (cPanel compatible)
- Sessions, cache and queue use the database driver. No Redis dependency.
- Queue worker will run via cPanel cron (`queue:work --stop-when-empty`) at deployment time.

## Planned domain rules (not built yet)
1. Every normal business setting is managed from the Admin Dashboard, not cPanel or `.env`. `.env` holds only infrastructure and secrets.
2. Each product/plan has its own provider: one primary and an optional fallback.
3. Each plan has separate prices for API User, Affiliate, Subscriber and Vendor.
4. Payment gateways sit behind one interface so several can run side by side (Monnify, Aspfiy). Nothing is integrated yet.
5. NIN and BVN are separate customer-facing services. Data and Smile Data are separate services.
6. Virtual account/KYC requirements are configurable (phone, BVN, NIN, provider-required documents). An ID card is never assumed.
7. Sensitive KYC data is encrypted at rest and visible only to permitted roles.

## Engineering conventions
- Money: integer minor units (kobo), never floats.
- Wallet and transactions: append-only ledger, DB transactions with row locking, idempotency keys on every purchase and webhook.
- Customers (`users`, `User`, `web` guard, Sanctum tokens) and staff (`system_users`, `SystemUser`, `admin` guard) are separate account systems. The admin area has its own session cookie (path `/admin`) and session table, set by `UseAdminSession`. Customer routes use `auth:web`; admin routes use `auth:admin`.
- Customer tier is `users.user_type` (`App\Support\Enums\UserType`), changed only through `ChangeUserType` by staff with `customers.change-type`. Customers never hold roles.
- Staff access is spatie roles/permissions on the `admin` guard (`SystemRole`, `SystemPermission`; Super Admin via `Gate::before`). Add staff abilities as `SystemPermission` cases and map them in `SystemRole::permissions()`, then re-run the seeder.
- Authentication: custom session controllers (Breeze-style). Customers sign in through `AuthenticateUser` (web and API), staff through `AuthenticateSystemUser`; both use `LoginThrottle` with separate counters.
- Configurable: email verification (`NADABO_REQUIRE_EMAIL_VERIFICATION`), API token lifetime (`SANCTUM_TOKEN_EXPIRATION`).
- Audit logs for admin and money-moving actions (Phase 16).
- Never log secrets, tokens, BVN/NIN or card data.
