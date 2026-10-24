# Veterinary Workspace Implementation Plan

**Goal:** Give veterinarians a clinical workspace with server-enforced, read-only Medicine/Vaccine inventory and clinic-only history/payments.

**Architecture:** Extend `User::staffPermissions()` and existing staff middleware. Scope existing inventory queries and formatters for veterinarians, reuse existing page components and identity/cache utilities, and leave clinical stock movement services unchanged.

**Tech Stack:** Laravel 12, PHP, Alpine.js, existing static HTML/Tailwind assets and Node regression harnesses.

**Spec:** User-provided Veterinary Role UI/UX, Inventory Permissions, and Clinic-Scoped Workflows brief (2026-10-24).

## Constraints

- Preserve administrator and receptionist workflows, clinic billing, batch safety and automatic deductions.
- Reuse established design, lazy sections, identity caching, request deduplication and cache invalidation.
- No branch, commit, push, deployment, schema migration or production data changes.
- Browser inspection is visual only; user performs navigation, input, OTP and password testing.

## Review Focus

- Category/barcode/ID/filter manipulation must not widen inventory access.
- Payment type/deep-link manipulation must not reveal grooming or POS records.
- Role changes and account switches must invalidate cached clinical/administrative payloads.
- Read-only settings must hide groomer management and reject mutation APIs.
- Clinical treatments must still deduct stock with expiry/batch validation.

## Tasks

1. **Authorization and inventory** — `app/Models/User.php`, `routes/api.php`, `app/Http/Controllers/InventoryController.php`; extend `tests/Feature/InventorySecurityRegressionTest.php`. First reproduce clinical category leaks and forbidden mutations, then apply scoped queries/serialization and staff permission middleware. Verify inventory/POS and clinic deduction tests.
2. **Dashboard and payments** — existing `AdminBookingController` and `PaymentController`; extend dashboard and transaction feature fixtures. Test clinic-only counts, previews/activity and totals before implementation. Preserve admin/receptionist behavior.
3. **Existing frontend pages** — sidebar, inventory shell/sections, dashboard, archive, transactions and settings components/markup, plus shared API access/cache utilities. Extend existing runtime regression tests for clinical defaults, restricted navigation, filters and cached identity. Preserve lazy loading and existing markup conventions.
4. **Delivery verification** — run relevant PHP and JS suites, build assets, review diff, update asset cache versions and record exact manual checks and redeployment steps. No migrations or new environment settings expected.

## Progress

- [x] Inspect running dashboard and existing permission, query, cache and section-loading architecture.
- [x] Add and reproduce backend/frontend regression coverage.
- [x] Implement permissions, clinical queries and existing UI changes.
- [x] Verify tests, build, changes and deployment documentation.

## Verification

- Affected Laravel suites: 445 tests, 6,085 assertions passed using the documented PHP 8.4 CLI and isolated SQLite fixtures.
- Full frontend regression command (`npm test`): 79/79 scripts passed.
- Production build (`npm run build`): passed; existing outdated Browserslist database warning only.
- Dashboard visually inspected in the ChatGPT browser before and after implementation. Other browser navigation, data input, OTP and password testing remains with the user.
- Delivery details and manual checks: `docs/veterinary-workspace-verification.md`.
