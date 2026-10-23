# Grooming QR Ph implementation report

Implemented October 23, 2026. QR collection is available in Grooming final payments and Pay Now. Cash remains the default. Clinic and POS collection are unchanged.

## MySQL migration correction

The existing `2026_10_23_000001_create_qr_payments` migration was corrected directly; no additional migration was added. The original SQLSTATE 42000 / driver error 1067 for `verified_at` was reproduced against an owned disposable database on the actual local MariaDB 10.4.32 server.

The server has `explicit_defaults_for_timestamp=0`. Its global SQL mode includes `NO_ZERO_DATE` and `NO_ZERO_IN_DATE`; Laravel's normal MySQL connection additionally enables `STRICT_TRANS_TABLES`. With explicit timestamp defaults disabled, the first required `TIMESTAMP` can receive implicit current-time initialization/update behavior, while a later required `TIMESTAMP` receives an invalid zero-date default. This explains both the migration error on `verified_at` and the risk of silently changing `transaction_at`. This is [documented MariaDB TIMESTAMP behavior](https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp).

| Field | Correct definition and responsibility |
| --- | --- |
| `qr_payments.transaction_at` | Required `DATETIME`, no default or automatic update; supplied from the validated actual transaction time |
| `qr_payments.verified_at` | Required `DATETIME`, no default or automatic update; explicitly supplied by the backend when authenticated staff verification is accepted |
| `qr_payment_settings.created_at`, `updated_at` | Existing Laravel nullable `TIMESTAMP` columns; explicit nullability suppresses legacy implicit timestamp behavior; settings model/seed supplies metadata normally |

Persisted QR rows represent confirmed payments, so neither payment date should be nullable. `QrPayment` already disables Eloquent timestamps and casts both dates to datetime. `QrPaymentSettings` correctly uses Eloquent's settings timestamps. `QrPaymentVerificationService` already validates the transaction date and explicitly supplies `verified_at = now()` at verification; a client-supplied verification date is ignored. Those models and the service required no changes. Only this one new migration defines the QR tables; historical payment migrations were left intact.

MySQL DDL can leave the settings table and singleton row committed when creation of the payment table fails. The corrected migration checks for existing tables/column and seeds only a missing singleton. Retrying preserves saved account settings, version, Cash records and historical payment records. The regression runs the actual targeted Artisan migration twice after simulating the partial failure and checks successful migration registration.

Existing `QrPaymentSettingsTest` and `GroomingPaymentPriceSafetyTest` were extended. The latter's `paid_at` fixture was corrected to match the existing production migration's nullable timestamp: its previous required first timestamp let MySQL silently change the audit time when linking the QR record. The test retains a strict stored paid-time assertion and verifies distinct actual transaction/verification times, including rejection of a forged client verification time. No production Cash timestamp definition was changed.

Latest verification for this correction:

- `php tests/qr-payments-mysql.php`, using PHP 8.4: **56 passed, 991 assertions** on MariaDB 10.4.32 through the MySQL driver. Strict SQL mode and `explicit_defaults_for_timestamp=0` remained unchanged. Schema metadata confirmed both payment dates are non-null DATETIME columns without defaults or update clauses; settings dates remain nullable. The new temporary database's removal was verified.
- Focused `php artisan test --compact --filter='QrPaymentSettingsTest|GroomingPaymentPriceSafetyTest|PaymentSchemaCompatibilityTest|TransactionHistorySearchTest|PaymentAmountValidationTest'`: **86 passed, 1,814 assertions**, including Cash, historical gateway records, stock/settlement, concurrency and history regressions.
- Existing OCR, Grooming invoice, settings and transaction-details JavaScript regression scripts: all four passed.
- PHP syntax checks and diff whitespace check passed. No frontend build was needed for this migration/test-only correction.

The new MySQL runner uses test-specific `QR_PAYMENT_TEST_MYSQL_HOST`, `QR_PAYMENT_TEST_MYSQL_PORT`, `QR_PAYMENT_TEST_MYSQL_USERNAME` and `QR_PAYMENT_TEST_MYSQL_PASSWORD` variables, defaulting to literal loopback `127.0.0.1:3306`, root and an empty password. It never uses application `.env`/`DB_*` credentials to create a database. It verifies local server identity, creates a new random `bethlehem_qr_<12 hex>` schema with an ownership marker, and uses an isolated PHPUnit configuration. Fixture setup and teardown verify ownership before writes. Only that newly created schema is removed. Connection failure has no remote fallback.

Files changed for this correction: the existing QR migration, the two existing PHP test classes, and this report. Added test utilities: `tests/qr-payments-mysql.php` and `tests/Support/QrPaymentMysqlHarness.php`. No replacement test class or migration was created.

Your actual application/production databases were not migrated, reset or altered. No global database settings or private environment files were changed. Run `php artisan migrate` yourself after reviewing the fix; no manual table deletion is needed for the reproduced partial-failure case. Runtime application requests, settlement services, caching and navigation were unchanged; extra schema checks occur only during migration. Direct Hostinger execution was not performed; the fix uses ordinary required DATETIME columns compatible with MySQL/MariaDB without reliance on legacy timestamp settings.

The MySQL execution log is `storage/logs/qr-migration-mysql.log`. The earlier full-suite counts below describe the initial implementation, before this correction.

## Workflow and configuration

Settings → QR Payments is available to administrators. It supports private image upload/preview/replacement, merchant/account name, receiving bank/e-wallet, enable/disable, explicit Save changes, error feedback, and version conflict detection. PNG/JPEG/WebP uploads are limited to 3 MB and 128–4096 pixels per side. Enabling requires the image and receiving identity. Replacement requires confirmation that the image is the official account QR.

Grooming loads configuration only when its payment modal opens. When configured and enabled, QR Ph appears beside Cash. Selecting it displays the uploaded QR, receiving identity, exact amount due, editable verification fields and Confirm QR Payment. Customers scan and pay in their own app; no website visit, second QR or screenshot submission is required.

Staff enter the exact amount received, actual external reference, transaction date/time in Philippine time and official verification source, then explicitly acknowledge receipt of funds in the clinic's account. The server recalculates the total and rejects mismatches, unavailable/stale configuration, ineligible visits, unauthorized actors, missing acknowledgment and reused references.

References normalize to uppercase letters/numbers with spaces and hyphens removed. Database unique constraints protect references, payable relationships and the financial-record link. Booking and inventory locks are retained. Verification and existing settlement execute in the same transaction; failure rolls back prices, stock, payment and notifications.

An unavailable reference requires an administrator, a 20–500 character explanation, actual transaction time, the ordinary account verification acknowledgment and an additional acknowledgment that the account credit was matched and not already recorded. No bank reference is invented. The reason and authenticated verifier are retained in the audit record.

## OCR and proof handling

Optional OCR uses pinned Tesseract.js 6.0.1 and a locally packaged English model. The API, worker, selected WebAssembly core and language data load on demand. Staff can select an image or paste an image while focus is inside the QR section. OCR attempts amount, reference, date/time and recipient extraction across common receipt layouts. Ambiguous amounts/dates remain blank. Every extracted field is editable.

Screenshots remain in the browser: they are not uploaded, stored in payment records, cached as proofs or exposed through URLs. There is therefore no retained payment-proof image to inspect in Transactions. Only reviewed payment fields and verification metadata persist. Recipient text extracted from a screenshot is not submitted or stored.

OCR has file type/size and decoded image-dimension limits, progress/error handling, a processing timeout and cancellation when the modal closes. Worker resources are terminated after use. Failure preserves manual entry. The actual installed OCR engine successfully extracted all four fields from a controlled synthetic receipt fixture during automated testing.

The engine configuration follows the [official local-installation documentation](https://github.com/naptha/tesseract.js/blob/master/docs/local-installation.md) and [supported image formats](https://github.com/naptha/tesseract.js/blob/master/docs/image-format.md). The displayed static-account QR pattern is consistent with the [BSP's description of QR Ph merchant collection](https://www.bsp.gov.ph/Media_And_Research/Public_Advisories/Joint_Memorandum_Circular_No.1_Series_of_2022_Paleng-QR_Ph.pdf).

## Shared architecture and financial behavior

- `QrPaymentSettings`: central versioned configuration and authorized image availability.
- `QrPaymentVerificationService`: request validation rules, reference normalization, exact-amount validation, configuration checks and audited verification creation.
- `QrPayment`: polymorphic payable association, unique external reference, receiving identity/version/image-hash snapshot, transaction time, verifier identity/time and reference exception reason.
- `payment-proof-ocr.js`: on-demand engine lifecycle and provider-layout-tolerant extraction.
- `qr-payment.js`: modal state, optional OCR interactions and verification payload/validation.

Both payment flows continue through `GroomingPaymentSettlementService`; there is no duplicate QR settlement implementation. Existing service price/size safeguards, multi-pet breakdowns, product sale records, stock movements, booking status/pickup eligibility and notifications remain in use.

The existing success modal shows QR payment amount, method, bank reference when available and verification details. Transactions batch-loads QR metadata only for the visible page and supports searching by normalized external reference. New verified QR payments do not build or print Cash invoices. Cash and historical gateway invoice behavior are preserved. Gateway rows are not reused or overwritten; historical test payments remain excluded from actual collections.

## Migration and activation

Added `database/migrations/2026_10_23_000001_create_qr_payments.php`:

- Creates `qr_payment_settings`, seeded disabled with singleton ID 1.
- Creates `qr_payments` and its unique external-reference/payable constraints.
- Adds nullable unique `payments.qr_payment_id`, compatible with both supported payment primary-key schemas.

The active application's database was **not migrated**. To activate:

1. Run `php artisan migrate` using the project's documented PHP 8.4 interpreter.
2. Ensure `storage/app/private` is writable, persistent across deployments and included in backups. Official QR images live in its `qr-payments` directory; replaced images are removed after the database change succeeds.
3. Run `npm ci` and `npm run build` for deployment. Include generated `scripts/vendor/ocr/` files in the deployed artifact, or generate them on the host. They are ignored in Git, as are the existing Vite build outputs. No external OCR service or credentials are needed.
4. In Settings → QR Payments, upload the clinic's official QR, confirm ownership, enter receiving name/provider, enable and Save changes.
5. Reload Grooming and perform the practical Cash/QR and Pay Now/final-payment checks on localhost.

No private `.env` files were edited. No commits, pushes, deployments, actual transfers, payment-provider calls, OTP submissions or password reset interactions were performed.

## Initial implementation verification

| Check | Result |
| --- | --- |
| PHP 8.4 `php artisan test --compact` | 1,473 passed, 8 skipped; 19,834 assertions |
| Focused QR/Cash/pricing/settings/history/schema suite | 84 passed; 1,796 assertions |
| `npm test` | 74/74 regression scripts passed, including actual OCR engine recognition |
| `npm run build` | Passed; OCR packaging, Tailwind CSS and Vite |
| Two-process QR race | Passed; second process demonstrated an overlapping SQLite writer lock; one payment, QR record, product record, stock movement and notification |
| Database reference-uniqueness bypass test | Passed; duplicate insertion rejected by the database even without service precheck |
| `npm audit --omit=dev` | Zero reported production dependency vulnerabilities |
| Diff whitespace check | Passed |

Existing tests were extended rather than replaced. Cash-only method assertions were updated only where Grooming now accepts verified QR; unsupported Clinic/POS methods and historical gateway regression coverage remain. New tests cover settings permissions/upload validation/versioning, disabled or stale settings, exact totals, explicit/manual verification, reference validation/normalization, administrator exceptions, duplicate submission/races, early/final multi-pet settlement with add-ons, stock and financial totals, history metadata/reference search, QR success/invoice restrictions, OCR recognition/parsing/errors/cancellation, lazy settings reads, and historical invoice compatibility. No test files were removed or skipped to make these checks pass.

The eight skipped tests are existing opt-in MySQL integration checks (dashboard, Grooming capacity concurrency and phone verification). The QR race check used an owned temporary SQLite database with IMMEDIATE transactions, following the existing concurrency test pattern. It does not certify MySQL concurrency on the deployment host.

Logs are available locally at `storage/logs/qr-php-tests.log`, `storage/logs/qr-npm-tests.log` and `storage/logs/qr-build.log`.

## Performance and inspection limits

There are no new requests on unrelated navigation, no payment polling and no global OCR/clipboard listeners. Configuration loads once per payment modal opening; QR image loading waits until QR selection. Settings loads its configuration on first entry to the QR tab and reuses it until explicit reload. Current configuration/version is checked again at settlement.

OCR's generated distribution is approximately 20.6 MB across all engine variants and the model. The browser loads the selected core only when OCR is requested. Tailwind excludes these vendor files from scanning; measured CSS build time dropped from about 3.9 seconds with scanning to 1.6 seconds after exclusion. Existing cache invalidation now includes inventory and notifications after successful Pay Now as well as final payment. No Core Web Vitals performance claim is made.

The running dashboard was visually inspected in the ChatGPT browser. Existing blue accents, rounded surfaces, typography and control patterns were followed. Manual page navigation, payment entry, bank/account checks, OTP and password-reset testing were left to the user. The newly added UI has automated state/build coverage, but no claim of manual browser workflow verification is made.

Image validation and administrator attestation do not prove bank ownership, decode/validate a QR Ph payment payload, or establish settlement. OCR is functional extraction, not verification of funds. Staff must use the actual receiving account or official merchant notification; bank app formats and recognition accuracy still require practical review. A reference-free exception cannot provide the same cross-payment identity guarantee as a unique official reference. Verification records have no editing endpoint; any later correction workflow must retain the original audit information.

The build reports the existing outdated Browserslist database notice, and Node's VM tests print their experimental-module notice. Installing dependencies also reports development-tool vulnerabilities; the production-only OCR dependency audit is clean. No unrelated dependency upgrades were performed.

## File inventory

Created source, tests and documentation:

```text
app/Http/Controllers/QrPaymentSettingController.php
app/Models/QrPayment.php
app/Models/QrPaymentSettings.php
app/Services/QrPaymentVerificationService.php
database/migrations/2026_10_23_000001_create_qr_payments.php
docs/qr-payments.md
docs/superpowers/plans/2026-10-23-qr-payments.md
scripts/build-ocr-assets.mjs
scripts/components/qr-payment.js
scripts/services/payment-proof-ocr.js
tests/Feature/QrPaymentSettingsTest.php
tests/Fixtures/qr-payment-receipt.png
tests/Support/QrPaymentMysqlHarness.php
tests/qr-payments-mysql.php
tests/qr-payment-ocr-regression.mjs
```

Modified:

```text
.gitignore
app/Http/Controllers/PaymentController.php
app/Models/Payment.php
app/Services/GroomingPaymentSettlementService.php
css/output.css
package.json
package-lock.json
pages/admin/appointments.html
pages/admin/settings.html
pages/admin/transactions.html
routes/api.php
scripts/api.js
scripts/components/admin-dashboard.js
scripts/components/admin-settings.js
scripts/components/admin-transactions.js
tailwind.config.js
tests/Feature/GroomingPaymentPriceSafetyTest.php
tests/Feature/PaymentAmountValidationTest.php
tests/Feature/PaymentSchemaCompatibilityTest.php
tests/Feature/TransactionHistorySearchTest.php
tests/admin-grooming-invoice-print-regression.cjs
tests/admin-grooming-settings-regression.cjs
tests/admin-transactions-details-regression.cjs
```

Removed files: none. Generated/ignored build artifacts: `scripts/vendor/ocr/` (engine, worker, four embedded WebAssembly cores, English model and license notices) and `public/build/`.
