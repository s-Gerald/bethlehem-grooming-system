# Grooming QR Payment implementation report

Implemented October 23, 2026. QR collection is available in Grooming final payments and Pay Now. Cash remains the default. Clinic and POS collection are unchanged.

## Current workflow, proof storage and provider configuration

This section supersedes the earlier implementation reports below, including their descriptions of Amount Received, missing-reference exceptions and migration requirements.

### Payment workflow and presentation

- Normal full QR payments use the authoritative server-calculated total. Staff no longer retype Amount Received. The existing acknowledgment explicitly confirms that the displayed amount reached the official receiving account; it is never checked by OCR. Changing Total Due resets acknowledgment.
- An extracted receipt amount remains editable for OCR correction and is sent separately as `observed_amount`. Discrepancies block settlement on both client and server. Clearing an extracted amount does not bypass the client discrepancy check. Staff can correct a scan error after checking the account, or leave a genuine discrepancy unresolved. Removing an unsubmitted receipt returns to verified manual entry.
- Every new QR payment requires a normalized official external reference. Administrator exceptions, their controls/state/payloads and active backend branches were removed. Historical nullable references and reasons remain readable without any data rewrite. Duplicate references, payable uniqueness and existing settlement locks remain enforced.
- The user-facing label is **Payment Reference No.** throughout recording, success and transaction details. The backend field remains `external_reference`. Without a receipt, this is the only required typed QR field; method selection and receiving-account acknowledgment remain mandatory.
- Receipt upload, drop, screenshot paste and optional manual entry remain in the Record Payment modal. Remove aborts OCR, invalidates pending completions, releases preview URLs and clears unreviewed extraction while preserving independent edits. Replacement cannot apply old results or clear a newer scan's loading state.
- QR success shows Amount Paid, Payment Method, Payment Reference No., Recorded at and Verified by, preserving the service breakdown and customer details. Transactions show Recorded at in primary payment details and retain separate Grooming Reference, account, proof and audit information. Matching verification/recording times are not repeated. Cash success and printable invoices retain their existing behavior.

### Recording and receipt timestamps

The editable Payment Date & Time input and required-date validation were removed. Laravel generates `payments.paid_at` and `qr_payments.verified_at` during the existing settlement transaction, using configured `Asia/Manila` time. They persist only if settlement commits; client-supplied recording/verification timestamps are ignored. QR confirmation responses return machine-readable `paid_at` for the existing shared Philippine-time formatter, without another fetch or a browser-clock fallback.

`qr_payments.transaction_at` is only the original external transaction time. OCR retains a clear full date/time separately; unknown, invalid or conflicting receipt times remain null. The form displays an extracted receipt time read-only with a Clear action, never an editable date input. Explicit receipt time zones convert to Philippine time. Historical stored dates remain unchanged.

The forward-only migration `2026_10_23_000004_allow_unknown_qr_transaction_time.php` changes only this column to nullable `DATETIME`. It adds no columns, defaults, update clauses or data backfill. Its rollback intentionally does nothing because restoring NOT NULL would require inventing unknown dates. The original deployed migrations remain unchanged.

### Reference OCR

The existing lazy local Tesseract service now understands GCash, Maya, QRPH, InstaPay, generic reference/transaction labels and bank trace numbers. It reads inline, next-line and clearly separated column values, excludes billing/customer/processor identifiers, and uses receipt context to choose a payment reference. It never concatenates ambiguous columns. Conflicting candidates remain suggestions for staff to select or replace manually; GCash is not a universal preference.

Interword-space preservation improves layout extraction without another recognition pass, cloud service or dependency. Manual corrections, including cleared fields, survive later OCR completions. Receipt replacement clears unreviewed old suggestions; scan versioning and cancellation block stale results. OCR never acknowledges account credit. Existing tests include sanitized receipt text, generated GCash/Maya images, real local recognition/PDF rendering and manual-edit races.

### Providers and optional paying app

`config/qr_payments.php` is the receiving-provider catalog. Its GCash, Maya, BPI, BDO, Metrobank, RCBC, UnionBank, LandBank, PNB, Security Bank and AUB names were verified against the [BSP/BancNet participant list dated 30 September 2026](https://www.bsp.gov.ph/PaymentAndSettlement/QR%20Ph%20P2M%20Participants.pdf). This identifies receiving institutions; it does not certify that every institution supports every QR type. Continue uploading official institution-issued QR images.

Settings uses a searchable native provider list with an explicit Other supported provider fallback. The existing Settings request supplies the catalog, so no additional request is needed. Server validation canonicalizes catalog choices and preserves an existing unmatched provider name. No settings backfill or multi-account manager was added. Grooming retains one QR Payment choice.

Paying App is stored separately and remains optional. OCR recognizes only explicit, unambiguous `Paying app:`, `Paid using:`, `Sent using:` or `Payment app:` labels for GCash/Maya. A logo, receiving institution, recipient name or conflicting labels never establishes the paying app. Unsupported or ambiguous receipt formats remain unspecified. Staff can clear the extracted suggestion; no persistent dropdown was added.

### Attachment security, integrity and retention

Optional proof files are written under the existing private local disk, using random server filenames. QR rows contain only metadata: private path, display filename, MIME, size and SHA-256 hash. APIs omit raw paths and hashes. Uploaded filenames never determine storage paths.

Validation checks real MIME/content and a 5 MB size limit. PNG/JPEG/WebP receipts are limited to 16 megapixels and 16,000 pixels per side. PDFs require a successful bounded `pdfinfo` parse, no encryption and at most two pages; parsing has a five-second timeout. Browser PDF processing retains the existing two-page/four-megapixel rendering limits, disabled scripting/XFA and cancellation cleanup. Proof PDF previews reuse that renderer to display inert page images.

Validation, PDF parsing and file writes happen before financial locks. Metadata commits with the QR record and existing settlement in the same database transaction. Rejected settlements, duplicate retries and exceptions discard their uploaded file. A daily `qr-payments:prune-orphans` task handles interrupted requests or failed cleanup, deleting only unreferenced uploads older than 24 hours. Reference lookups are batched in groups of 200.

Recorded proofs are retained indefinitely with their official payment record and backups. No replacement/removal endpoint for recorded attachments is introduced. Any later retention/removal workflow must include authorization and an audit trail and must preserve the financial record.

Proof access is authenticated, restricted to staff with Grooming access and scoped to Grooming QR records. The endpoint accepts a record ID rather than a path and returns private/no-store, nosniff and sandbox headers. A private-directory Apache deny file protects deployments that expose the repository root; production should still use Laravel's `public` document root. Transactions carry compact metadata only. Viewing details never downloads proof; View Payment Proof starts the protected request. Closing/replacing details releases previews and discards stale responses.

### Files changed

Existing implementation files modified:

```text
app/Http/Controllers/PaymentController.php
app/Http/Controllers/QrPaymentSettingController.php
app/Models/QrPayment.php
app/Services/QrPaymentVerificationService.php
routes/api.php
routes/console.php
pages/admin/appointments.html
pages/admin/settings.html
pages/admin/transactions.html
scripts/api.js
scripts/components/admin-dashboard.js
scripts/components/admin-settings.js
scripts/components/admin-transactions.js
scripts/components/payment-invoice.js
scripts/components/qr-payment.js
scripts/services/payment-proof-ocr.js
css/components/admin-transactions.css
storage/app/private/.gitignore
docs/qr-payments.md
```

Existing tests updated in place:

```text
tests/Feature/GroomingPaymentPriceSafetyTest.php
tests/Feature/PaymentSchemaCompatibilityTest.php
tests/Feature/QrPaymentSettingsTest.php
tests/Feature/TransactionHistorySearchTest.php
tests/admin-grooming-invoice-print-regression.cjs
tests/admin-grooming-settings-regression.cjs
tests/admin-transactions-details-regression.cjs
tests/qr-payment-ocr-regression.mjs
```

New files, each used by the implementation or its delivery:

```text
app/Http/Controllers/QrPaymentProofController.php
app/Services/QrPaymentProofService.php
config/qr_payments.php
database/migrations/2026_10_23_000002_add_qr_payment_proofs.php
database/migrations/2026_10_23_000004_allow_unknown_qr_transaction_time.php
storage/app/private/.htaccess
docs/superpowers/plans/2026-10-23-qr-payment-workflow.md
```

No files were deleted. Obsolete exception UI, state, outgoing fields, validation branches and success-modal audit rows were removed in place. Historical exception columns and read-only details remain. Existing test expectations were revised rather than replaced with parallel suites. QR metadata now uses an explicit model field list, preventing schema-column caching from silently dropping newly migrated proof fields.

### Validation and performance

- Focused PHP payment/schema/history suite: **98 passed, 1,993 assertions**, including early/final confirmation without receipt dates, known/unknown receipt dates, server timestamps, rollback, uniqueness, private proof access and overlapping settlement/stock confirmation.
- Full PHP 8.4 suite: **1,498 passed, 8 existing opt-in skips, 1 failed; 18,617 assertions**. The failure is the existing archive query budget in `GroomingAdministrationAuthorizationTest.php:1419` (14 queries, limit 13). It also fails with the original Git HEAD payment controller and verification service loaded into the isolated test process. Its assertion and unrelated production behavior were not changed.
- Guarded `php tests/qr-payments-mysql.php`: **79 passed, 1,132 assertions** on local MariaDB 10.4.32 in an owned disposable database; cleanup verified. Metadata confirms nullable receipt `DATETIME`, required verification `DATETIME`, no automatic updates and unchanged historical rows. This does not certify Hostinger concurrency.
- `npm test`: **75/75 regression scripts passed**, including actual local OCR/PDF rendering, stale OCR completion races, multipart requests, early/final payment, Cash invoice, provider selection, transaction proof fetch/release and date formatting.
- `npm run build`: passed. Changed PHP/JavaScript syntax checks and `git diff --check`: passed.

Current full-suite/build logs: `storage/logs/qr-reference-php-tests.log`, `qr-reference-npm-tests.log` and `qr-reference-build.log`. Earlier logs below describe previous iterations.

OCR and PDF libraries remain lazy-loaded. No polling, eager attachment download, extra settings calls on unrelated navigation, per-row attachment queries or new JavaScript packages were added. Financial list pagination, totals, report filters and cache invalidation remain in place. Only affected pages' changed asset URLs were refreshed. Upload processing adds bounded work only to payments with proof, outside database locks. Core Web Vitals and production latency were not measured.

The existing and updated payment modal were visually inspected in the ChatGPT browser, preserving the original open tab. No payment data, receipts, OTPs or passwords were entered and no payment was submitted. Remaining practical checks are the user's functional browser workflow, real provider receipt formats, device scanning and deployment storage/PDF-validator availability. Non-blocking existing Node VM/PDF recovery and Browserslist notices remain.

### Before manual testing or deployment

1. Apply the new forward migration with the intended application's PHP CLI: `php artisan migrate --path=database/migrations/2026_10_23_000004_allow_unknown_qr_transaction_time.php`. The preceding QR migrations must already be applied. No actual application database migration was run during this task. Apply this schema change before testing receipt-free confirmations, then reload the page to load the updated modal scripts.
2. Keep private storage persistent, writable and backed up; include `storage/app/private/.htaccess` on Apache deployments and keep private directories outside public aliases on other web servers.
3. Make Poppler's `pdfinfo` available to the web/worker PHP process. Its default executable is `pdfinfo`; an absolute executable path can be configured through `QR_PAYMENT_PDFINFO`. The test environment has Poppler installed. If parsing is unavailable, PDF attachments fail safely before settlement with an image-upload alternative. No private environment files were edited.
4. Ensure the existing Laravel scheduler runs so abandoned-upload cleanup executes. Keep the official receiving QR configured. Deploy generated static OCR/PDF assets through the existing build process and restart long-lived workers when applying code/schema updates.

No commit, push or deployment was performed. Earlier reports below are retained as implementation history.

## Receiving-account verification simplification

The Grooming payment form no longer contains **Verified using**, **Transaction History** or **Payment Notification** choices. The selector, source-selection state, validation error, change handler and outgoing `verification_source` field were removed. The remaining fields use the existing spacing without replacement controls or helper text. **Verified in the clinic's receiving account** remains mandatory; OCR never checks it automatically. The QR image, amount, optional image/PDF receipt uploads, drop/paste, editable extraction, manual entry and administrator exception remain unchanged.

`QrPaymentVerificationService` no longer requires or validates a client source choice. Newly verified payments record `receiving_history` explicitly on the server, representing staff's required check of the actual receiving-account transaction. The controller passes only validated inputs and the service ignores client source values, including legacy notification values or malformed values. Source input cannot substitute for the required `qr_verified` acknowledgment, reference, actual transaction time, exact amount, authorization or payment eligibility. Settlement, locks and duplicate protection are unchanged.

The `verification_source` column, migrations, models and historical read-only source display were retained. Older `merchant_notification` and `receiving_history` entries remain readable with their original audit metadata; no data backfill or migration is needed.

Files changed for this simplification: `pages/admin/appointments.html`, `scripts/components/qr-payment.js`, `scripts/components/admin-dashboard.js`, `app/Services/QrPaymentVerificationService.php`, `tests/Feature/GroomingPaymentPriceSafetyTest.php`, `tests/Feature/PaymentSchemaCompatibilityTest.php`, `tests/Feature/TransactionHistorySearchTest.php`, `tests/qr-payment-ocr-regression.mjs`, `tests/admin-grooming-invoice-print-regression.cjs`, and this report. No files, dependencies or migrations were added or deleted. Existing dirty changes from the preceding UX implementation were preserved.

Existing regressions now submit early/final payments without a source field, assert the canonical server value, ignore tampered source inputs, require account acknowledgment, and read both historical source values without rewriting them. The existing multi-pet, product/stock, Cash, duplicate-reference, duplicate-submission, authorization and overlapping-process concurrency cases run with the simplified payload. Frontend checks cover the absent selector/state/payload field, required checkbox and existing real OCR/image/PDF upload behavior. No additional queries, API requests, listeners, polling or dependencies were introduced; OCR loading, configuration reads and data-cache invalidation are unchanged. Only the changed Grooming script/module URLs were versioned for deployment cache freshness.

The current dashboard was opened and visually inspected in the ChatGPT browser without navigation or data entry. Practical payment/receipt uploads, OTP/password-reset workflows and real account verification were left to the user. Application databases, private environment files, commits and deployment were untouched.

Validation for this simplification: PHP 8.4 `php artisan test --compact` passed **1,485 tests / 19,917 assertions**, with 8 existing opt-in skips. The focused QR/Grooming/schema/history/amount suite passed **89 tests / 1,805 assertions**, including authorization and isolated concurrent-confirmation coverage. `npm test` passed **75/75 scripts** and `npm run build` passed. JavaScript syntax and diff whitespace checks passed. No new test files replaced existing coverage. The concurrency fixture uses owned temporary SQLite storage; MySQL/Hostinger concurrency was not newly certified for this UI cleanup. Existing non-blocking Browserslist/Node VM notices and the deliberate corrupt-PDF fixture notice remain. No known failing checks remain. Logs: `storage/logs/qr-verification-php-tests.log`, `storage/logs/qr-verification-npm-tests.log`, and `storage/logs/qr-verification-build.log`.

## QR Payment UX and upload follow-up

The existing Grooming Record Payment modal now keeps the service breakdown, totals, add-ons, early/final collection, sticky header/footer and single scrollable body. QR collection shows the official image and amount due, followed by a collapsed **Scan Payment Details** control, Transaction Reference, Payment Date & Time, Amount Received and **Verified in the clinic's receiving account**. Administrator reference exceptions are inside **More Options**; Notes stays secondary. The ordinary recipient input was removed; extracted recipient information instead produces a mismatch warning. Amount Received is never initialized from Total Due, and OCR never acknowledges account verification.

Receipt upload remains functional through browse, drop and clipboard paste. A selected-file indication and small image preview accompany progress, cancellation, success and manual-entry errors. Pasting opens the scan panel. PNG/JPEG/WebP use the existing local Tesseract engine without cropping. PDFs use full-page PDF.js rendering, loaded only for a selected PDF, with a 5 MB file limit, two-page limit, at most four million rendered pixels per page, a 16-million-pixel embedded-image limit and the existing 90-second overall OCR deadline. Password-protected or unreadable files fall back to manual entry. Receipt bytes and recipient suggestions remain local; only reviewed transaction fields and existing verification metadata reach the API. Missing OCR fields preserve manual entries, while every extraction clears verification acknowledgments.

Settings now has a styled browse/drop uploader, immediate source preview and an inline square crop editor. Cropper.js 1.6.2 and jsQR 1.4.0 load only when an administrator selects an image. A detected region suggests a crop; the administrator may zoom, reposition, reset, cancel, apply the crop or explicitly retain a readable suitable original. The approved result is previewed before Save. Crops must retain the detected code, decode successfully and retain a quiet zone; output is capped at 2048 pixels and 3 MB and rechecked after resizing. Suitable originals remain unchanged and must satisfy the existing server limits. A decoder establishes readability, not official ownership or payment-network eligibility; the receiving-account acknowledgment remains required for replacements. Cropper, object URLs, canvases and workers are released when done.

The API now reports administrator configuration status as **Not Ready**, **Disabled** or **Enabled**, with concise missing-field identifiers. Disabled partial configurations can be saved. Activation with missing image, account name or bank/e-wallet returns errors for the individual fields without changing persisted settings. A complete disabled setup stays unavailable in Grooming. Successful saves use the returned settings/version and fetch the authenticated persisted image once; no redundant settings GET is added. Loading and saving share one reconciliation method that normalizes null account values to empty fields, preventing a later submission from turning missing information into the literal string `null`. A preview failure retains the committed version and cannot cause an old image/version to be resubmitted accidentally. Version-conflict protection and private server-side file validation remain intact.

Active method, success and history labels now say **QR Payment**. The shared gateway label helper supplies the QR label. Database columns, `qrph` values, supported network behavior and historical financial relationships are unchanged. No Clinic/POS collection interface, schema change, gateway, bank integration, environment edit, commit or deployment was added.

Validation performed for this follow-up:

| Check | Result |
| --- | --- |
| PHP 8.4 `php artisan test --compact` | 1,476 passed, 8 existing opt-in skips; 19,871 assertions |
| Final focused Settings/QR/Cash/pricing/schema/history/amount suite | 87 passed; 1,833 assertions |
| Owned isolated MySQL/MariaDB QR runner | 57 passed; 1,010 assertions; temporary schema removal verified |
| `npm test` | 75/75 regression scripts passed |
| `npm run build` | Local payment asset packaging, Tailwind and Vite passed |
| `npm audit --omit=dev` | Zero reported production dependency vulnerabilities |
| Focused final OCR/crop/settings checks | Passed, including actual OCR on image-based PDF pages, crop decoding, cancellation and local URL cleanup |

Existing test files were updated directly for labels, configuration status, per-field errors, disabled availability, version/image reconciliation, approved-result submission, cancel/invalid replacement, permissions, receipt drop/paste, manual fallback and cleanup. The new focused crop regression uses controlled QR pixels and an actual decoder to test square suggestions, output readability, quiet-zone preservation and rejection of unreadable/cut-off crops. Existing authorization, Cash, multi-pet early/final settlement, historical gateway/invoice, stock integrity, duplicate-reference/submission and overlapping-process regression coverage passed. The eight full-suite skips remain unrelated opt-in MySQL integrations; the existing QR concurrent-confirmation test uses isolated SQLite locking, so this report does not certify concurrency on Hostinger.

No additional configuration requests, polling or global clipboard listeners were introduced. OCR, QR detection/cropping and PDF rendering stay out of ordinary page navigation and financial database transactions. Existing data fetching, caches and invalidation remain in place, and unchanged API script cache URLs were retained across pages. The new scoped stylesheet is loaded only by Grooming and Settings. Core Web Vitals were not measured. `npm test` now packages ignored local dependencies before running, so a clean dependency install does not rely on previously generated browser assets.

Modified existing files for this follow-up:

```text
.gitignore
app/Http/Controllers/PaymentController.php
app/Http/Controllers/QrPaymentSettingController.php
app/Models/GatewayPayment.php
app/Models/QrPaymentSettings.php
app/Services/QrPaymentVerificationService.php
css/output.css
docs/qr-payments.md
package.json
package-lock.json
pages/admin/appointments.html
pages/admin/settings.html
pages/admin/transactions.html
scripts/build-ocr-assets.mjs
scripts/components/admin-dashboard.js
scripts/components/admin-settings.js
scripts/components/qr-payment.js
scripts/services/payment-proof-ocr.js
tests/Feature/GroomingPaymentPriceSafetyTest.php
tests/Feature/PaymentSchemaCompatibilityTest.php
tests/Feature/QrPaymentSettingsTest.php
tests/Feature/TransactionHistorySearchTest.php
tests/admin-grooming-invoice-print-regression.cjs
tests/admin-grooming-settings-regression.cjs
tests/admin-transactions-details-regression.cjs
tests/qr-payment-ocr-regression.mjs
```

Added: `css/components/qr-payment.css`, `scripts/services/qr-image-editor.js`, `scripts/services/payment-receipt-pdf.js`, `tests/qr-image-editor-regression.mjs`, and `docs/superpowers/plans/2026-10-23-qr-payment-ux.md`. Removed files: none. The obsolete recipient input, native visible file controls, expanded exception controls and old active display labels were replaced in place. Generated/ignored assets now also include `scripts/vendor/qr-image/` and `scripts/vendor/pdf/`; `npm run build` packages these locally with their licenses. Future deployments must include these generated static assets alongside the existing OCR assets.

Actual localhost navigation, real receipt/GCash image uploads, QR scanning on a payment device and real receiving-account verification were left to the user. No application/production records were modified or migrated; only owned test databases and fake storage were used. The existing corrected QR migration must be applied by the user if still pending; no new migration is required for this follow-up. Normal settings configuration still requires the official receiving image and identity, plus explicit activation. Known non-blocking notices are the existing Browserslist data warning, Node VM warning, PDF.js recovery notice for the deliberately invalid PDF fixture, and development dependency audit advisories. No known failing automated checks remain.

The integration uses the upstream [Cropper.js v1 API](https://github.com/fengyuanchen/cropperjs/tree/v1) and [PDF.js document/render API](https://mozilla.github.io/pdf.js/api/draft/module-pdfjsLib.html). Earlier implementation and migration history follows; the interface, cropping and PDF behavior above supersedes the original UI description below.

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

Staff check the actual receiving-account transaction, enter or scan the exact amount received, actual external reference and transaction date/time in Philippine time, then explicitly acknowledge receipt of funds in the clinic's account. The backend records the receiving-account source. The server recalculates the total and rejects mismatches, unavailable/stale configuration, ineligible visits, unauthorized actors, missing acknowledgment and reused references.

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

Image validation and administrator attestation do not prove bank ownership, decode/validate a QR Ph payment payload, or establish settlement. OCR is functional extraction, not verification of funds. Staff must check the actual transaction in the clinic's receiving account; bank app formats and recognition accuracy still require practical review. A reference-free exception cannot provide the same cross-payment identity guarantee as a unique official reference. Verification records have no editing endpoint; any later correction workflow must retain the original audit information.

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
