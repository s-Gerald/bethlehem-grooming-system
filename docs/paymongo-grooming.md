# QR Ph-only PayMongo alongside manual QR/OCR and Cash — PHP 8.4

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

## Local configuration and manual testing

Set these yourself in the appropriate private environment; `.env.example` contains blank secret placeholders only:

```dotenv
PAYMONGO_SECRET_KEY=your_test_secret_key
PAYMONGO_WEBHOOK_SECRET=your_test_webhook_signing_secret
PAYMONGO_LIVE_MODE=false
PAYMONGO_PAYMENT_METHODS=qrph
PAYMONGO_RETURN_URL=http://127.0.0.1:8000/pages/admin/appointments.html
```

Use actual `sk_test_` credentials server-side only. Keep `PAYMONGO_LIVE_MODE=false` and `PAYMONGO_PAYMENT_METHODS=qrph` for this QR Ph-only merchant account. Adjust the return URL to your real frontend origin/port and deployment subdirectory. Clinic/POS return paths derive from this anchor. Configurable channels are retained for future account capabilities; do not enable unsupported channels. There is no frontend secret/public-key setting to add.

The currently registered **test-mode** webhook is `https://darkorchid-horse-104804.hostingersite.com/api/paymongo/webhook`. It can settle attempts stored in that Hostinger deployment's database; it cannot settle attempts created in the separate `http://127.0.0.1:8000` database. Returning to localhost alone does not deliver the webhook. Automated mocked tests do not prove real delivery.

For a local end-to-end test, personally configure a trusted publicly reachable HTTPS endpoint routed to the local API, register that test webhook for `checkout_session.payment.paid`, and configure its own signing secret privately in that local environment. Alternatively, test checkout and webhook together on Hostinger using disposable records in its database, its matching signing secret and an HTTPS Hostinger return URL. No tunnel or endpoint was installed/changed by Codex. Before deployment, review the changes, refresh configuration caches yourself if applicable, verify QR Ph activation and test credentials, then confirm real signed delivery, duplicate-event safety, correct receipts, and test-versus-real revenue separation. Live payments remain intentionally disabled.

For QR Ph tests, use the provider's simulation controls/test URL. The official [testing guidance](https://docs.paymongo.com/docs/payment-acceptance-testing) specifically warns that generated QR codes can process real payments even in Test Mode: do not scan/pay them with a real bank or e-wallet app. Hosted checkout and direct Payment Intent test controls may differ; verify the available test control in the actual hosted session.

Before deployment, use disposable records to check:

1. Grooming Cash succeeds with correct change, existing invoice and pickup/early-payment behavior.
2. QR Payment shows the configured clinic QR and all receiving providers. Check optional upload, drag/drop and paste; local OCR/reference correction; invalid/duplicate reference errors; amount discrepancy; receiving-account acknowledgment; private proof permissions; invoice/history.
3. Online Payment works with no cash/QR fields, OCR or acknowledgment. Test early and final Grooming, multiple pets and product add-ons. Verify the frozen checkout summary after refresh and navigation.
4. Exercise pending, declined/retried checkout, cancel return, confirmed expiry and interrupted requests. Check Status remains available after polling stops. A return URL must never show paid without the signed webhook.
5. Try another tab/method while checkout is held; verify rejection and one payment/stock movement after duplicate webhook delivery. Check cancellation racing with payment and delayed webhook delivery.
6. Verify Clinic finalized/fractional charges, billing holds, staff permissions, invoice and completed status.
7. Verify POS reservations reduce availability for other cashiers; cancellation releases only after provider confirmation; payment deducts stock once at frozen prices. Simulate a stock conflict only in disposable data and inspect reconciliation behavior.
8. Review historical PayMongo and current QR transactions, test badges, real/test totals and report/dashboard cache refresh. Test keyboard and narrow/mobile layouts; real browser visual verification is still required.

## Hostinger deployment actions — user-run only

No deployment commands were executed. Choose PHP 8.4 for the website in hPanel, and separately verify SSH/cron/queue interpreters and extensions. Hostinger documents using an explicit versioned interpreter for Composer because website and SSH defaults can differ: [PHP configuration](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/) and [Composer PHP versions](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/).

After your normal code review, backup and release preparation, run from the deployed Laravel project directory, using paths verified on your hosting account:

```sh
/opt/alt/php84/usr/bin/php -v
/opt/alt/php84/usr/bin/php -m
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 validate --strict --no-check-publish
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --prefer-dist --optimize-autoloader
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 check-platform-reqs --no-dev
/opt/alt/php84/usr/bin/php artisan migrate:status
```

This restoration adds **zero migrations**. Do not rerun applied gateway/QR migrations. Only if `migrate:status` identifies reviewed pending migrations from your wider deployment, apply those yourself after backup:

```sh
/opt/alt/php84/usr/bin/php artisan migrate --force
```

Build assets locally with `npm ci` and `npm run build`; deploy the normal generated assets, existing vendored OCR/PDF assets and changed static pages/scripts/styles using your established release process. Keep private payment proofs/storage private. Do not expose the repository or `.env` as the document root.

Configure the test settings privately on Hostinger, set `PAYMONGO_RETURN_URL` to the HTTPS frontend, register the HTTPS test webhook, and then refresh deployment caches:

```sh
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
/opt/alt/php84/usr/bin/php artisan queue:restart
```

Use the same verified 8.4 binary for cron/workers and your normal PHP/OPcache restart process when required. Run the checklist in staging/disposable data. This code deliberately rejects live mode; enabling real collections requires a separately reviewed live-payment change and provider validation.
