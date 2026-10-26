# Grooming service selection — implementation and verification

> This is the earlier service-selection change record. Its estimation architecture and old verification commands are superseded by [grooming estimation removal](grooming-estimation-removal.md).
Implemented on `gerald-frontend` on 2026-10-26. Changes are local and ready for review; nothing was pushed, deployed, or applied to production.

## Behavior

- Both flows share the pet-card renderer: identity and existing size behavior, required groomer, grouped services, and instructions beside monetary pricing on desktop (stacked on mobile).
- Service Rules and the duplicate per-pet section heading are removed. The error container is hidden until there is an actual error.
- Regular and Deluxe retain their existing radio choices. Custom Hairstyle requires instructions. Cat Full Grooming has an independent Include haircut checkbox, default false.
- Active haircut requests update and focus only that pet's textarea. Reduced motion uses immediate scrolling. Initial render, restoration and Back navigation do not steal focus. Existing notes survive toggles.
- `haircutRequested` in drafts/reviews maps to the explicit `haircut_requested` booking-pet boolean in the API/database. Review, confirmation, customer visit data and staff selection labels expose the request.
- Database catalogue inclusions drive Facial Trimming availability and pricing for Partial, Regular and Deluxe at every dog size. Direct API requests cannot add an included service as another charge. Bath and Go and cat inclusions are preserved.
- Service-selection duration displays and walk-in factor controls/payload fields are removed. Legacy walk-in factor fields are ignored. Shared historical and operational duration code remains.

## Files and responsibilities

### Shared frontend rendering, rules and groomer controls

- `scripts/components/grooming-estimate-selection.js`
- `scripts/components/pet-groomer-selection.js`
- `scripts/services/grooming-service.js`
- `scripts/components/grooming-service-selection.js`

### Flow controllers, review, consent and visit presentation

- `scripts/components/admin-dashboard.js`
- `scripts/components/booking-confirmed-step.js`
- `scripts/components/booking-consent-step.js`
- `scripts/components/booking-review-step.js`
- `scripts/components/booking-services-step.js`
- `scripts/components/client-dashboard.js`
- `scripts/components/grooming-pre-registration.js`
- `scripts/components/grooming-visit-summary.js`
- `scripts/components/walk-in-consent-step.js`
- `scripts/components/walk-in-pet-step.js`
- `scripts/components/walk-in-review-step.js`
- `scripts/components/walk-in-services-step.js`

### Screen markup, styles and asset cache versions

- `css/grooming-estimates.css`
- `css/output.css`
- `pages/admin/appointments.html`
- `pages/admin/dashboard.html`
- `pages/admin/walk-in-booking-confirmed.html`
- `pages/admin/walk-in-booking-review.html`
- `pages/admin/walk-in-consent.html`
- `pages/admin/walk-in-grooming-services.html`
- `pages/admin/walk-in-pet-details.html`
- `pages/client/grooming-pre-registration.html`

### Backend validation, visit persistence and API data

- `app/Http/Controllers/AdminBookingController.php`
- `app/Http/Controllers/BookingController.php`
- `app/Http/Controllers/WalkinController.php`
- `app/Http/Requests/StoreWalkinRequest.php`
- `app/Models/BookingPet.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/GroomingTimeEstimate.php`
- `app/Services/GroomingServiceSelection.php`

### Forward migrations and catalogue defaults

- `database/data/grooming-catalog.json`
- `database/migrations/2026_10_26_000001_add_haircut_requested_to_booking_pets.php`
- `database/migrations/2026_10_26_000002_include_facial_trimming_in_dog_packages.php`

### Updated existing tests and fixtures

- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingManagedCatalogTest.php`
- `tests/admin-grooming-pricing-regression.cjs`
- `tests/grooming-catalogue-fixture.json`
- `tests/grooming-estimates-regression.cjs`
- `tests/grooming-managed-catalog-regression.mjs`
- `tests/grooming-price-presentation-regression.mjs`
- `tests/named-pet-groomers-regression.mjs`
- `tests/walk-in-capacity-preview-regression.cjs`

### Implementation plan

- `docs/superpowers/plans/2026-10-26-grooming-service-selection.md`

## Database and Hostinger release requirements

1. Include both new migrations, `database/data/grooming-catalog.json`, updated PHP/JS/HTML/CSS and generated `css/output.css` in the normal release.
2. Run `php artisan migrate --force` using the production CLI configuration before exposing the updated application. The first migration adds `booking_pets.haircut_requested` with a false default. The second idempotently appends Facial Trimming's existing service ID to only Partial, Regular and Deluxe dog packages.
3. These migrations deliberately have no destructive rollback. They do not reseed, reset data, rewrite transaction amounts or touch historical service snapshots. Existing price/status/description settings remain intact.
4. Build assets with `npm run build` in the normal deployment workflow. Module and stylesheet query versions have been updated consistently, including dynamic catalogue consumers.
5. Apply normal Laravel cache refresh/reload steps for the existing release process. No new environment variables, dependencies, URLs, queue settings or permissions are required.

## Tests and results

Updated existing Node and PHPUnit tests in place; no parallel replacement suite was introduced. Coverage includes instruction whitespace and flags in both APIs, per-pet restoration and focus, conditional state, all four dog sizes, authoritative inclusions, direct-API duplicate-charge rejection, independent monetary range examples, ignored legacy factor inputs, groomer behavior and unchanged operational estimates.

Commands run:

```powershell
$groomingPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
$env:PATH = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84;' + $env:PATH
$env:APP_CONFIG_CACHE = 'storage/framework/testing-config-unused.php'
npm test
npm run build
& $groomingPhp vendor/phpunit/phpunit/phpunit --display-skipped tests/Feature/GroomingAdministrationAuthorizationTest.php tests/Feature/GroomingManagedCatalogTest.php tests/Feature/GroomingPricingCatalogueTest.php tests/Unit/GroomingTimeEstimateTest.php tests/Unit/GroomingWorkloadCapacityTest.php tests/Feature/GroomingSinglePageInterfaceTest.php tests/Feature/GroomingReviewAndConsentInterfaceTest.php tests/Feature/CustomerGroomingVisitSummaryTest.php
git diff --check
```

- Frontend: **81/81 regression scripts passed**, including **2,177 server/preview estimate comparisons**.
- Backend: **429 tests, 5,557 assertions; one skipped MySQL concurrency test**. All executed tests passed using PHP 8.4 and the repository's normal bootstrap.
- Production build: **passed**. Existing Browserslist data is outdated; this advisory did not block the build.
- Browser: both real service controllers exercised against local fixture API boundaries at desktop and 390px mobile width. Verified notes/focus/error independence, whitespace errors, toggling, package deselection, facial pricing, Back → Review state, no initial focus stealing, and reduced motion. No horizontal overflow observed.
- Fetching: one existing catalogue load and one existing groomer load on initial entry; selection toggles add no requests. Back re-entry retains the existing load behavior. No new dependencies or polling.
- Diff whitespace check: passed.

## Limits and preserved operations

The SQLite test run skips `test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql`; its runner is `tests/grooming-capacity-concurrency.php` and needs an isolated MySQL database. MySQL locking/concurrency was not re-tested here. Core Web Vitals were not benchmarked against production traffic.

**System-wide grooming estimation was NOT removed.** GroomingTimeEstimate, factor rules used by other operational screens, duration snapshots, workload forecasting, admission/reservation capacity checks, scheduling, arrival windows, queue assignment, payments and authorization remain in place. Unit and integration tests verify operational duration and capacity behavior. Production migrations have not been run.
