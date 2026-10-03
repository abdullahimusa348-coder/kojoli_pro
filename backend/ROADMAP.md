# ROADMAP: Nadabo Global Data

Rule: build and test everything locally first. cPanel is used only in Phase 20. Each phase needs your approval before it starts.

| # | Phase | Goal | Done when |
|---|---|---|---|
| 1 | Project foundation | Laravel 12 skeleton, tooling, structure, docs | App boots, tests pass, `/up` and `/api/v1/health` respond |
| 2 | Authentication & user foundation ✅ | Session web auth (Breeze-style, custom), spatie roles/permissions, account types (API User, Affiliate, Subscriber, Vendor), Sanctum token auth foundation | Register, login, reset work; roles seeded; auth tests pass |
| 3 | Admin Dashboard foundation ✅ (complete and closed: Step 1 layout, navigation, dashboard shell; Step 2 Settings Store; Step 3 System Users; Step 4 Roles & Permissions; Step 5 Customer Users) | Admin layout, navigation, settings store (config managed in DB), system users | Admin can log in and manage settings without `.env` |
| 4 | User Dashboard foundation ✅ (complete and closed: Step 1 customer layout & navigation; Step 2 dashboard home; Step 3 account page; Step 4 security page; Step 5 closing checks) | Responsive user layout, profile, security settings | Mobile-first dashboard shell works |
| 5 | Services & Categories ✅ (complete and closed: Step 1 Services & Categories catalog foundation; Step 2 Products & Plans structure foundation) | Admin-managed services (Data, Smile Data, NIN, BVN separate) and the Category → Service → Product → Plan structure (no prices) | Services, products and plans toggle on/off from admin |
| 6 | Customer-type pricing / Pricing Engine ✅ (complete and closed: Step 1 Pricing Engine foundation; there is no Step 2) | Per-customer-type pricing for the Phase 5 plans (API User, Affiliate, Subscriber, Vendor) | Price resolves correctly per user type |
| 7 | Providers / API engine (in progress: Provider Engine foundation implemented, awaiting approval) | Provider registry, supported services, encrypted write-only credentials, ordered per-plan provider routes (primary plus any number of fallbacks by priority) with provider plan codes and optional provider cost, and route resolution. No provider API calls, adapters, health checks or failover execution | Provider routes and route resolution configured from admin and covered by tests |
| 8 | Wallet & Transactions | Ledger, balances, idempotent debits and credits, transaction states | Concurrency and rounding tests pass |
| 9 | Payment gateways & points | Gateway interface, Monnify and Aspfiy adapters, webhooks, payment points | Verified webhooks credit the wallet exactly once |
| 10 | Data / Airtime / other services | Data, Airtime, Airtime to Cash, Cable TV, Electricity, Alpha Topup, Bills; provider adapters and live provider calls; automatic failover execution through the Phase 7 routes (retries, timeouts, health checks) | Purchase flow works end to end per service, including failover to the next eligible route |
| 11 | NIN / BVN / Exam Pin / Smile Data | Verification and pin services as separate products | Each service sells and refunds correctly |
| 12 | Referral & Commission | Referral tracking, commission rules, payouts to wallet | Commissions calculate and post correctly |
| 13 | KYC & Virtual Accounts | Configurable verification (phone, BVN, NIN, provider documents), encrypted KYC data, virtual accounts | Sensitive data encrypted and permission-gated |
| 14 | Withdrawals | Withdrawal requests, approvals, limits, fees | Admin approval flow audited |
| 15 | Notifications & Support | Email/SMS/in-app notices, support tickets | Events notify; tickets work both sides |
| 16 | Reports & Audit | Transaction and revenue reports, audit logs | Reports reconcile with ledger |
| 17 | Security hardening | Rate limits, 2FA option, headers, secret handling, penetration checklist | Security checklist signed off |
| 18 | API for mobile application | Sanctum tokens, `/api/v1` endpoints, docs | Mobile client can do all core flows |
| 19 | Full testing | Feature, integration, load and security testing; bug fixing | All suites green, no open critical bugs |
| 20 | Production deployment to cPanel | Build, upload, cron queue worker, SSL, backups, go-live checklist | Live site passes smoke tests |
