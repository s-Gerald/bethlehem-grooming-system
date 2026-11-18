# Owner Identity Implementation Plan

**Goal:** Let the Owner edit personal names independently of credentials and consistently identify staff/admin actors as `First Name · Role`.

**Architecture:** Keep Owner authorization separate from staff/manager self-service. Reuse HumanName, the existing account payload and navigation cache. Rename the payment helper to actorAttribution and use it for compact actor labels, preserving formal names and historical snapshots.

**Tech Stack:** Laravel/PHP, Alpine.js, Tailwind, PHPUnit and Node regression harnesses.

**Spec:** User request in this chat, sections 1–11; repository AGENTS.md.

## Constraints and review focus

- No schema changes or changes to Owner/Manager authority, credential OTP, session handling, or payment calculations.
- Only first_name and last_name accepted by the Owner endpoint; reject extra fields.
- Preserve failed form entries, block duplicate/no-op saves, and supersede stale identity reads.
- Eager-loaded attribution consumers must include role/admin_type/staff_subrole; no per-row queries.
- Preserve existing activity rows and seed ownership checks; fill missing seed name components independently.

## Tasks

1. Owner profile API: extend AdminSecurityController with updateOwnerProfile, share name normalization/rules and profile response with updateStaffProfile; add owner-middleware PATCH /settings/security/owner-profile. Extend AdminCredentialChangeTest for valid/invalid names, hostile fields, forbidden Manager/Staff, session preservation and revision changes. Run red then green.
2. Actor attribution: rename User::paymentAttribution to actorAttribution; migrate PaymentController, AdminClinicController, PosTransaction, ActivityRecorder and focused related actor producers. Preserve full professional/selection names. Add Manager to ActivityLogController options. Extend transaction/activity tests, including unchanged historical rows, and run red then green.
3. Seeder: preserve filled existing first/last names separately in AdminSeeder. Extend PrivilegedCredentialSecurityTest for personalized, missing-component and new Owner seeding; retain collision/safety tests. Run red then green.
4. Settings and navigation: add separate responsive profile form to pages/admin/settings.html and state/actions to admin-settings.js using HumanName. Share API profile identity synchronization for Owner/Staff in scripts/api.js. Replace Owner sidebar conditional bindings across active shells, showing first name and operational role. Extend existing Node behavior tests for errors, submission guards, authoritative cache updates, race protection and Owner/Manager rendering; run red then green.
5. Verification: run smallest affected PHPUnit/Node files first; expand to affected Manager/security, payments/printing, clinical attribution and frontend suites. Build CSS if required, inspect responsive form and review git diff. Report exact commands and distinguish verified pre-existing failures.

Execution stays in this checkout as requested; no automatic commits, deployment, or optional workflow changes.

## Implementation and verification

Tasks 1–4 are complete. Owner profiles use the Owner-only endpoint, strict HumanName validation, and the existing identity response/cache. Staff/Manager unchanged legacy-name handling and credential behavior are preserved. Additional compact actor consumers covered by the focused search are inventory movements, grooming cancellations (customer full names preserved), vaccination recording/publishing/voiding, medical record recording, confinement observations, and new clinical/QR verifier snapshots. Existing stored snapshots are retained.

The Settings addition uses existing Bethlehem styles and Phosphor icons. Labels, error descriptions, disabled/loading states, and separate profile/credential actions were checked. Desktop and 320px screenshots were visually inspected; automated layout checks also cover 375, 414, and 768px. No new design system or page redesign was introduced.

### PHP commands

Run from `C:/bethlehem-clinic-frontend` with the documented interpreter:

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $clinicPhp vendor/bin/phpunit tests/Unit/ActorAttributionTest.php tests/Feature/AdminCredentialChangeTest.php tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/AccountSecurityActivityTest.php tests/Feature/ActivityLogTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/HumanNameTest.php tests/Feature/TransactionHistorySearchTest.php tests/Feature/InventorySecurityRegressionTest.php tests/Feature/AdminVaccinationApiTest.php tests/Feature/ClinicConfinementTest.php tests/Feature/ClinicAdministrationAuthorizationTest.php tests/Feature/ClientPetProfileTest.php tests/Feature/SharedPayMongoTest.php tests/Feature/GroomingPayMongoTest.php tests/Feature/PaymentSchemaCompatibilityTest.php tests/Feature/GroomingProductSaleTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php tests/Feature/GroomingCancellationTest.php tests/Feature/QrPaymentSettingsTest.php
& $clinicPhp vendor/bin/phpunit tests/Feature/ActivityLogTest.php tests/Feature/PaymentSchemaCompatibilityTest.php
```

Results: the affected run passed **890 tests / 12,786 assertions**. The final two-file run, including the subsequently added named QR-verifier regression and complete historical-row assertion, passed **26 tests / 619 assertions**. Logs: `storage/logs/owner-identity-phpunit.log` and `storage/logs/owner-identity-final-phpunit.log`.

Before implementation, focused runs reproduced missing Owner-route responses, grooming/POS full-name attribution, Owner username activity attribution, and seeder name overwrites. Further focused runs covered invalid unchanged Owner names, inventory/vaccination/monitoring actor labels, and grooming cancellation attribution. All are resolved. The first broader run exposed a new QR nameless-verifier compatibility regression; preserving the existing empty-string fallback fixed it, including concurrent settlement coverage.

### Frontend commands

```powershell
node tests/admin-security-settings-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/admin-loading-regression.cjs
node tests/admin-transactions-details-regression.cjs
node tests/frontend-pos-regression.cjs
node tests/human-name-regression.cjs
npm run build:css
$env:PATH = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84;' + $env:PATH
npm test
git diff --check
```

All focused scripts pass. The full frontend suite passes **104/104 regression scripts**, including transaction detail, payment, POS/invoice, printing, security, and loading regressions. The CSS build passes and produces no generated CSS diff. `git diff --check` passes. Full frontend output: `storage/logs/owner-identity-frontend.log`.

### Browser commands

The server runs locally with PHP 8.4 from the `public` directory:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' -S 127.0.0.1:8000 C:/bethlehem-clinic-frontend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

In a second shell, from the repository root:

```powershell
$env:PLAYWRIGHT_PATH = 'C:/Users/Gerald/AppData/Local/npm-cache/_npx/e41f203b7505f1fb/node_modules/playwright'
$env:LOCAL_REVIEW_SCREENSHOTS = 'C:/bethlehem-clinic-frontend/storage/app/owner-identity-review'
$env:LOCAL_REVIEW_CASES = 'owner-profile,manager'
node tests/admin-sidebar-first-paint-browser.cjs
$env:LOCAL_REVIEW_CASES = 'owner-profile,cache,pre-alpine'
$env:LOCAL_REVIEW_PROFILES = 'admin,manager'
node tests/admin-sidebar-first-paint-browser.cjs
```

The browser harness intercepts API requests with isolated fixtures; it does not edit real accounts. Owner form checks cover blank names, preserved server-rejected values, independent saving, immediate sidebar initials/name/role, no redundant `/me`, unchanged disabled state, and absence of credential-change requests at 320/375/414/768/1440px. Owner and Manager account Security workflows passed at 1440px and 390px. Screenshots are in `storage/app/owner-identity-review`.

The default XAMPP PHP 8.2 executable was rejected by the repository's existing bootstrap; all reported PHP results use PHP 8.4. No remaining application test failures have been observed. No schema migration was added. Changes remain local and uncommitted; deployment was not performed.

### Continuation verification

The working tree already contained the Owner profile and sidebar, shared attribution, Activity Log updates, and seeder changes. The continuation corrected the clinical options response used by the medical record “Recorded By” fallback and updated the dashboard dependency test to expect the current sidebar binding. The clinical regression first failed on `Admin User` and passed with `Admin · Admin`. It also verifies that veterinarian choices retain full names.

```powershell
& $clinicPhp vendor/bin/phpunit tests/Unit/ActorAttributionTest.php tests/Feature/AdminCredentialChangeTest.php tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/AccountSecurityActivityTest.php tests/Feature/ActivityLogTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/TransactionHistorySearchTest.php tests/Feature/PaymentSchemaCompatibilityTest.php
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminVaccinationApiTest.php --filter test_medical_record_options_reuse_active_veterinarians_without_loading_inventory
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminDashboardScriptDependenciesTest.php --filter test_staff_dashboard_has_a_stable_title_and_keeps_identity_in_the_sidebar
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminDashboardScriptDependenciesTest.php
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminVaccinationApiTest.php tests/Feature/ClinicAdministrationAuthorizationTest.php tests/Feature/ClinicConfinementTest.php tests/Feature/InventorySecurityRegressionTest.php tests/Feature/SharedPayMongoTest.php tests/Feature/GroomingPayMongoTest.php tests/Feature/GroomingProductSaleTest.php tests/Feature/SessionLockTest.php tests/Feature/IndependentAuthenticationSessionsTest.php
node tests/admin-security-settings-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/admin-loading-regression.cjs
node tests/admin-transactions-details-regression.cjs
node tests/admin-clinic-payment-regression.cjs
node tests/frontend-pos-regression.cjs
node tests/admin-activity-log-regression.cjs
node tests/human-name-regression.cjs
node tests/admin-clinic-medical-record-regression.cjs
node tests/admin-clinic-vaccination-reconciliation-regression.cjs
node tests/clinic-confinement-regression.cjs
& $clinicPhp artisan route:list --path=settings/security/owner-profile -vv
git -c core.safecrlf=false diff HEAD --check
```

The focused PHP run passed 240 tests and 4,067 assertions. The corrected dashboard suite passed 3 tests and 62 assertions. The affected clinic, payments, inventory, and session run passed 644 tests and 9,237 assertions. All 11 listed Node scripts passed. The route check showed one authenticated PATCH endpoint with Owner middleware. The final diff check found no whitespace errors.

On the final working tree, `& $clinicPhp vendor/bin/phpunit` reported 2,555 tests, 36,568 assertions, 10 skips, and zero failures. `npm test`, with the documented PHP 8.4 directory on `PATH`, passed 104 of 104 scripts. Full output is in `storage/logs/owner-identity-resume-full-phpunit.log` and `storage/logs/owner-identity-resume-frontend.log`.
