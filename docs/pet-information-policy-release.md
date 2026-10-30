# Pet information policy release notes

Implemented locally on `gerald-frontend` in the existing workspace. The configured `backend` remote points to `rij-summit/bethlehem-grooming-system`; no remote push or deployment was performed.

## Implemented behavior

- Customer pet name and species are fixed after creation. Each optional field can be completed once. Meaningful saved values, including Unknown Fur Type, remain locked even if staff later clears them.
- One nullable `pets.customer_locked_fields` JSON column records lifetime locks. Existing profiles remain compatible: current meaningful values are protected immediately, and their lock metadata is retained on profile writes. Initially blank optional fields stay available. There is no record reset or bulk backfill.
- Customer updates are checked against the latest pet row under a transaction and row lock. Ownership, unchanged legacy values, and clinic verification are preserved, including verification flags added concurrently by another staff update. Either verified weight or size protects both fields. First-time weight completion preserves an already saved size and computes size only when it is blank.
- Saved-pet grooming submissions send a profile ID and visit-specific data. The backend preserves the profile, rejects conflicting nonempty profile changes and invalid ownership, and prices using the authoritative grooming size. Clinic online registration continues selecting the owned existing profile.
- Species is explicitly required for new pets; silent Dog defaults were removed from customer creation and related selection/editor code. Breed and fur are optional. Unknown Fur Type is last for every supported breed, including blank/custom/mixed/unknown breeds; catalogue choices, legacy values and shared fetching are retained.
- Existing My Pets forms use subtle locked inputs, the existing Phosphor lock-key icon, existing Verified indicators, one correction sentence, one pre-confirmation warning, and Confirm/Confirming labels. Unchanged or invalid edits cannot confirm. Online pet confirmation still saves and selects in one action. Button styling and modal/focus behavior are retained.
- Existing staff authority remains. Weight/size update calculation is shared across profile and walk-in edits. Unchanged confirmed size is retained; genuine weight changes still use the original thresholds.
- A single atomic Pet save path creates one existing-type in-app notification for actual persisted staff/admin profile changes. Messages identify Bethlehem Animal Clinic, pet and changed fields, including name corrections. Initial creation, no-ops, metadata-only verification/archive changes and rolled-back operations do not notify. Both walk-in update paths are covered. No new delivery channel was added.
- Notifications retain existing retrieval, unread state, caching and pet destinations. Names and fields receive selective emphasis after escaping plain text, including the dashboard custom-renderer path; historical names remain emphasized after a later rename.

## Existing code replaced

- Removed customer clinic-verification override confirmation and verification clearing. Archive/restore confirmations remain.
- Removed profile writes from customer saved-pet booking selection.
- Removed required-fur validation and automatic coat selection from the shared control.
- Removed duplicate pet-update notification writers from PetController and size confirmation; notification generation is centralized in the atomic Pet save.
- Reused shared field comparison and staff weight/size calculation rather than adding form-specific policies.
- Removed forced active/archive refreshes before every customer confirmation; the existing cache/prefetch and post-mutation invalidation remain.
- Updated obsolete tests and existing hand-built pet schemas for the new column. No tests were skipped, deleted, or weakened to hide failures.

## Verification

- Final affected PHP suite: **511 tests, 7,763 assertions, no failures, 1 existing skip**. Includes pet profiles, catalogue validation, both registration/walk-in workflows, pricing/measurements, UI contracts, local icons and migration coverage.
- Full PHP suite: **2,086 tests, 27,644 assertions, 1 failure, 8 skips**. The failure is unchanged `EmailVerificationTest::test_warning_mail_is_encrypted_durable_and_contains_no_authentication_material`: its queue worker exits **12**, Laravel's memory-limit code, during the full process. The test passes in isolation. The additional final grooming walk-in notification and concurrent-verification cases pass in the focused suite.
- Final complete frontend suite: **86/87 scripts pass**. Unchanged `admin-loading-regression.cjs` fails because its Settings API mock omits `getSecurityActivity`. The production Settings implementation and this test were not edited. The failure was reproduced separately.
- Shared breed/fur regression: **73/73 cases pass**, including all catalogue breeds, optional blank/custom cases, legacy values, explicit choices, Unknown Fur Type ordering and one catalogue request.
- Customer cache/modal, notification escaping/emphasis, saved-pet submission, weight/size, grooming age/flow and clinic-role regressions pass. Lifetime locks and stale competing submissions are covered. Migration up/down preserves existing rows and verification.
- Production `npm run build` passes, including generated policies, OCR/QR/PDF assets, Tailwind and Vite. Only the existing outdated Browserslist database notice remains.
- Changed PHP syntax checks and `git diff --check` pass. Existing query-growth checks retain 5 queries for both 3 and 13 customers, and the clinic list fixture retains 12 queries.
- All PHP tests use the repository's isolated SQLite database and test delivery configuration. No production data was modified; delivery tests used isolated test transports only.

## Hostinger rollout

1. Include `database/migrations/2026_10_30_000001_add_customer_locked_fields_to_pets.php` and run `php artisan migrate --force` with the deployment's supported PHP CLI **before serving the new Pet model code**. Coordinate this with the existing deployment process. This adds one nullable JSON column only; it does not rewrite existing pet information.
2. Deploy the updated Laravel files together with the changed static `pages/`, `scripts/`, and `css/output.css`. Run the existing `npm run build` on the build host and include its `public/build/` output. Static portal files are still served through the existing project structure, so deploying only the Vite manifest is insufficient.
3. Cache-busting URLs use `pet-fields=20261030-v1` and the updated notification script version throughout affected entry points and imports. Keep the existing catalogue and customer/notification caching strategy.
4. No Composer/npm dependency changes, environment variables, route migrations, notification tables or queue configuration changes are required. Keep the existing PHP/production-database prerequisites documented in README.

## Verification limits

- Live visual/responsive browser review could not finish: the in-app browser could not reach the isolated loopback fixture, and native computer use stopped because it could not verify the browser URL. The temporary server was stopped and removed. Automated form, modal, accessibility-contract and icon checks passed; no claim of screenshot verification is made.
- Native MySQL concurrency and the actual Hostinger runtime were not exercised. Row locking uses Laravel's existing database transaction APIs; stale-submission behavior is covered on the isolated test database. The full suite's environment-specific skips are reported above.

## Changed-file inventory

### Application and migration

- `app/Http/Controllers/BookingController.php`
- `app/Http/Controllers/ClinicWalkinController.php`
- `app/Http/Controllers/CustomerNotificationController.php`
- `app/Http/Controllers/PetController.php`
- `app/Http/Controllers/WalkinController.php`
- `app/Models/Pet.php`
- `app/Rules/ValidBreedCoat.php`
- `app/Support/BreedCoatCatalog.php`
- `app/Support/PetInformation.php`
- `app/Support/PetWeightSize.php`
- `database/migrations/2026_10_30_000001_add_customer_locked_fields_to_pets.php`

### Frontend and assets

- `css/input.css`
- `css/output.css`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `pages/admin/pet-edit-form.html`
- `pages/admin/walk-in-pet-details.html`
- `pages/client/booking-pet-details.html`
- `pages/client/dashboard.html`
- `pages/client/grooming-history.html`
- `pages/client/grooming-pre-registration.html`
- `pages/client/my-pets.html`
- `pages/client/notifications.html`
- `pages/client/pet-details.html`
- `pages/client/settings.html`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-customers.js`
- `scripts/components/booking-consent-step.js`
- `scripts/components/booking-pet-step.js`
- `scripts/components/breed-coat-catalogue.js`
- `scripts/components/breed-coat-combobox.js`
- `scripts/components/breed-combobox.js`
- `scripts/components/client-notification-ui.js`
- `scripts/components/grooming-pre-registration.js`
- `scripts/components/my-pets.js`
- `scripts/components/walk-in-pet-step.js`
- `scripts/services/pet-service.js`

### Tests and fixtures

- `tests/Feature/ActivityLogTest.php`
- `tests/Feature/AdminCustomerPetSearchTest.php`
- `tests/Feature/AdminDashboardCustomerSearchTest.php`
- `tests/Feature/AdminDashboardSummaryTest.php`
- `tests/Feature/AdminNotificationCenterTest.php`
- `tests/Feature/AdminPetProfileTest.php`
- `tests/Feature/AdminUnregisteredCustomerTest.php`
- `tests/Feature/AdminVaccinationApiTest.php`
- `tests/Feature/BreedCoatMigrationTest.php`
- `tests/Feature/ClientPetMedicalRecordsTest.php`
- `tests/Feature/ClientPetProfileTest.php`
- `tests/Feature/ClientPetVaccinationsTest.php`
- `tests/Feature/ClinicAdministrationAuthorizationTest.php`
- `tests/Feature/ClinicCaseServiceBillingTest.php`
- `tests/Feature/ClinicConfinementTest.php`
- `tests/Feature/ClinicInventoryPricingTest.php`
- `tests/Feature/CustomerGroomingVisitSummaryTest.php`
- `tests/Feature/CustomerPageLoadingPerformanceInterfaceTest.php`
- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingPayMongoTest.php`
- `tests/Feature/GroomingPaymentPriceSafetyTest.php`
- `tests/Feature/PaymentSchemaCompatibilityTest.php`
- `tests/Feature/PetInformationVerificationInterfaceTest.php`
- `tests/Feature/PetVerificationMigrationTest.php`
- `tests/Feature/QueuePreRegistrationModelTest.php`
- `tests/Feature/SharedPayMongoTest.php`
- `tests/Feature/TransactionHistorySearchTest.php`
- `tests/Feature/VaccinationRecordFoundationTest.php`
- `tests/Support/CreatesGroomingVisitFixture.php`
- `tests/Unit/BreedCoatCatalogTest.php`
- `tests/breed-fur-fields-regression.mjs`
- `tests/client-notification-ui-regression.cjs`
- `tests/customer-data-cache-regression.cjs`
- `tests/preferred-arrival-window-regression.cjs`

### Documentation

- `docs/pet-information-policy-release.md`
- `docs/superpowers/plans/2026-10-30-pet-information-policy.md`

