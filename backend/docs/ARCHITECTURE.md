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
2. Each plan has its own ordered provider routes (Phase 7): a primary (priority 1) and any number of fallbacks by priority, unique per plan, each with the provider's own plan code and an optional provider cost. Providers are independent of the catalog and list the services they support. `RouteResolver` only lists routes in order with eligible/skipped reasons; trying them and failing over is built in Phase 10. Ratel and Bangansuba are examples of future providers, created by an administrator; none are seeded.
3. Each plan has separate prices for API User, Affiliate, Subscriber and Vendor.
4. Payment gateways sit behind one interface so several can run side by side (Monnify, Aspfiy). Nothing is integrated yet.
5. NIN and BVN are separate customer-facing services. Data and Smile Data are separate services.
6. Virtual account/KYC requirements are configurable (phone, BVN, NIN, provider-required documents). An ID card is never assumed.
7. Sensitive KYC data is encrypted at rest and visible only to permitted roles.

## Engineering conventions
- Money: integer minor units (kobo), never floats.
- Wallet and transactions (Phase 8): see "Wallet engine" below. Idempotency keys on every money operation, purchase and webhook.
- Customers (`users`, `User`, `web` guard, Sanctum tokens) and staff (`system_users`, `SystemUser`, `admin` guard) are separate account systems. The admin area has its own session cookie (path `/admin`) and session table, set by `UseAdminSession`. Customer routes use `auth:web`; admin routes use `auth:admin`.
- Customer tier is `users.user_type` (`App\Support\Enums\UserType`), changed only through `ChangeUserType` by staff with `customers.change-type`. Customers never hold roles.
- Staff access is spatie roles/permissions on the `admin` guard (`SystemRole`, `SystemPermission`; Super Admin via `Gate::before`). Add staff abilities as `SystemPermission` cases and map them in `SystemRole::permissions()`, then re-run the seeder.
- Authentication: custom session controllers (Breeze-style). Customers sign in through `AuthenticateUser` (web and API), staff through `AuthenticateSystemUser`; both use `LoginThrottle` with separate counters.
- Configurable: email verification (`NADABO_REQUIRE_EMAIL_VERIFICATION`), API token lifetime (`SANCTUM_TOKEN_EXPIRATION`).
- Audit logs for admin and money-moving actions (Phase 16).
- Never log secrets, tokens, BVN/NIN or card data.
- Provider credentials: encrypted at rest (Laravel encryption, `APP_KEY`; keep `APP_PREVIOUS_KEYS` when rotating the key), write-only in the admin, hidden from serialization, never flashed as old input, logged or shown (only a last-four hint for values of 8+ characters). Every set, replacement and clear is recorded without the value.

## Wallet engine (Phase 8)
- **Structure:** one wallet per customer per wallet type (`wallets`, unique customer + type). Phase 8 has only the `main` type; the same generic engine serves Subscribers, Vendors, Affiliates and API Users. Currency NGN, integer kobo, status active or frozen (frozen: credits allowed, debits blocked). No holds: available balance = current balance.
- **Three records per operation:** `transactions` (what the customer sees: TXN reference, type, direction, amount, status, idempotency key), `wallet_ledger_entries` (append-only money movement: WLE reference, direction, positive amount, `balance_after_kobo`, entry type, staff creator) and the cached `wallets.balance_kobo`. Purchases, provider attempts, payments and withdrawals get their own tables in later phases and link to a transaction.
- **Ledger invariants:** entries are never updated or deleted (model guards, no routes); corrections are compensating `reversal` entries (`reverses_entry_id` is unique, so an entry is reversed at most once); for every wallet, cached balance = sum(credits) − sum(debits) = latest `balance_after_kobo`, and every entry's `balance_after_kobo` equals the running total. `php artisan wallet:verify` checks this and only reports; it never repairs.
- **Cached balance:** written only by `App\Services\Wallet\WalletService`, in the same database transaction as the ledger entry and transaction row. The column is unsigned (a negative balance is impossible at database level) and capped at `WalletService::MAX_BALANCE_KOBO`.
- **Concurrency:** every operation runs in `DB::transaction(..., 3)` (deadlock retry) and locks the wallet row with `SELECT … FOR UPDATE` before reading the balance, so operations on one wallet happen one at a time; no overdraft is possible. Future multi-wallet operations (transfers, payouts) must lock wallets in ascending id order. Proven by the MariaDB concurrency suite (`php artisan test -c phpunit.concurrency.xml`, database `nadabo_concurrency_test`): parallel processes cannot double-spend or lose updates, and removing the lock makes those tests fail.
- **Idempotency:** each transaction may carry a key, unique per customer. A repeated key with the same wallet, type, direction and amount returns the original result (nothing posted again); a reused key with different parameters is refused. The unique index is the last safety net for parallel retries. Admin forms use a one-time token; future purchase/API requests will use a client `Idempotency-Key`, and webhooks the gateway reference.
- **Statuses:** transaction pending → successful | failed, successful → reversed; anything else is rejected. Ledger entries have no status. Phase 8 adjustments are successful immediately.
- **Pricing and providers:** the wallet never calculates prices. Future purchases (Phase 10): `PriceResolver` quote → transaction + wallet debit (idempotent) → `RouteResolver` → provider attempts (route, provider code and cost snapshots) → success, or a refund credit entry and status failed. Margin = selling price − cost of the successful attempt, recorded on the purchase.
- **Deferred:** deposits/gateways/webhooks (9), purchases, provider attempts and refunds (10), commissions/cashback/extra wallet types (12), withdrawals and holds (14), maker-checker approval and general audit/reports (16), customer wallet API (18), customer transfers (later).

