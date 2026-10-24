# QR Ph-only PayMongo alongside manual QR/OCR and Cash — PHP 8.4

## Hostinger readiness inspection — October 24, 2026

The existing application already supports the requested Hostinger-only public payment backend. **No application, frontend, configuration-default, schema or dependency change was necessary.** Keep Hostinger checkout creation, webhook settlement and payment records on Hostinger; keep localhost independent. The remaining work is owner configuration, release and integration testing, detailed in [What I Need to Do Manually](#what-i-need-to-do-manually).

Inspected both provider/shared services, the webhook and payment controllers, environment configuration, API routes, API origin resolution, shared return watcher and its Grooming/Clinic/POS consumers, settlement/reservation services, gateway migrations/model and existing payment regressions. Findings:

| Concern | Current implementation |
| --- | --- |
| Public webhook | `POST /api/paymongo/webhook`: `bootstrap/app.php` supplies the `/api` prefix to `routes/api.php`; signature verification replaces staff authentication for this route. No `/public` or `/index.php` prefix is required when the domain routes to Laravel's public entry point. |
| Return configuration | Explicit `PAYMONGO_RETURN_URL` wins; otherwise `FRONTEND_URL`, then `APP_URL`, plus `/pages/admin/appointments.html`. A copied explicit localhost value continues to win until corrected. |
| Other return pages | Clinic: `/pages/admin/clinic.html`; POS: `/pages/admin/inventory/inventory.html#sell-product`. Both derive the origin/subdirectory from the Grooming anchor and include recovery identifiers. |
| Cached configuration | Payment services/controllers consume `config()`, not runtime `env()` reads. Isolated tests booted both uncached and from serialized cached configuration with environment payment values removed on the cached boot. |
| Frontend backend selection | `scripts/api.js` uses the website's own origin plus `/api` on HTTPS deployment, while preserving the separate localhost backend. No Hostinger override is needed. |
| QR Ph and Test Mode | With `PAYMONGO_PAYMENT_METHODS=qrph`, every new context snapshots and sends exactly `["qrph"]`. Saved methods govern webhook acceptance. Both live mode and live keys remain rejected; historical method compatibility remains intact. |
| Financial safety | Only a verified matching paid webhook settles; return/status cannot. Existing locks, unique identifiers, reservations, expiry checks and reconciliation remain unchanged. |
| Queues | The webhook settles synchronously. It does not need a new payment worker, scheduler, tunnel, proxy or synchronization service. |

The documented public URL is `https://darkorchid-horse-104804.hostingersite.com/api/paymongo/webhook`. **Its path matches the repository route, conditional on the existing domain routing to this Laravel application.** Public read-only verification could not establish the deployed route: the web reader could not access it and the shell reported DNS resolution failure. This does not establish a public outage, and no authenticated Hostinger or PayMongo account state was inspected. Endpoint registration, enabled state, matching signing secret, deployed revision, HTTPS routing and real delivery remain unverified. The previous unsupported claim that the endpoint was already registered has been removed.

Changes are limited to this guide and the two existing PayMongo PHP test files. Test fixtures now use the actual Grooming page structure, assert exact success/cancel payload destinations in all three contexts, and cover explicit URL precedence, frontend/app fallbacks, subdirectory preservation, localhost separation, cached methods/mode and cached signature acceptance/tamper rejection. No obsolete application code was proven unused; none was removed. No runtime requests, queries, polling, caching or UI behavior changed.

Current verification, separate from the historical results below:

| Check | Result |
| --- | --- |
| Baseline PayMongo suites | 75 tests, 564 assertions passed |
| Final relevant PHP 8.4 suites and Clinic/POS Cash checks | **192 tests, 2,213 assertions passed; no failures or skips** |
| Relevant frontend regressions | **9 scripts passed**, including real local OCR/PDF, QR image decoding, Cash/POS, return recovery, bounded polling, scoped cache invalidation and transaction labels |
| Changed PHP syntax / diff whitespace | Passed |

PHP suites: `GroomingPayMongoTest`, `SharedPayMongoTest`, `PaymentSchemaCompatibilityTest`, `QrPaymentSettingsTest`, `PaymentAmountValidationTest`, `PaymentAmountLimitTest`, `GroomingProductSaleTest`, `GroomingPaymentPriceSafetyTest` (188 tests), plus four targeted existing Clinic/POS Cash tests (receipt/processor, invalid tender, server price/change and limits). All provider HTTP is mocked; financial writes use in-memory/owned disposable SQLite databases. The first PHP invocation was blocked by Windows sandbox `is_readable()` checks; the same tests passed outside the sandbox. New cache tests initially exposed two test-fixture issues (Windows absolute-path recognition and an incorrect initial cache-state assertion), corrected without production changes. One frontend invocation used the wrong transaction test filename; the existing `admin-transactions-details-regression.cjs` was then run and passed. No failing assertions were weakened.

Logs: `storage/logs/hostinger-paymongo-{baseline,config,php-tests,cash-tests,frontend-tests,transaction-details}.log`. The frontend log retains the initial missing-filename invocation; the separate transaction-details log records its corrected pass. OCR fixtures emit the existing experimental VM-module/PDF warnings. Full PHP/frontend suites, build and opt-in MySQL tests were not rerun for these test/documentation-only changes. The historical unrelated archive query-budget failure below remains outside this verification. No real payment, application database mutation, private `.env` edit, production migration, commit, push, deployment or Hostinger setting change was performed.

## QR Ph-only refinement — October 24, 2026

`PAYMONGO_PAYMENT_METHODS=qrph` already worked: the restored shared checkout service passed the configured array to Hosted Checkout. No provider restoration or checkout rewrite was needed. The fallback and `.env.example` now also default to `qrph`. Effective local configuration was checked read-only: methods `["qrph"]`, live mode `false`, localhost appointments return URL, test-key and signing-secret presence confirmed without printing credentials; configuration is not cached.

New early/final Grooming, Clinic and POS attempts save `payment_methods` in their existing snapshots, then send that exact list as `payment_method_types`. A signed payment source must match the saved list, so a new QR Ph-only attempt cannot settle through GCash, Maya or Card. Historical snapshots without a list retain the supported historical channels and Maya alias. Saved lists remain authoritative after configuration changes. Invalid new-checkout method settings cannot block authenticated retrieval/expiry of existing sessions; credential/environment checks remain enforced.

Hosted checkout remains labelled exactly **Online Payment**, including Clinic/POS receipts and transaction history. The redundant raw provider-method row was removed from transaction details. Provider identifiers and actual source methods remain stored for audit/reconciliation. Cash and manual QR/OCR implementation, receiving-provider labels, optional upload, private proof storage, verification and reference handling are unchanged. No schema, dependencies, private environment, caches, polling, frontend API calls or financial query counts changed.

The official [Hosted Checkout quick start](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start) uses `payment_method_types: ["qrph"]` and webhook payment confirmation. [QR Ph](https://docs.paymongo.com/docs/payment-acceptance-qr-ph) works with participating bank/e-wallet apps; those apps are not separate direct checkout channels. A QR code expiring does not establish checkout-session expiry, and browser returns never settle payments.

Verification for this refinement (separate from the historical restoration results below):

| Check | Result |
| --- | --- |
| Baseline restored payment suites | 51 tests, 363 assertions passed |
| Focused new regressions before production fixes | Expected failures for missing method snapshots/enforcement, suffixed labels, malformed configuration and blocked historical recovery |
| Payment, manual QR, cash amounts, schema, pricing, history and financial reporting suites | **206 tests, 2,313 assertions passed; zero failures/skips** |
| Existing frontend regression runner, including actual local OCR/PDF and bounded payment navigation | **78/78 scripts passed** |
| Production build, changed PHP 8.4 lint, JavaScript syntax and diff whitespace | Passed |

The existing GroomingPayMongoTest, SharedPayMongoTest, PaymentSchemaCompatibilityTest, online-payment-flows and transaction-details regression files were updated; no duplicate regression file was added. New coverage includes historical QR Ph/GCash/Maya/Card settlement, exact labels and preserved provider data, method mismatch rejection before stock deduction, configuration changes, and invalid settings without financial side effects. Existing signature, identity, amount/currency, idempotency, concurrent webhook/Cash/manual QR, reservation, cancellation, expiry, recovery and test-revenue checks passed. Logs: `storage/logs/qrph-{red,green,php-tests,frontend-tests,build}.log`.

The unrelated archive query-budget assertion was not changed or rerun; its prior 14-versus-13 failure remains documented below. The full PHP suite and opt-in MySQL tests were not rerun for this focused refinement. Build output retains the existing outdated Browserslist notice; frontend output retains VM-module/PDF fixture warnings. No provider requests or real payment records were created. No commits, pushes, migrations against an application database, or deployment actions were performed. Real browser checkout and webhook delivery remain user-run checks.

## Restoration history — October 23, 2026

Implementation date: October 23, 2026. Branch: `gerald-frontend`; starting HEAD: `bd45f36`.
Historical references: `f572dfaa779d` and removal `fba4feac3434c4c13c268708ddf1255e7642552a`.

## Recovered functionality

| Area | Supported behavior |
| --- | --- |
| Grooming | Early and final hosted checkout, server pricing/size validation, frozen service/product summary, return recovery, existing success receipt and workflow transitions |
| Clinic | Checkout from finalized database charges, fractional charge handling, payment permissions, case/billing holds, signed settlement and existing invoice |
| Inventory/POS | Checkout from validated server cart, cashier-owned UUID retries, stock reservations, frozen prices/names, atomic sale/stock deduction and existing receipt |
| All contexts | Authenticated status/cancellation, signed public webhook, idempotent settlement, test-mode labels/collection exclusion, reconciliation flags |

Historical payment code was recovered in selected methods/removal hunks and adapted to the current implementation. No rollback, branch change, commit, push or deployment was performed. The starting working tree was clean.

## Independent payment methods

- **Cash:** existing tender/change validation and settlement.
- **QR Payment:** existing Grooming static clinic QR; GCash/Maya/bank/other configured receiving providers, optional receipt, local OCR, reference correction and uniqueness, discrepancy checks and mandatory staff receiving-account acknowledgment. Receipts remain private with existing upload safeguards. OCR and PDF processing remain lazy loaded. No QR services, migrations or OCR implementation were replaced.
- **Online Payment:** PayMongo-hosted checkout; no receipt upload, OCR, manual reference or account acknowledgment. QR Ph inside PayMongo belongs to this method. Clinic and POS recover their previous Cash/Online choices; this change does not add manual QR settlement to those contexts.

The existing payment modal is reused. A pending checkout locks competing settlement/corrections until safe provider-confirmed cancellation/expiry. A paid booking cannot be settled again. New records use `payment_method=online` and gateway metadata; manual QR continues using `qrph` and `qr_payment_id`. Legacy provider-method values remain readable; gateway-backed QR Ph records display Online Payment instead of the manual clinic QR label.

## Provider protocol and security

Creation uses the server's frozen amount, payable, reference, metadata and line items through `/v2/checkout_sessions`, with `pass_on_fees=false`. Retrieval and explicit expiry use the documented `/v1/checkout_sessions/{id}` endpoints. Current official guidance recommends V2 creation; see [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout).

Only `checkout_session.payment.paid` can settle. Raw-body HMAC-SHA256 verification uses the test signature (`te`), webhook secret and a five-minute timestamp tolerance. The handler validates the signed environment, any supplied nested environment flags, session/payment/event identities, PHP currency, integer cent amount, payable metadata and the saved snapshot. New attempts also require the exact merchant reference and context. Older Grooming metadata retains its historical compatibility allowance. See [webhook security](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management) and [event envelopes](https://docs.paymongo.com/docs/developer-tools-webhooks-events).

The official event-resource envelope and the historical `send.webhook` wrapper are accepted. Abbreviated official examples can omit nested mode flags: the authenticated event's environment remains mandatory, and any nested flag that is present must agree. Browser redirects, GET status responses, screenshots and manual references cannot settle PayMongo payments.

Payable/attempt locks and existing unique provider indexes serialize settlement, cancellation and duplicate events. External HTTP requests happen outside financial transactions. A snapshot/hold is committed before the one creation request; an interrupted/ambiguous creation cannot create another chargeable session on retry. Definitive rejection releases reservations; timeouts, server errors and ambiguous responses retain them.

Sessions do not expire automatically. A cancel redirect does not cancel a session. Holds are released only after a matching, same-environment expired resource without unresolved/successful nested payments or intents. See the [Checkout Session resource](https://docs.paymongo.com/reference/checkout-session-resource). A failed individual payment does not make the whole checkout terminal, because checkout can allow another attempt.

Grooming products and POS stock are reserved under sorted item locks. A signed payment with insufficient/missing reserved stock retains the confirmed gateway charge and reconciliation flag; application sale/payment/stock writes roll back together. Delayed confirmation after a locally closed checkout or payment through another channel is recorded for reconciliation rather than silently fulfilling again. Paid attempts needing reconciliation continue blocking competing actions. These conditions require administrator investigation against provider records; no automatic override/refund or new reconciliation UI was added.

**Test Mode only:** both `PAYMONGO_LIVE_MODE=true` and live secret keys are rejected. Tests never contact PayMongo or create real charges. Test gateway collections remain excluded from real totals. Test checkouts still exercise booking and inventory workflows, so manually test only disposable development records.

## Performance

No new requests run during ordinary dashboard navigation. Active payment modals check for an existing hold. Refresh/navigation recovery uses the saved attempt/return identifiers. The shared watcher makes at most 15 checks, four seconds apart, then offers Check Status. It prevents overlapping checks and discards late responses after close/cancellation. Modal close, section leave, component destruction and page navigation stop polling. A hidden page stops automatic checking; Check Status resumes it.

Online creation/cancellation invalidates the affected inventory availability; confirmed settlement reuses targeted cash-settlement cache invalidation for history/notifications. Pending GET responses do not invalidate unrelated presentation caches. Existing pagination, reporting filters, lazy inventory sections and OCR/PDF loading remain intact.

## Files changed

| Files | Purpose |
| --- | --- |
| `app/Services/PayMongoService.php`, `OnlinePaymentService.php` | HTTP, signature verification, snapshot lifecycle, shared lock order, safe expiry and response helpers |
| `app/Http/Controllers/PayMongoWebhookController.php`, `OnlinePaymentController.php` | Signed settlement, Clinic/POS creation/status/cancel and ownership |
| `app/Http/Controllers/PaymentController.php` | Grooming checkout preparation/recovery, frozen summary and historical Clinic labels; existing QR settlement retained |
| `app/Services/GroomingPaymentSettlementService.php`, `ClinicPaymentSettlementService.php`, `PosSaleService.php`, `GroomingProductSaleService.php`, `InventoryStockMovementService.php` | Reuse existing settlement/stock logic, gateway audit associations, frozen product pricing and current reservation messages |
| `app/Models/GatewayPayment.php`, `Payment.php`, `PosTransaction.php`, `ClinicAppointment.php`; `app/Http/Controllers/PosController.php` | Relationships, unresolved/reconciliation holds, stock helpers and distinguishable historical labels |
| `routes/api.php`, `config/services.php`, `.env.example` | Restore routes inside existing role/permission groups and test-mode configuration |
| `scripts/api.js`, `scripts/services/inventory-service.js` | API wrappers and scoped availability/settlement invalidation |
| `scripts/components/online-payment.js`, `admin-dashboard.js`, `admin-clinic.js`, `admin-pos.js`, `admin-inventory.js` | Shared bounded watcher, existing modal workflows, snapshot recovery and cleanup |
| `pages/admin/appointments.html`, `clinic.html`, `inventory/inventory.html`, `inventory/sections/sell-product.html`; `css/components/admin-pos.css` | Existing payment controls/state actions, three Grooming choices, asset cache versions and restored POS option styles |
| `tests/Feature/GroomingPayMongoTest.php`, `SharedPayMongoTest.php` | Recovered/adapted historical tests plus signature/metadata/race/expiry/reservation regressions |
| Existing `PaymentSchemaCompatibilityTest.php`, `GroomingAdministrationAuthorizationTest.php`, `ClinicAdministrationAuthorizationTest.php` | Replace obsolete removal-era route expectations; retain historical financial and current QR coverage |
| `tests/grooming-paymongo-regression.cjs`, `online-payment-flows-regression.cjs`, `online-payment-navigation-regression.cjs`; existing `admin-data-cache-regression.cjs` | Restored behavioral tests, snapshot recovery, bounded/non-overlapping polling and targeted invalidation |
| `docs/superpowers/plans/2026-10-23-restore-paymongo.md`, this document | Implementation/verification and deployment handoff |

No Composer/npm dependencies, applied migrations or actual `.env` files were changed. No new database migration is needed: existing gateway, reservation and QR structures are reused.

## PHP 8.4 and verification

The actual interpreter is `C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe` (PHP 8.4.26). Bare `php` still resolves to XAMPP PHP 8.2.12. Tests explicitly use PHP 8.4, including concurrent workers via `PHP_BINARY`. Installed Laravel is 12.66.0; PHPUnit is 11.5.55. The existing `^8.2` PHP constraint supports 8.4 and remains unchanged.

Composer strict validation and `check-platform-reqs --lock` passed under 8.4. The portable runtime still needs its documented optional cURL/MySQL extensions enabled for corresponding real integrations; Composer's declared extension check alone does not certify MySQL connectivity. See the repository README for runtime selection.

| Verification | Result |
| --- | --- |
| Final focused PayMongo/current QR, schema, price/amount and report suite | 170 tests, 1,927 assertions passed |
| Full PHP 8.4 suite | 1,558 tests: 1,549 passed, 1 failed, 8 skipped; 18,616 assertions |
| Actual concurrent PayMongo/webhook vs webhook/Cash/manual QR workers | 3 cases, 39 assertions passed, proving overlapping writer locks and one sale/stock deduction |
| `npm test` | 78/78 regression scripts passed |
| `npm run build` | Passed; existing Browserslist data notice remains |
| Composer strict validation / locked platform requirements | Passed under PHP 8.4.26 |
| Changed/restored PHP lint, JavaScript syntax, diff whitespace | Passed |

The full-suite failure is `GroomingAdministrationAuthorizationTest::test_archive_payload_has_constant_query_count_and_keeps_the_page_contract`: 14 queries against its existing limit of 13. It also fails in a separate isolated process loading unchanged starting-HEAD payment classes. Its assertion and unrelated application behavior were not weakened or removed. Proof: `storage/logs/paymongo-baseline-archive.log`.

The final focused rerun also verifies that a confirmed charge requiring reconciliation has no successful payment/receipt payload. The wider run preceded this response cleanup; the affected Grooming/Clinic/POS and current QR tests were rerun afterward.

The eight skips are six opt-in `AdminDashboardMysqlTest` cases, one MySQL grooming-capacity concurrency case, and one opt-in phone-verification MySQL migration case. Their dedicated runners require an explicitly isolated MySQL test environment; no production/local application database was substituted. MySQL/Hostinger concurrency is not certified by the SQLite checks.

No actual provider checkout/webhook delivery was exercised. A browser attempt against an isolated static localhost fixture timed out because the available browser could not reach the workstation's loopback server; browser visual/end-to-end checks remain outstanding. The temporary fixture/server/baseline source copies were removed/stopped. Automated component tests use isolated fixtures; they do not replace real provider/browser verification.

Logs are under `storage/logs/paymongo-*.log`. All PHP test writes use in-memory or owned disposable test databases. No application/Hostinger database was migrated or modified.

## What I Need to Do Manually

### A. Hostinger .env configuration

Edit **Hostinger's private Laravel `.env`**, in the deployed project directory containing `artisan`. These are sample values for the documented root-domain deployment; replace credential placeholders privately:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://darkorchid-horse-104804.hostingersite.com
FRONTEND_URL=https://darkorchid-horse-104804.hostingersite.com

PAYMONGO_SECRET_KEY=sk_test_REPLACE_WITH_YOUR_TEST_SECRET_KEY
PAYMONGO_WEBHOOK_SECRET=REPLACE_WITH_THIS_TEST_ENDPOINT_SIGNING_SECRET
PAYMONGO_LIVE_MODE=false
PAYMONGO_PAYMENT_METHODS=qrph
PAYMONGO_RETURN_URL=https://darkorchid-horse-104804.hostingersite.com/pages/admin/appointments.html
```

`APP_ENV=production` configures Laravel hosting; it does not enable PayMongo live payments. Leave existing `APP_KEY`, Hostinger database credentials, mail, queue, cache, storage and session configuration intact. Do not regenerate the application key, upload the local `.env`, or change the Hostinger database to the local database. Never copy keys into frontend scripts, HTML, browser tools or shared logs. Hosted Checkout needs no frontend PayMongo key.

Use **only** `qrph` in `PAYMONGO_PAYMENT_METHODS`. The service retains configurable historical channels; it does not silently correct a wrong but supported channel setting. New attempts snapshot the configured list, so verify this value before opening any checkout. New configuration does not change existing pending attempts' saved methods or their provider return URLs. Use fresh disposable attempts after redeployment; resolve old pending sessions safely rather than editing their financial rows.

Configuration precedence is `PAYMONGO_RETURN_URL` → `FRONTEND_URL` → `APP_URL`. The last two receive the Grooming page suffix only when the explicit return variable is absent. A stale explicit localhost return URL is not repaired by changing `APP_URL` alone. Avoid blank URL overrides; either set the correct full value above or remove the override to use the fallback. `.env.example` deliberately retains useful localhost defaults.

| Purpose | Hostinger URL before dynamic recovery parameters |
| --- | --- |
| Grooming browser return | `https://darkorchid-horse-104804.hostingersite.com/pages/admin/appointments.html` |
| Clinic browser return | `https://darkorchid-horse-104804.hostingersite.com/pages/admin/clinic.html` |
| POS browser return | `https://darkorchid-horse-104804.hostingersite.com/pages/admin/inventory/inventory.html#sell-product` |
| Provider-to-server webhook | `https://darkorchid-horse-104804.hostingersite.com/api/paymongo/webhook` |

The application adds `gateway_payment`, `payment_context`, `payment_return` and `payable` query parameters before the POS fragment. Do not append those manually to `.env`. The webhook URL is registered in PayMongo, not placed in `PAYMONGO_RETURN_URL`. There is no separate application webhook-URL environment variable.

Keep **your workstation's local `.env`** separate, for example:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://127.0.0.1:8000
PAYMONGO_LIVE_MODE=false
PAYMONGO_PAYMENT_METHODS=qrph
PAYMONGO_RETURN_URL=http://127.0.0.1:8000/pages/admin/appointments.html
```

Use port `5173` in the local frontend/return values only if that is where you actually serve the local static pages. Retain your existing private local test credentials if needed. Without a public local webhook, local checkout cannot be an end-to-end settlement test; use mocked automated tests locally and the complete Hostinger workflow for integration. Do not send local-created events to Hostinger: the matching payment records would be in a different database. No tunnel, remote API override or synchronization is needed.

### B. PayMongo Dashboard configuration

1. Sign in to the [PayMongo Dashboard](https://dashboard.paymongo.com/). Select **Test Mode** in its environment selector and verify you are viewing test data before inspecting keys or webhooks. Under Developers/API Keys (sometimes beneath Settings), select the secret key beginning `sk_test_`; never use a live key.
2. Open **Developers → Webhooks** in that test environment. Inspect the existing endpoints first. If the exact URL below already exists, open and reuse it; do not add another copy.
3. Verify the complete HTTPS URL, without a trailing redirect, localhost, `/public`, `/index.php`, query parameters or browser page path:

   ```text
   https://darkorchid-horse-104804.hostingersite.com/api/paymongo/webhook
   ```

4. If no matching endpoint exists, use **Add endpoint**, enter that URL and subscribe to **`checkout_session.payment.paid`**. If an existing endpoint has the wrong URL/event, correct that endpoint. A subscription only to `payment.paid` does not settle Hosted Checkout in this application. The controller acknowledges unrelated signed events without fulfilling anything.
5. Open that endpoint's details and reveal/copy its **webhook signing secret** privately. This is distinct from the API secret key and from secrets belonging to other endpoints or environments. Save it only as Hostinger's `PAYMONGO_WEBHOOK_SECRET`. If the dashboard does not offer the secret, follow its supported reveal/rotation procedure for that endpoint, then update Hostinger and rebuild configuration cache before testing. Do not create a duplicate just to obtain another secret.
6. Confirm the endpoint is **enabled**, the subscription is saved, and its Test Mode/account matches the key used by Hostinger. If you are changing an endpoint, coordinate changes outside active checkout use.
7. After the controlled test in section D, inspect that endpoint's delivery/event history: the event should be `checkout_session.payment.paid`, environment test, and the HTTP response successful. Inspect failure status/response and timestamp if unsuccessful. A blank history before a test does not prove delivery works. Where the dashboard offers retry/resend, use it on the same test event to verify duplicate safety. Do not forge or manually re-sign webhook requests.

PayMongo documents endpoint creation/editing, enablement and test/live signatures in [Webhook setup and management](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management). Re-enabling does not itself replay events missed while an endpoint was disabled; use supported delivery recovery and review unresolved payments.

### C. Hostinger redeployment

The following commands are **for you to run**, not commands executed during this inspection. The actual SSH project path, installed server revision, PHP selection, database migration state and worker setup have not been verified. Use the directory containing the deployed `artisan`, `composer.json` and `bootstrap/`; do not assume it is `public_html`. Verify it before proceeding.

**1. Mandatory: prepare and back up before replacing code.** Back up the Hostinger database, existing code/revision, private `.env`, and persistent storage including private QR proofs. Keep backups outside public web directories and confirm they can be restored. Let active checkouts finish and preserve unresolved attempts/reservations. Avoid interrupting incoming paid webhooks; any temporary maintenance window must be followed by delivery-history/retry checks. Retain the current `APP_KEY` and uploaded files.

**2. Mandatory: prepare and release the intended branch yourself.** Review the local diff and, when satisfied, commit/push it yourself if your release process uses Git; these edits are currently uncommitted. Build the full release's frontend assets on your workstation using its normal dependency lockfile:

```sh
git status --short --branch
git branch --show-current
npm ci
npm run build
```

The source branch must be `gerald-frontend` in `rij-summit/bethlehem-grooming-system`. This workspace's `backend` remote is that repository; `origin` is the separate frontend repository. Do not assume Hostinger uses the same remote name. For a **Git-managed Hostinger checkout only**, inspect:

```sh
git status --short --branch
git remote -v
git branch --show-current
git rev-parse HEAD
```

After identifying the remote that actually points to `rij-summit/bethlehem-grooming-system`, use your established deployment process. For example, **only if that verified server remote is named `backend`**, the tree is clean, and the checked-out branch is `gerald-frontend`:

```sh
git fetch backend gerald-frontend
git pull --ff-only backend gerald-frontend
git rev-parse HEAD
```

Substitute the verified remote name if different; stop and review unexpected local changes or divergence. For an SFTP/archive release, upload the reviewed branch's complete release instead, retaining Hostinger `.env` and storage. Include changed static `pages/`, `scripts/`, CSS, normal generated OCR/PDF assets and `public/build/`; Vite does not bundle all of these standalone pages/scripts. Do not overwrite the working Hostinger front controller/path adjustments blindly. Keep the document root at Laravel's public entry point and private code/configuration inaccessible. No new root rewrite or domain hardcoding is required for payment.

**3. Mandatory: confirm PHP 8.4 for web and SSH.** In hPanel, check the website's PHP Configuration and required extensions. Separately verify the SSH executable below exists; website selection does not guarantee the SSH default uses the same version. Also check the interpreter used by any existing cron/worker. [Hostinger's Composer guidance](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/) documents the explicit executable convention.

Run from the verified Laravel project root, using the account's actual matching paths:

```sh
pwd
test -f artisan
/opt/alt/php84/usr/bin/php -v
/opt/alt/php84/usr/bin/php --ini
/opt/alt/php84/usr/bin/php -m
command -v composer2
```

Confirm PHP reports 8.4 and the web runtime has Laravel's required extensions plus `pdo_mysql` for the Hostinger database. Verify outbound HTTPS/OpenSSL and a working HTTP transport for PayMongo; enable cURL as recommended by the repository README. Do not disable TLS certificate verification. Confirm `/usr/local/bin/composer2` before using it below; substitute the verified Composer path if different.

**4. Mandatory for a source release: install locked PHP dependencies; review schema state.** Do not run `composer update`, `composer setup` or `key:generate` on this existing deployment. Composer's `setup` script also migrates and must not be used here.

```sh
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 validate --strict --no-check-publish
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --prefer-dist --optimize-autoloader
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 check-platform-reqs --no-dev
/opt/alt/php84/usr/bin/php artisan migrate:status
```

Review all pending migrations and their code before applying any. Existing payment structures include `2026_10_16_120000_create_gateway_payments_table`, `2026_10_18_000001_extend_shared_gateway_payments` (including stock reservations), and the October 23 manual QR migrations. This readiness task adds **no migration**. Whether the unreleased implementation needs existing migrations depends on Hostinger's actual migration ledger. Already-applied migrations must not be rerun manually.

**Conditional only:** after backup and review, if the release has necessary pending migrations, preview their SQL without applying it, review any migration PHP for side effects, then apply only the approved deployment migrations. `migrate --force` applies **all** pending migrations; do not use it if any pending item remains unreviewed.

```sh
/opt/alt/php84/usr/bin/php artisan migrate --pretend --force
/opt/alt/php84/usr/bin/php artisan migrate --force
```

If `migrate:status` shows everything required already ran, skip both commands. Never use `migrate:fresh`, `migrate:refresh`, `db:wipe`, `db:seed`, migration rollback, or a database import to prepare these existing payment records.

**5. Mandatory: update Hostinger's environment, then rebuild relevant caches.** Apply section A privately and ensure section B has the matching secret. Preserve the existing Hostinger DB connection. If you changed the database environment as part of a separately reviewed release, clear stale config before reviewing migrations; otherwise keep its established connection intact.

```sh
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan route:list --path=api/paymongo -vv
/opt/alt/php84/usr/bin/php artisan route:list --path=online-payment -vv
```

`config:cache` and `route:cache` rebuild their respective existing caches; no preceding `optimize:clear` or broad `cache:clear` is required. Do not print `config:show services`, dump `.env`, or share cached configuration files: they contain credentials. The public route listing should show `POST api/paymongo/webhook` without staff authentication, and authenticated payment routes for all three contexts. API routing, not `PAYMONGO_RETURN_URL`, determines the webhook route.

**Optional/conditional:** use `view:cache` only if your usual release includes Blade view caching. The payment return pages here are static HTML. Restart existing persistent queue workers only when they need to load changed code/configuration; none is needed for webhook settlement itself. Existing cron workers that exit after each run start fresh automatically, so do not create another payment cron or worker.

```sh
# Optional Blade cache
/opt/alt/php84/usr/bin/php artisan view:cache
# Only for existing persistent Laravel queue workers
/opt/alt/php84/usr/bin/php artisan queue:restart
```

Use your established Hostinger PHP/OPcache reload mechanism only if the release requires it or stale code persists; no `systemctl` or hosting-setting change is assumed. Preserve existing background email work and scheduling.

**6. Mandatory: verify the release before simulation.** Compare the deployed revision/release manifest to your reviewed local commit; do not rely on branch name alone. Check the three return pages load at their expected HTTPS paths, the browser sends API requests to this Hostinger origin, and the route listings match. From a terminal, a read-only route probe is:

```sh
curl -i -H 'Accept: application/json' https://darkorchid-horse-104804.hostingersite.com/api/paymongo/webhook
```

This sends GET to a POST-only endpoint: normally expect **405 Method Not Allowed** with POST allowed. A 404, HTML login page, redirect, TLS/DNS error or 5xx requires deployment/routing investigation. A 405 alone does not prove signature verification, Dashboard registration, deployed version or delivery; the signed simulation is still required. Do not send unsigned fabricated paid events as a substitute. Inspect the effective non-secret return URL, `qrph` list and false live mode privately if needed; never publish configuration dumps.

### D. Manual payment testing

**PayMongo Test Mode can still change real Hostinger application records and inventory.** Use dedicated disposable bookings/cases, a dedicated test staff account where practical, and isolated test stock; record starting quantities and references. Avoid real customers and operational stock. Manual Cash/QR records may count as real collections because those methods are independent of the gateway test flag, so run those smoke checks only in suitable disposable/staging data. Keep an audit trail rather than deleting financial rows to reset a test.

1. Verify Dashboard Test Mode, the enabled endpoint and matching server credentials. Open the **deployed Hostinger** website, sign in with the appropriate permissions, and hard-refresh once after release if old assets remain. Do not initiate this integration test from localhost.
2. Prepare a small disposable Grooming visit with known prices, confirmed pet sizes and, optionally, one test product. Record its expected total/stock. Select **Online Payment** and start checkout. Expect a PayMongo-hosted page offering QR Ph only, and an application attempt that remains pending. No manual receipt upload, OCR, reference entry or Cash tender is needed. A second submission must reuse/block the unresolved attempt.
3. **Simulate through PayMongo's official Test Mode controls/test URL only. Do not scan generated QR codes using a real banking/e-wallet app.** PayMongo's [testing guide](https://docs.paymongo.com/docs/payment-acceptance-testing) warns that QR Ph test QR codes can process real transactions and directs developers to the provider's `test_url`. In Hosted Checkout, use its test simulation control if offered. If it does not expose one, locate the provider-generated test URL for that same checkout's QR Ph payment through PayMongo's supported test tooling/developer response, or ask PayMongo support how to simulate that Hosted Checkout session. Do not create a separate direct Payment Intent as a replacement, guess a simulator URL, or add an endpoint exposing secrets/payment payloads. The exact Hosted Checkout simulation control for this account remains unverified.
4. Choose the supported successful simulation. Check Dashboard delivery history for `checkout_session.payment.paid` and a successful HTTP response. Return via the checkout's normal return action to Grooming appointments. Expect the authoritative server status and existing receipt labelled **Online Payment**, a Test indicator where supported, the correct amount, and one stock deduction for any sold test product. Early payment preserves an unfinished visit's workflow; final payment releases the finished visit as before.
5. Refresh or reopen the return page. Expect recovery of the same attempt and receipt, without another sale. Where the Dashboard offers supported event resend/retry, resend the same test event and verify one payment/transaction and one inventory deduction remain. Never replay hand-edited payloads or reuse signing secrets in a browser.
6. Repeat with separate disposable **Clinic** finalized charges (including a fractional line if useful): return to `/pages/admin/clinic.html`, case paid/completed once, correct invoice. Repeat **POS** with a small test cart: return to `/pages/admin/inventory/inventory.html#sell-product`, correct cart/receipt, cashier ownership, reserved availability while pending and a single deduction after confirmation. Verify all three labels remain Online Payment and test collections stay separate from real gateway totals.
7. Test delayed confirmation with a separate attempt. Return/refresh while no valid paid webhook has yet arrived; expect pending, no success receipt/fulfillment, and protected stock/billing. Existing automatic checks are bounded; **Check Status** remains available afterward. Use supported provider delivery recovery if needed, then check again. Do not mark it paid manually or use Cash to bypass a pending hold. Use a maintenance/staging test window for deliberate delivery-delay experiments, not account-wide endpoint disruption during staff use.
8. With fresh disposable attempts, test cancel/back and the provider's supported failure/expiry simulation. A cancel return triggers an expiry attempt; stock/billing unlocks only after the server verifies safe checkout expiry. Failed individual payments or an expired QR code do not automatically mean the whole checkout is safely expired. If cancellation is refused or status is ambiguous, expect pending/held state. Review the actual provider checkout before retrying. A late paid event on a locally closed attempt is retained for administrator reconciliation, without another fulfillment.
9. On independent disposable records, confirm **Cash** still validates tender/change and generates its existing receipt. Confirm manual **QR Payment** still uses the clinic's configured receiving QR/providers, optional receipt/OCR, reference/amount checks and receiving-account acknowledgment. Neither method should require PayMongo configuration or settle a pending Online Payment. Check permissions and private proof access remain intact.

If a step fails, inspect:

| Symptom | Inspect / expected safety behavior |
| --- | --- |
| Localhost return or wrong page | Hostinger explicit return URL, then frontend/app values, stale config cache, old checkout created before the change. New configuration only affects new sessions. |
| Checkout 422, "not configured" | Test secret key prefix, nonempty matching signing secret, false live mode and valid QR Ph configuration. Do not expose values in screenshots/logs. |
| Checkout 502 / timeout | Hostinger outbound HTTPS/DNS/CA certificates and PayMongo availability/account method support. An ambiguous creation remains held for reconciliation; do not keep making new sessions. Provider response bodies/authorization are deliberately not logged or returned. |
| Webhook 401 | Correct endpoint signing secret, test `te` signature, unchanged raw body, server clock within the five-minute tolerance and refreshed cache. Never log full headers/secrets to debug. |
| Webhook 422 / unknown payment | Matching Hostinger database and checkout, test environment, PHP cent amount/currency, saved methods, reference, payment/event IDs and payable metadata. Local-origin payment records will not be present on Hostinger. |
| Webhook 404/405/419/redirect | Correct POST URL, public front controller/rewrite, deployed route/cache; the repository's API webhook needs no browser CSRF token or login. GET 405 is expected only for the read-only probe. |
| Webhook 5xx | Hostinger PHP/error logs, `storage/logs/laravel.log` or the configured log channel, unapplied required schema, database permissions/connectivity and provider delivery retries. |
| Provider paid but app pending | Check delivery history and actual checkout identity; return/status never settles. Recover delivery through supported provider tools and investigate before any retry/alternate payment. |
| Paid gateway but no application receipt | Inspect `reconciliation_reason` privately: stock conflict, previously closed checkout or already-paid payable requires review. Preserve payment and reservations; no manual SQL status/stock override. |

Capture only non-secret timestamps, event/checkout identifiers, HTTP status and sanitized error messages when asking for help. Preserve the matching test references for reconciliation; avoid sharing customer details, raw payment payloads or credential-bearing headers. Dashboard and Laravel logs complement each other; validation errors may appear in the delivery response rather than the Laravel log.

### E. Final readiness checklist

- [x] Existing Hosted Checkout, QR Ph snapshots/method verification, Test Mode-only credentials and Online Payment labels inspected.
- [x] Signed paid-webhook-only settlement, identity/amount/currency/environment checks, idempotency, stock holds, return recovery, expiry/cancellation and reconciliation retained.
- [x] No application-code change required; existing test fixtures/assertions and this deployment guide updated.
- [x] Relevant PHP tests: 192 passed / 2,213 assertions; relevant frontend scripts: 9 passed; changed PHP syntax and diff whitespace passed. No remaining failures in the selected checks.
- [x] No new financial queries, HTTP requests, polling, migrations, dependencies, services or UI changes.
- [ ] Owner: back up, release reviewed `gerald-frontend`, verify PHP 8.4/extensions and pending migrations, configure Hostinger private `.env`, rebuild config/routes and restart only existing workers when needed.
- [ ] Owner: verify/reuse the Test Mode Dashboard endpoint, event subscription, enabled state and matching signing secret.
- [ ] Owner: verify public DNS/TLS/routing, deployed revision, account-specific QR Ph simulation, signed delivery, receipts, MySQL behavior and one-time inventory changes with disposable records.
- [x] **Repository is ready for your manual Hostinger redeployment, conditional on the checks above.** Public payment delivery and end-to-end integration are not yet verified; live payments remain intentionally unavailable.
