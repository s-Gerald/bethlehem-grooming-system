# Phase 1 cash-only implementation report

Date: October 23, 2026 (Asia/Manila). Scope: active payment cleanup only.

Grooming final payment and Pay Now, Clinic settlement, and POS now expose Cash only. The existing backend cash endpoints reject submitted unsupported methods, including `online` and `qrph`. No QR Ph, OCR, proof upload, replacement provider, database migration, commit, push or deployment was added. Private environment files were not edited.

## Functionality removed

- All checkout creation, provider HTTP calls, public webhook processing, status retrieval/polling, checkout cancellation/expiry, browser redirects and return-query recovery.
- Provider settlement branches in Grooming, Clinic and POS; frozen online-cart repricing/settlement; reservation creation and automatic release.
- Online/QR Ph selectors, checkout/recovery/cancel UI, payment watchers, session-storage persistence, obsolete listeners and inventory initialization hooks.
- API exports, dynamic script dependencies, selector-only POS styles, provider configuration/examples and obsolete operations documentation. Related asset versions and preload URLs were updated together. Generated Tailwind CSS was rebuilt.
- No provider SDK was present in Composer or npm dependencies; manifests and lockfiles remain unchanged.

## Cash functionality preserved

Existing cash totals, server-authoritative prices, Grooming size/price bounds and product add-ons, early-payment workflow, finished-visit release/pickup, Clinic charge validation, POS reviewed-cart validation, tender/change, payment notifications and authenticated processor information remain in place. Inventory sale writes retain ordered row locks, transaction boundaries, expiry/batch checks and atomic stock movements. Duplicate payment/click guards remain.

Invoice templates and print behavior, historical transaction detail rendering, search/filter/pagination, real/test collection separation, and report aggregation remain in place. `admin-transactions.js`, the history/invoice models, InventoryController and historical report implementations were inspected and retained where their remaining behavior is necessary. No new polling, page reloads, data dependencies or per-row queries were introduced. Existing cash mutation/cache invalidation and navigation regression tests pass.

## Historical database compatibility

All migration history, gateway tables, financial columns, references, amounts and timestamps remain intact. `Payment`, `ClinicAppointment` and `PosTransaction` retain historical gateway relationships; reporting keeps historical snapshots and test-mode exclusions. GatewayPayment retains historical metadata casts, labels and read-only unresolved-payment/stock-hold queries. It has no checkout, settlement or reservation-release functionality.

Historical reservations still reduce saleable stock in Inventory, Grooming add-ons, POS and stock movements. Pending historical attempts still block conflicting billing/settlement/correction operations. This is intentionally retained until reconciliation: removing it would increase saleable stock or allow collecting a second payment without establishing the provider outcome. No reservation is released based on age, local status, or removal of the provider integration.

No schema migration is needed. Do not roll back old gateway migrations or remove their tables/columns during this phase.

## Read-only local reconciliation audit

The configured local MySQL database contained one Grooming attempt marked `failed`, in test mode; zero pending or reconciliation-flagged attempts; and zero gateway stock reservations. No record was updated, removed, expired, settled or released. A locally recorded failure was not used as proof of provider failure.

This is a local audit, not a production audit. Before deployment, independently inspect production pending/flagged attempts and every reservation (including orphaned or unexpectedly terminal holds). Match provider session/payment IDs, references, amounts, currency, mode, timestamps and final provider outcome to local transactions. Resolve ambiguous creation, delayed payments, successful payments without local settlement and product stock conflicts through controlled financial/inventory reconciliation before collecting again or releasing a hold. Removed webhook routes cannot settle an old hosted checkout that completes later. Retire provider webhook subscriptions and outstanding hosted sessions only after their outcomes have been established; do not infer failure from this cleanup.

At deployment, rebuild any existing Laravel configuration/route caches using the normal deployment process so cached routes do not reference deleted controllers. No deployment/cache operations were performed here.

## Manual environment cleanup

Remove these variables from private/local/production environment settings yourself:

- `PAYMONGO_SECRET_KEY`
- `PAYMONGO_WEBHOOK_SECRET`
- `PAYMONGO_LIVE_MODE`
- `PAYMONGO_PAYMENT_METHODS`
- `PAYMONGO_RETURN_URL`

## Tests migrated and updated

Dedicated gateway-only tests were deleted after reusable coverage was moved into existing tests:

- `PaymentSchemaCompatibilityTest.php`: all cash endpoints reject unsupported methods without writes; gateway routes absent; historical billing/stock holds; historical POS receipts and test-collection exclusions; shared migration round trip preserves cash sales.
- `GroomingPaymentPriceSafetyTest.php`: early cash payment retains unfinished workflow and prevents duplicate settlement.
- `PaymentAmountValidationTest.php`: explicit manual `online`/`qrph` rejection.
- `admin-grooming-invoice-print-regression.cjs`: final/early cash payloads, tender/change receipts and duplicate-click prevention, alongside existing invoice printing assertions.
- Existing Clinic/Grooming authorization route lists, Clinic cash UI tests, navigation/board initialization tests, data-cache tests, browser fixture route mocks and the regression runner were updated to remove obsolete gateway expectations. Historical report tests remain valid and are retained.

## Validation

- `npm test`: 73/73 regression scripts pass, including cash/POS, Clinic billing, pricing, invoices, transaction details, cache invalidation and navigation performance.
- `npm run build`: passes. Existing Browserslist data-age warning remains; Node reports its usual experimental VM-modules warning.
- Focused PHP cash/history/authorization/report suite: 486 passed, 1 skipped, 7,126 assertions.
- Full `php artisan test --compact`: 1,452 passed, 10 skipped, 1 failed (19,528 assertions); details below.
- Focused inventory/cash/pricing run: 101 passed; same SQLite concurrency failure as the full suite.
- Route registration: 203 routes; none register checkout, payment-status, cancellation or webhook endpoints.
- Static checks: no active frontend provider API calls, checkout URLs, payment return handlers, storage/polling state or script dependencies. All local script references on affected pages exist; changed PHP/JavaScript syntax and diff whitespace checks pass. Remaining provider names are historical reporting/labels, preserved migrations, negative-method/route tests and this report.
- No localhost browser workflows or browser Core Web Vitals measurements were performed. Browser workflow verification remains with the developer as requested.

The initial Phase 1 PHP 8.2 run failed the stock-in concurrency test because its
Laravel SQLite connection did not acquire the requested `IMMEDIATE` writer lock.
That runtime issue was resolved in the subsequent PHP 8.4 validation: the
strengthened stock-in test passes 35 assertions, and the complete suite passes
1,460 tests with eight explicitly reported MySQL-fixture skips and no failures.
A newer-SQLite history rounding discrepancy was also corrected without altering
stored amounts or cash settlement. See [PHP runtime setup, current results and
manual deployment steps](../README.md#php-runtime-and-tests). The original Phase 1
counts above remain a record of that earlier PHP 8.2 run. No browser performance
measurements or localhost browser workflows were performed.

## Files deleted

- `app/Http/Controllers/OnlinePaymentController.php`
- `app/Http/Controllers/PayMongoWebhookController.php`
- `app/Services/OnlinePaymentService.php`
- `app/Services/PayMongoService.php`
- `docs/paymongo-grooming.md`
- `docs/superpowers/plans/2026-10-18-shared-paymongo.md`
- `scripts/components/online-payment.js`
- `tests/Feature/GroomingPayMongoTest.php`
- `tests/Feature/SharedPayMongoTest.php`
- `tests/grooming-paymongo-regression.cjs`
- `tests/online-payment-flows-regression.cjs`
- `tests/online-payment-navigation-regression.cjs`

## Existing files modified

- `.env.example`
- `app/Http/Controllers/PaymentController.php`
- `app/Http/Controllers/PosController.php`
- `app/Models/GatewayPayment.php`
- `app/Services/ClinicPaymentSettlementService.php`
- `app/Services/GroomingPaymentSettlementService.php`
- `app/Services/GroomingProductSaleService.php`
- `app/Services/InventoryStockMovementService.php`
- `app/Services/PosSaleService.php`
- `config/services.php`
- `css/components/admin-pos.css`
- `css/output.css`
- `docs/admin-loading-performance.md`
- `docs/admin-notifications-local-review.md`
- `docs/admin-scoped-fetching-performance.md`
- `docs/performance/admin-navigation-2026-10-19.md`
- `docs/superpowers/plans/2026-10-18-grooming-reports-mysql-compatibility.md`
- `pages/admin/appointments.html`
- `pages/admin/clinic.html`
- `pages/admin/dashboard.html`
- `pages/admin/inventory/inventory.html`
- `pages/admin/inventory/sections/sell-product.html`
- `pages/admin/transactions.html`
- `routes/api.php`
- `scripts/api.js`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-dashboard.js`
- `scripts/components/admin-inventory.js`
- `scripts/components/admin-pos.js`
- `scripts/services/inventory-service.js`
- `tests/Feature/ClinicAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingPaymentPriceSafetyTest.php`
- `tests/Feature/PaymentAmountValidationTest.php`
- `tests/Feature/PaymentSchemaCompatibilityTest.php`
- `tests/admin-clinic-payment-regression.cjs`
- `tests/admin-data-cache-regression.cjs`
- `tests/admin-grooming-board-regression.cjs`
- `tests/admin-grooming-invoice-print-regression.cjs`
- `tests/admin-navigation-performance-regression.cjs`
- `tests/admin-notifications-browser.cjs`
- `tests/admin-sidebar-first-paint-browser.cjs`
- `tests/run-regressions.cjs`

## Documentation added

- `docs/cash-only-cleanup.md` (this report and historical/deployment reconciliation notes).
- `docs/superpowers/plans/2026-10-23-cash-only-cleanup.md` (implementation plan).
