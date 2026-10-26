# On-site FCFS registration — implementation and release notes

Implemented on `gerald-frontend` in the existing Bethlehem application. No Hostinger deployment was performed.

## Customer workflow

- Grooming: Pet Information → Grooming Services → Review Online Registration → Consent → Registration Received. Four editable steps, progressing 25%, 50%, 75%, 100%.
- Clinic: Pet Information → Reason for Visit → Review Online Registration → Registration Received. Three editable steps, progressing 33%, 67%, 100%.
- Review pages retain complete service/patient summaries and use a separate, supplemental Queue Information card. Grooming retains independent packages, extras, preferences, groomers, instructions, size verification and per-pet prices/ranges. Pet subtotals and the overall total are distinct.
- The waiting count is an aggregate for the selected service. Grooming counts unstarted pets in checked-in/in-progress bookings, excluding incoming, cancelled, finished and currently groomed pets. Clinic counts checked-in patients. No customer data is returned by this endpoint.
- Queue information loads once on review entry, uses the configured API base URL and `no-store`, and never blocks the form. Closed/future-day registrations and network failures receive neutral availability text.
- Confirmation shows the server reference, submission timestamp, valid service day, Waiting to Arrive and Not assigned. Staff walk-in confirmations retain Checked In and their assigned queue numbers.
- The customer dashboard now shows active Clinic registration/check-in details alongside the existing Grooming display; Grooming tracking includes pet queue numbers. Incoming staff lists distinguish registration from physical check-in timestamps.

## Server and compatibility decisions

`RegistrationServiceDay` uses Philippine time and the existing per-service hours and closure records. Before opening, today's next opening is eligible. During service hours, including the lunch break, a registration is valid today. After closing, or on an active closure, it is valid on the next open day; multi-day and overlapping closures are skipped without a per-day query loop. The existing settings do not define a weekly closed-day calendar.

Physical check-in still requires the valid service date and operating availability. The noon–1 PM break and stop-today/blocked-date controls reject physical intake. Grooming's existing on-site capacity remains authoritative at check-in; online registration does not reserve capacity. The existing expiry process expires unused registrations once their valid Philippine date has passed. Existing notification and scheduler commands remain in use; new no-window records receive valid-day reminders without an invented arrival time.

Online requests no longer require or use customer-supplied date/window fields. Old clients may still send them, but the server computes the date and saves a null window. Both database window columns are already nullable in the repository migrations, so **no new migration is required**. Historical window data and shared availability APIs remain readable. The retired customer reschedule endpoint returns HTTP 410; its dialog, handlers and orphaned styles are removed. Legacy arrival-picker URLs recover into the supported flow.

Booking `created_at` is set explicitly on submission because this model disables Eloquent timestamps. Clinic uses its existing timestamps. Neither is overwritten by check-in. Incoming lists use submission time and a stable ID tie-breaker.

## Queue integrity

Both online intake paths and physical check-in paths use `DailyPetQueue`'s existing transaction and shared MySQL advisory lock, acquired before queue/capacity reads. Clinic continues using `ClinicAppointmentSequence`; Grooming retains separate booking and pet sequences. Current/historical assigned numbers, including terminal records, remain part of the maximum calculation. Online registration creates only a unique reference, with no official booking/pet queue number.

Check-in locks the registration, validates intake, then assigns numbers and records the check-in timestamp atomically. A repeated request for an already checked-in record returns its existing assignment without inserting an active entry or changing timestamps. Existing staff check-in correction and later status transitions are retained.

A focused regression exposed SQLite's date serialization in the old pet-queue maximum lookup. Its replacement uses an inclusive/exclusive date range, which also preserves index-friendly comparisons on MySQL DATE columns.

## Verification performed

| Check | Actual result |
| --- | --- |
| Full frontend suite, `npm test` | **80/80 regression scripts passed** |
| Full PHP suite, PHP 8.4.26 / PHPUnit 11.5.55 | **1,730 tests, 23,416 assertions, 8 skips; no failures** |
| Isolated MariaDB 10.4.32 concurrency harness | **1 test, 69 assertions passed** |
| Production assets, `npm run build` | **Passed** |
| `git diff --check` | **Passed** |
| Running Grooming page | Inspected existing draft and review visually; no form data entered or submitted |

The normal PHP suite's eight skips are six opt-in dashboard MySQL cases, the isolated registration-concurrency case and the opt-in phone timestamp migration test. The registration-concurrency case was separately executed successfully against a verified, locally owned disposable database. It observed real overlapping GET_LOCK contention for Grooming submissions, Clinic submissions and both services' check-ins, verified separate sequential numbers and safe retries, and verified database cleanup.

Earlier runs caught obsolete arrival/reschedule expectations and module-version fixture mismatches, which were updated. An unrelated full-suite email worker test initially exited with Laravel's memory-limit code 12; it passed in isolation. Its test-only `queue:work` command now uses PHPUnit's existing 512 MB budget instead of the worker's 128 MB default. Its delivery/expiry assertions and production worker configuration are unchanged. The final full suite passed.

The build reports the existing outdated Browserslist database warning. Node's VM-module tests report their standard experimental-module warning. Neither caused a failed check.

Existing regression files were updated rather than leaving obsolete expectations: Grooming/Clinic authorization and intake tests, queue/expiry/reminder tests, dashboard summary and customer history tests, review/consent and single-page interface tests, Grooming navigation, pricing/catalogue/preference/groomer tests, cage-slip tests, and the isolated concurrency worker. The obsolete calendar-only frontend test was removed with its unreferenced calendar implementation; its former submission test now exercises no-picker payloads, legacy draft recovery, timestamps and queue information.

## Hostinger release steps

1. Deploy the changed application code **and** static `pages`, `scripts`, `css/output.css` and `css/custom.css` together. Include the new queue-information module and controller/service. Deploy the generated `public/build` assets and manifest through the normal release process. Imported modules and their consuming pages carry the `fcfs-20261027-v1` release query to avoid cached module mismatches.
2. No database migration was added. Ensure the existing nullable `bookings.window_id` and `clinic_appointments.window_id` migrations are already applied; preserve all historical date/window values.
3. Run the usual Laravel release cache refresh (`php artisan optimize:clear`, then the project's normal cache warm-up), and restart persistent queue workers (`php artisan queue:restart`). Keep the existing scheduler entries for expiry and reminders.
4. Keep the existing PHP/platform configuration and credentials. No dependencies, credentials or private environment values were changed. PHP 8.4 was used for verification; application compatibility declarations remain unchanged.
5. Have the user verify both customer journeys and staff check-in workflows after release. No production database commands or Hostinger operations were run here.

## Manual verification remaining

The user retains manual navigation, actual pet/service entry, consent/signature entry, OTP submissions and password resets. Verify Clinic review/confirmation visually, both flows on mobile, browser Back/Forward after editing multiple pets, registered/check-in timestamps, future-date check-in rejection, and cancellation/history after release. Automated frontend/PHP checks and isolated MySQL tests do not establish production browser or Hostinger deployment success.

## Modified existing files

- `app/Console/Commands/SendAppointmentReminders.php`
- `app/Http/Controllers/AdminBookingController.php`
- `app/Http/Controllers/AdminClinicController.php`
- `app/Http/Controllers/BookingController.php`
- `app/Http/Controllers/ClinicWalkinController.php`
- `app/Http/Requests/StoreClinicPreRegistrationRequest.php`
- `app/Services/ClinicAppointmentSequence.php`
- `app/Services/DailyPetQueue.php`
- `app/Services/OperationalCapacity.php`
- `css/custom.css`
- `css/output.css`
- `package.json`
- `pages/admin/appointments.html`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `pages/admin/dashboard.html`
- `pages/admin/walk-in-booking-confirmed.html`
- `pages/admin/walk-in-booking-review.html`
- `pages/admin/walk-in-clinic-confirmed.html`
- `pages/admin/walk-in-grooming-services.html`
- `pages/admin/walk-in-pet-details.html`
- `pages/client/booking-pet-details.html`
- `pages/client/clinic-visit-confirmed.html`
- `pages/client/clinic-visit-date.html`
- `pages/client/clinic-visit-reason.html`
- `pages/client/clinic-visit-summary.html`
- `pages/client/dashboard.html`
- `pages/client/grooming-pre-registration.html`
- `pages/client/my-pets.html`
- `pages/client/pet-details.html`
- `pages/client/pre-register.html`
- `routes/api.php`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-customers.js`
- `scripts/components/admin-dashboard.js`
- `scripts/components/booking-calendar.js`
- `scripts/components/booking-confirmed-step.js`
- `scripts/components/booking-consent-step.js`
- `scripts/components/booking-pet-step.js`
- `scripts/components/booking-review-step.js`
- `scripts/components/booking-services-step.js`
- `scripts/components/client-dashboard.js`
- `scripts/components/clinic-visit-confirmed.js`
- `scripts/components/clinic-visit-date.js`
- `scripts/components/clinic-visit-reason-fields.js`
- `scripts/components/clinic-visit-reason.js`
- `scripts/components/clinic-visit-summary-card.js`
- `scripts/components/clinic-visit-summary.js`
- `scripts/components/confirmation-presentation.js`
- `scripts/components/grooming-pre-registration.js`
- `scripts/components/grooming-service-selection.js`
- `scripts/components/grooming-visit-summary.js`
- `scripts/components/my-pets.js`
- `scripts/components/pet-details.js`
- `scripts/components/pet-groomer-selection.js`
- `scripts/components/pre-registration-options.js`
- `scripts/components/walk-in-clinic-complaint-step.js`
- `scripts/components/walk-in-clinic-consent-step.js`
- `scripts/components/walk-in-pet-step.js`
- `scripts/components/walk-in-review-step.js`
- `scripts/components/walk-in-services-step.js`
- `scripts/services/booking-draft-service.js`
- `scripts/services/booking-form-access-guard.js`
- `scripts/services/clinic-visit-service.js`
- `scripts/services/grooming-service.js`
- `tests/Feature/AdminDashboardSummaryTest.php`
- `tests/Feature/ClinicAdministrationAuthorizationTest.php`
- `tests/Feature/CustomerPreRegistrationAccessInterfaceTest.php`
- `tests/Feature/EmailVerificationTest.php`
- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingReviewAndConsentInterfaceTest.php`
- `tests/Feature/GroomingSinglePageInterfaceTest.php`
- `tests/Feature/QueuePreRegistrationModelTest.php`
- `tests/admin-grooming-cage-slip-regression.cjs`
- `tests/admin-grooming-invoice-print-regression.cjs`
- `tests/admin-grooming-pricing-regression.cjs`
- `tests/customer-dashboard-history-regression.cjs`
- `tests/grooming-capacity-submit.php`
- `tests/grooming-flow-regression.cjs`
- `tests/grooming-managed-catalog-regression.mjs`
- `tests/grooming-paymongo-regression.cjs`
- `tests/grooming-preferences-regression.cjs`
- `tests/grooming-price-presentation-regression.mjs`
- `tests/named-pet-groomers-regression.mjs`
- `tests/preferred-arrival-calendar-regression.cjs`
- `tests/preferred-arrival-window-regression.cjs`

New implementation files: `app/Services/RegistrationServiceDay.php`, `app/Http/Controllers/RegistrationQueueController.php`, and `scripts/components/registration-queue-information.js`. The implementation plan and these release notes are new documentation files. Other page/module-only edits update asset references for the changed dependency tree.
