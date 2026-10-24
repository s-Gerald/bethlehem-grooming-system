# Clinic role workflows: release and verification

This change separates receptionist intake/payment actions from clinical actions. It preserves the existing blue interface, profile/history layout, clinical permissions, queue locks, API cache invalidation, request deduplication, deferred records, search debounce, and refresh intervals.

Receptionist cancellation is checked against the current status under the case row lock. Only `checked_in` can be cancelled. Receptionists cannot update existing pets through the admin update API; new-patient intake remains available. Walk-ins receive a queue number and check-in timestamp, with consultation starting only through an authorized start endpoint.

Clinic receipts and transaction invoices use the linked cash processor or successful online checkout initiator, formatted as `First Name · Role`. The existing invoice print path uses the same receipt data. Renamed/inactive identities remain readable; missing identities display the existing unavailable marker. Historical records use the currently linked identity, since the existing schema does not store identity snapshots. No identities or historical roles are invented or backfilled.

## Files in the release

Backend source:

- `app/Http/Controllers/AdminClinicController.php`
- `app/Http/Controllers/ClinicWalkinController.php`
- `app/Http/Controllers/OnlinePaymentController.php`
- `app/Http/Controllers/PaymentController.php`
- `app/Http/Controllers/PetController.php`
- `app/Models/User.php`

Static frontend source, which Vite does **not** bundle:

- `pages/admin/clinic.html`
- `pages/admin/walk-in-pet-details.html`
- `scripts/components/admin-clinic.js`
- `scripts/components/walk-in-pet-step.js`
- `scripts/components/walk-in-clinic-complaint-step.js`
- `scripts/components/walk-in-clinic-consent-step.js`

The clinic entry script and the complete walk-in import chain have `clinic-roles=20261024` cache versions. Unmodified scripts retain their existing versions.

Updated regression files are the existing ClinicAdministrationAuthorizationTest, SharedPayMongoTest, AdminNotificationCenterTest, TransactionHistorySearchTest, patient-profile, payment, navigation-performance, confinement, first-render, subrole-workflow, and confirmation regressions. No competing implementation or test suite was introduced. Superseded receptionist workspace access and full-name-only Clinic payment formatting were replaced in place.

## Verification results

- Focused Clinic authorization, shared PayMongo, confinement, and service billing run: **269 tests, 2,978 assertions passed** before the interruption. After limiting processor loading to the paths that need it, **13 relevant receipt/webhook/archive tests, 164 assertions passed**. The notification and transaction fixtures found by the broader run were updated and passed on targeted rerun.
- Frontend runner: **76/78 passed** initially. Both failing fixtures were corrected and their scripts passed on targeted rerun; all 78 scripts have passing results. Later role/default/deep-link, payment, confirmation, and visibility-related changes passed their relevant targeted checks. Successful scripts were not all rerun unnecessarily.
- `npm run build`: **passed**. The existing Browserslist data warning remains; no dependency update was made for it. Generated CSS matches the tracked CSS, so no stylesheet version change was needed.
- Changed PHP/JavaScript syntax checks and `git diff --check`: **passed**.
- Broader PHPUnit run: **1,603 tests**, initially 2 errors, 5 failures, 8 skips. Two failures/errors were obsolete Clinic-related fixtures and are fixed. Four file-readability cases passed outside the Windows sandbox: ExampleTest::test_the_application_returns_a_successful_response, GroomingFinishedEmailNotificationTest::test_email_has_simple_grooming_message_and_embedded_bethlehem_logo, GroomingPaymentPriceSafetyTest::test_receipt_is_private_linked_to_settlement_and_not_exposed_as_a_storage_path, and QrPaymentSettingsTest::test_private_image_and_versioned_settings_survive_reloads_replace_and_disable.
- **One unrelated failure remains:** GroomingAdministrationAuthorizationTest::test_archive_payload_has_constant_query_count_and_keeps_the_page_contract observes **14 queries against its limit of 13**. It also fails with all six changed application PHP classes loaded from unchanged HEAD sources, confirming it predates this implementation. Its production code and assertion were left intact. The full suite is not claimed green. The 8 suite-reported skips and real MySQL concurrency/payment acceptance are not claimed as passes.
- The browser's visible dashboard was inspected in the existing Grooming Receptionist session. No veterinarian session, browser data entry, OTP, password reset, or real payment acceptance was performed. Role-specific manual visual/functional acceptance remains with the user.

The initial broad logs remain under `storage/clinic-frontend-regressions.log`, `storage/clinic-phpunit-suite.log`, and `storage/clinic-production-build.log`. Those logs describe their original runs; the successful targeted reruns above supersede the resolved failures. Disposable test configuration/baseline files were removed after verification.

## Required release steps

1. Use the existing production Laravel/PHP environment and installed Composer dependencies. No dependency or environment-variable changes are required. Keep production `.env`, storage, and live data intact. Check runtime compatibility with the existing lock file:

   ```sh
   php -v
   composer check-platform-reqs --no-dev
   ```

2. On the build machine, install dependencies if this is a clean checkout, then run the existing production build:

   ```sh
   npm ci
   npm run build
   ```

   `npm ci` is unnecessary when the lock-file dependencies are already installed. The build packages OCR/QR/PDF vendor assets, generates `css/output.css`, and builds Laravel Vite assets into `public/build/`.

3. Through the existing Hostinger upload/release process, upload the backend and raw frontend files listed above. Include `css/output.css` and the complete generated `public/build/` directory, including its manifest and hashed assets. A complete release must also carry the generated `scripts/vendor/ocr/`, `scripts/vendor/qr-image/`, and `scripts/vendor/pdf/` directories already required by the build. Uploading only `public/build/` will **not** update the Clinic pages/scripts.

4. No migration is introduced or required by this change on an up-to-date installation. Existing prerequisites are the Clinic charges/processor columns, cash-received column, role columns, and shared gateway-payment schema. Inspect existing migration status if the production baseline is uncertain:

   ```sh
   php artisan migrate:status
   ```

   Apply only reviewed, previously pending migrations through the normal release process if that installation lacks its existing prerequisites. Never use `migrate:fresh`, reset, seed, or overwrite production records for this release.

5. Perform the user-owned acceptance checks: receptionist Expected/default and patient-history actions, checked-in cancellation/stale-status rejection, walk-in queue confirmation, veterinarian/admin operational sections, cash/online payments, and matching screen/printed invoice attribution. OTP/password-reset and real provider interactions remain manual.

## Optional cache/worker steps

No routes or configuration changed, so route/config cache rebuilding is not required. If the established release procedure rebuilds those caches, use:

```sh
php artisan config:cache
php artisan route:cache
```

If long-lived Laravel queue workers are running and need to load new application code:

```sh
php artisan queue:restart
```

Purge a hosting/CDN HTML cache only if one is configured and still serves the old entry pages. The versioned scripts handle ordinary browser asset caching. No production deployment or cache commands were executed during implementation.
