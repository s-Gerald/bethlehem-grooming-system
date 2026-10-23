# Restore PayMongo Alongside QR/OCR Implementation Plan

**Goal:** Restore test-mode hosted checkout for Grooming, Clinic and POS without changing manual QR/OCR or cash behavior.

**Architecture:** Reuse gateway payments, snapshots, unique provider identifiers, reservations and existing settlement services. External requests run outside financial locks; short transactions recheck the payable and attempt before applying provider results. Only an authenticated paid webhook settles; redirects and status retrieval never settle.

**Tech stack:** Laravel 12, PHP 8.4.26, framework HTTP client, Alpine, PHPUnit 11 and existing Node regressions.

**Spec:** User request in this chat; history `f572dfaa779d`, removal `fba4feac3434c4c13c268708ddf1255e7642552a`, baseline `bd45f36`.

## Constraints

- Stay on gerald-frontend; no commits, pushes, deployment, real charges or application database migrations.
- Preserve QR receipt privacy, OCR lazy loading, amount/reference checks, staff acknowledgment and current UI.
- Test credentials only; no dependencies or schema changes unless proven necessary.
- Preserve test-mode collection exclusions and historical financial compatibility.

## Review focus

- Unknown checkout creation outcome retains its hold and cannot create a second session.
- Provider expiry must exclude paid/processing intents, including nested V2 payment intents.
- Concurrent cancellation/webhook/cash/QR writes serialize on the same payable before the attempt.
- Confirmed payments with stock or payable conflicts retain reconciliation metadata and stock holds.
- Refresh/navigation resumes the exact attempt; polling is bounded and stops on close/navigation.

## Tasks

- [x] Recover the deleted GroomingPayMongoTest and SharedPayMongoTest selectively, inspect every expectation, add security regressions, and update PaymentSchemaCompatibilityTest's obsolete route assertion. Run to establish red tests under explicit PHP 8.4.
- [x] Restore PayMongoService, OnlinePaymentService and controllers, adapting webhook envelopes, explicit environment/identity checks, provider calls outside locks and safe expiry. Extend GatewayPayment helpers and existing settlement services; reserve Grooming add-ons and POS stock atomically. Verify focused backend tests.
- [x] Restore API wrappers, shared checkout recovery/watch helper and existing modal integration in admin-dashboard.js, admin-clinic.js and admin-pos.js; update the corresponding existing HTML, history labels and cache invalidation. Adapt useful historical Node regressions and obsolete current expectations. Preserve current manual QR UI and safeguards. Verify focused frontend regressions.
- [x] Run wider payment/report/authorization coverage, full PHPUnit where practical, npm test, npm run build, Composer platform checks and diff review. Document configuration, deployment commands and manual/provider checks in docs/paymongo-grooming.md.

## Outcome

Final focused suite: 170 tests / 1,927 assertions passed. Frontend: 78/78 scripts and production build passed. Full PHP run: 1,549 passed, 1 baseline archive query-budget failure, 8 existing opt-in MySQL skips. Composer/platform checks passed on PHP 8.4.26. Actual provider and browser end-to-end checks remain unverified; the available browser could not reach the isolated local fixture. Full deployment/manual checklist and file responsibilities are in `docs/paymongo-grooming.md`. No new migrations, application database writes, dependency changes, branch changes, commits, pushes or deployments.

## Verification commands

Use `C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe` with `vendor/phpunit/phpunit/phpunit`; phpunit.xml forces SQLite :memory: and isolated config. Never run application migrations. Use `npm test` and `npm run build`; Composer validate and check-platform-reqs use the same PHP 8.4 executable.
