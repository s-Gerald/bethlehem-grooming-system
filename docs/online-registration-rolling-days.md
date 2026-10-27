# Online Registration: rolling service days and closing-time No Show

Implemented on `gerald-frontend`. No commit, push, deployment, private environment edit, or migration against the running application's database was performed.

## Customer interface

- One centered information card with equal Grooming / Clinic Visit tabs; Grooming is selected initially.
- Existing blue palette, typography, Phosphor icons, natural card height and a full-width Continue action.
- Arrow Left / Right wrap, Home / End, native Enter / Space activation, roving tab focus, selected state, labeled tab panel and accessible loading/error messages.
- Switching uses a 180 ms opacity fade, suppressed by reduced-motion preferences. It makes no network requests and does not clear partial drafts.
- A completed old Grooming draft is cleared only when Continue starts another registration. Clinic's existing successful-submission cleanup remains intact.
- Both services load once with the existing access/phone-verification guard. Only Continue is disabled for an unavailable service. A retry is available for request failures.
- Visit day, check-in opening and availability come from the server. The selector does not advertise cages as reserved or guaranteed.

Visual review used only the existing ChatGPT browser account and this page. Desktop plus 320, 375, 414 and 768 px layouts were inspected. At 320 px, document width was 320 px, both tabs were 138 × 48 px, and Continue was 48 px high. No page-navigation, form-entry, OTP or password-reset testing was performed in the browser. The live settings showed Grooming opening at 4:00 AM and Clinic opening at 12:00 AM; these existing settings were displayed without being changed.

## Registration rules and API changes

`RegistrationServiceDay` is the shared authority, using `Asia/Manila` and each service's configured hours. Before closing, today is eligible; at closing, tomorrow is eligible. A closure can move today to tomorrow, but never beyond tomorrow. If both are blocked, the response is unavailable. Full grooming capacity still affects physical intake only.

The existing queue-info response retains its service-day, waiting-count and check-in fields and adds availability, opening/closing and display-label fields. Its availability and check-in eligibility share the same loaded settings and closures, avoiding repeated availability queries.

Both existing online submission endpoints now require the reviewed `service_day` to match the current server assignment. Missing/stale dates return `409 service_day_changed`; newly unavailable services return `409 service_unavailable`. No visit or pet is created by those rejected submissions. Grooming returns to review; Clinic updates review in place. Data remains intact and submission requires another explicit customer action.

There are no new appointment slots, preferred times, advance queue numbers or reserved cages. Existing physical check-in, staff verification, multi-pet intake and cage occupancy remain authoritative. Successful online submissions retain `waiting_to_arrive`.

## No Show and administrative interruptions

- Reuses `PreRegistrationExpiry` and `pre-registrations:expire`; the existing scheduled command now runs every minute.
- New online records save `registration_closes_at` from the assigned visit day and configured closing time. A normal unarrived registration becomes `no_show` at closing or the next processing run, never at opening.
- Extended hours extend eligibility. Shorter hours or a later opening persist `registration_no_show_exempt_at` for existing unarrived registrations for today/tomorrow. Restoring hours does not remove that protection.
- Active closures, stops and closure history indicating interrupted intake are excluded. Interrupted registrations remain for staff cancellation/review; they are not automatically blamed on the customer. The normal ongoing-registration guard continues to apply until staff resolves them.
- Legacy registrations without a saved deadline use current closing hours conservatively. If settings changed after their creation, they are retained for staff review rather than retroactively penalized.
- Processing selects only `waiting_to_arrive`, with no arrival timestamp or queue number; it excludes grooming activity and paid records. Terminal statuses remain untouched.
- Each transaction locks the settings singleton before registration rows, matching staff intake and availability changes. It processes at most 200 rows per transaction, uses a committed keyset cursor, batches relation loads and notifications, and rolls back status/audit/notifications together on failure. Deadlock retries cannot advance past a rolled-back batch.
- No Show has a distinct audit action and customer Grooming notification type. Historical `expired` records and their old notifications remain historical; the new migration does not relabel them.

## Deployment and local manual-testing prerequisite

The running application's database has **not** been migrated. Apply the additive migration before testing actual submissions or releasing the updated endpoints, using the correct application's PHP CLI and root directory:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000002_add_registration_closing_deadlines.php --force
```

This adds two nullable timestamps to each registration table and a Grooming status/date index. It assumes the earlier repository migrations, including service-specific closures, are already applied. It does not rewrite statuses or historical records.

Deploy the rebuilt `css/output.css` and updated JavaScript/HTML together. Related import and page references include new cache-busting parameters. Existing older open forms must refresh/review rather than submit without the new reviewed-date contract.

### Hostinger scheduler

Hosting configuration cannot be confirmed from the repository and was not changed. Laravel's schedule was verified with `artisan schedule:list` using isolated test configuration; it lists `pre-registrations:expire` with `* * * * *`.

In Hostinger hPanel, select the website, find **Cron Jobs**, and create or verify one **Custom** job running every minute (`* * * * *`). Use the actual absolute PHP CLI and application paths:

```text
/ABSOLUTE/PATH/TO/PHP /ABSOLUTE/APP/ROOT/artisan schedule:run
```

Replace both placeholders; do not assume the application root equals `public_html` or that CLI PHP matches web PHP. Do not add a second independent expiry cron. A minute schedule works regardless of hPanel's UTC clock; business eligibility explicitly uses Manila time. Verify the cron's output and automatic No Show processing without opening a page after deployment. Do not run `schedule:run` merely as a read-only diagnostic: it executes all due jobs.

References: [Laravel 12 scheduler](https://laravel.com/docs/12.x/scheduling), [Hostinger cron configuration](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/), [W3C tabs](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/), [W3C reduced motion](https://www.w3.org/WAI/WCAG22/Techniques/css/C39.html).

## Verification

- Full PHP 8.4 suite: **1,773 tests, 23,669 assertions, zero failures/errors; 8 opt-in MySQL checks skipped** (six dashboard MySQL cases, one Grooming concurrency MySQL case, one phone-verification MySQL migration case).
- Final focused registration/concurrency and dashboard group after metadata-query consolidation: **85 tests, 582 assertions passed**.
- Frontend: **82/82 regression scripts passed**, plus final selector, rollover/draft and shared-service-day reruns passed.
- Production: **`npm run build` passed**, including OCR assets, Tailwind CSS and Vite; Vite transformed 56 modules. The existing outdated Browserslist data warning remains informational.
- `git diff --check` passed. `artisan schedule:list` confirmed the minute-based expiry entry without executing scheduled jobs.
- Dashboard fixture query counts improved from the earlier 32 full / 24 summary / 1 indicator to **26 full / 18 summary / 1 indicator** after eliminating repeated schema metadata lookups. Response payloads remained 2,065 / 371 / 22 bytes. These are fixture measurements, not production Core Web Vitals. Detailed local logs are in `storage/online-registration-php-final.log`, `storage/online-registration-frontend-final.log` and `storage/online-registration-build-final.log`.

Original affected tests were updated for reviewed dates, closing-time No Show, separate legacy Expired labels, draft preservation and the single dynamic selector. Existing queue order, capacity, phone enforcement, audit batching and concurrency assertions were retained. New cases cover bounded days, Manila/UTC boundaries, closure changes, saved deadlines, restored hours, transaction retries and stale-form recovery.

## Files changed

- `app/Console/Commands/ExpirePreRegistrations.php`
- `app/Http/Controllers/AdminClinicController.php`
- `app/Http/Controllers/BookingController.php`
- `app/Http/Controllers/ClinicClosureController.php`
- `app/Http/Controllers/ClinicSettingController.php`
- `app/Http/Controllers/ClinicWalkinController.php`
- `app/Http/Controllers/CustomerNotificationController.php`
- `app/Http/Controllers/RegistrationQueueController.php`
- `app/Http/Requests/StoreClinicPreRegistrationRequest.php`
- `app/Models/Booking.php`
- `app/Models/ClinicAppointment.php`
- `app/Models/ClinicClosure.php`
- `app/Models/CustomerNotification.php`
- `app/Observers/ActivityObserver.php`
- `app/Services/ActivityRecorder.php`
- `app/Services/CustomerPreRegistrationAccessService.php`
- `app/Services/PreRegistrationExpiry.php`
- `app/Services/RegistrationServiceDay.php`
- `app/Support/ActivityPresentation.php`
- `css/output.css`
- `database/migrations/2026_10_27_000002_add_registration_closing_deadlines.php`
- `docs/online-registration-rolling-days.md`
- `docs/superpowers/plans/2026-10-27-online-registration.md`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `pages/client/booking-pet-details.html`
- `pages/client/clinic-visit-summary.html`
- `pages/client/dashboard.html`
- `pages/client/grooming-history.html`
- `pages/client/grooming-pre-registration.html`
- `pages/client/pet-details.html`
- `pages/client/pre-register.html`
- `routes/console.php`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-customers.js`
- `scripts/components/booking-consent-step.js`
- `scripts/components/booking-pet-step.js`
- `scripts/components/booking-review-step.js`
- `scripts/components/client-dashboard.js`
- `scripts/components/clinic-visit-summary.js`
- `scripts/components/grooming-pre-registration.js`
- `scripts/components/pet-details.js`
- `scripts/components/pre-registration-options.js`
- `scripts/components/registration-queue-information.js`
- `scripts/services/booking-form-access-guard.js`
- `scripts/services/registration-service-day.js`
- `tests/Feature/ActivityLogTest.php`
- `tests/Feature/ClientPetProfileTest.php`
- `tests/Feature/ClinicAdministrationAuthorizationTest.php`
- `tests/Feature/CustomerPreRegistrationAccessInterfaceTest.php`
- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Feature/GroomingCancellationTest.php`
- `tests/Feature/GroomingSinglePageInterfaceTest.php`
- `tests/Feature/QueuePreRegistrationModelTest.php`
- `tests/Support/CreatesRegistrationAvailability.php`
- `tests/Support/SubmitsSignedGrooming.php`
- `tests/customer-dashboard-history-regression.cjs`
- `tests/grooming-consent-regression.mjs`
- `tests/grooming-flow-regression.cjs`
- `tests/online-registration-selector-regression.mjs`
- `tests/preferred-arrival-window-regression.cjs`
- `tests/registration-service-day-regression.mjs`
