# Grooming walk-in reliability — 2026-10-29

Implemented on `gerald-frontend`. The additive migration was applied to the backed-up **local** database. No commit, push, Hostinger deployment, or production database change was performed.

## Confirmed diagnosis and local data

The original Laravel log confirms MySQL error 1062, duplicate `WI-20261029-001` on `bookings_reference_unique`, during `WalkinController::store()` on October 29. The failed insert attempted `user_id=15`, `status=checked_in`, `booking_type=walk_in`, `queue_number=1`. This was a database reference collision, rather than a failure in the shared browser fetch function.

Previously, the booking reference suffix came from `max(queue_number)+1`. Undo Check-in cleared the last queue number while retaining its unique reference. Subsequent registrations attempted that same reference again. Existing customer/pet selection did not exclude canceled visits or cause the observed exception.

Stable IDs verified in the local database:

| Record | ID | Verified state |
| --- | --- | --- |
| Registered customer John Lennon | 15 | Active, not archived |
| Affected booking | 189 | `WI-20261029-001`, `walk_in`, canceled |
| Walk-in | 47 | Linked to user 15 |
| Booking-pet | 241 | Canceled; queue fields cleared |
| Existing pet | 44 | Reusable through its selected owner |

Booking 189 has null `queue_number` and `dropped_off_at`; booking-pet 241 has null `pet_queue_date` and `pet_queue_number`. The booking's cancellation reason, cancellation time/staff attribution, and signed consent remain present. These canceled records require **no historical data correction**.

A full local SQL backup was created before investigation/migration at `storage/app/private/backups/before-grooming-intake-20261029-070607.sql` (751,429 bytes; SHA-256 `c318a345efc5e2256fc0862b8907cb76e95414520bc381667d4830658f66137a`). It stays outside the release files.

The backup was restored to a newly created disposable MariaDB database. Against that copy, the real request validator and walk-in controller successfully created `WI-20261029-002`, reused pet 44, and replayed the same result on retry. The old booking, booking-pet, walk-in and consent were compared before/after and remained unchanged. The disposable database was removed.

After applying the additive migration to localhost, a read-only check confirmed that booking 189 remains canceled with its consent intact, the original database still has 174 bookings, and the new submission-receipt table has zero rows. No real repeat visit was inserted into the application database. Manual browser functional testing remains with the user.

## Changed implementation

| Files | Purpose |
| --- | --- |
| `app/Http/Controllers/AdminBookingController.php` | Reject either persisted walk-in origin indicator before changing state; expose persisted origin to cards; reserve fresh online check-in numbers; preserve counters before online Undo clears queue fields. |
| `app/Http/Controllers/WalkinController.php` | Reserve durable booking/pet numbers; replay keyed retries atomically; return controlled validation errors for uniqueness conflicts. Existing identity, service, consent and capacity validation remain. |
| `app/Http/Requests/StoreWalkinRequest.php` | Validate the optional UUID `submission_key` while preserving the existing API payload. |
| `app/Services/GroomingIntakeSequence.php` | One source for durable daily grooming booking/reference and pet counters. |
| `app/Services/DailyPetQueue.php` | Assign the reserved pet range using the existing dog/cat ordering; retain the shared Clinic/Grooming intake lock and transaction. |
| `database/migrations/2026_10_29_000001_add_grooming_intake_sequences.php` | Add only the daily counter and successful-submission receipt tables; leave historical records unchanged. |
| `scripts/components/admin-dashboard.js` | Permit Undo Check-in only for persisted online origin without a walk-in ID; retain other eligibility safeguards. |
| `scripts/components/walk-in-consent-step.js` | Guard submission before async confirmation; persist a retry UUID; retain data/signature after errors; restore accessible submit state. |
| `scripts/components/walk-in-owner-step.js` | Clear the retry UUID with the existing new-visit draft cleanup. |
| `pages/admin/appointments.html` | Use the existing Phosphor XCircle cancellation symbol and version the corrected script. |
| `pages/admin/dashboard.html`, `walk-in-booking.html`, `walk-in-consent.html`, `walk-in-booking-review.html`, `walk-in-grooming-services.html`, `walk-in-pet-details.html` | Update raw script cache versions. |
| `scripts/components/walk-in-pet-step.js`, `walk-in-services-step.js`, `walk-in-review-step.js` | Propagate cache versions through the full module import chain. |
| Existing PHP/frontend test files listed below | Extend existing coverage and update obsolete queue-reuse expectations and stale fixtures. |

The new daily row is initialized once per date under the existing shared intake lock, using **all** retained WI references plus booking and pet queue maxima. Numeric suffixes above 999 are handled numerically. After initialization, one row read/update reserves the booking number and complete pet range; there are no per-card requests or new dependencies. Numbers may contain gaps; they never rewind during supported Undo, cancellation or archive operations. Online bookings retain their original references while receiving fresh arrival queue numbers.

Successful keyed submissions store the actor, validated payload hash, booking ID and original JSON response in the same transaction as the visit. An identical retry returns the original result before owner/capacity mutations; a different actor or changed payload with that key receives 422. New visits receive new keys. Optional keys preserve older API compatibility; callers without a key do not receive server retry deduplication. The corrected browser flow always sends one.

## UI and workflow

The supplied admin appointments page was inspected in the ChatGPT browser. It had no active cards, so populated-menu behavior was checked through the existing automated regressions and template conditions. No other role or page was opened, and no data was entered through localhost UI.

Queued walk-ins retain eligible **Cancel** as their only secondary action; Undo Check-in is absent, including its separator. Eligible online registrations retain Undo Check-in and Cancel. The three-dot menu, keyboard/focus behavior, danger styling, primary controls, View Details, pet actions, payment and Undo Start remain in place. Cancellation still requires its existing reason and confirmation. No new helper text, disabled Undo item, banner, CSS system, typography or layout was introduced.

The backend returns 422 with `Walk-in visits cannot be moved to Incoming.` before modifying a prohibited walk-in. Existing payment/status/grooming guards remain. The existing action handler awaits successful API completion before moving the card, so a rejection does not move it to Incoming.

## Verification

- Existing `GroomingAdministrationAuthorizationTest`: walk-in API rejection via either origin field; unchanged prohibited requests; John Lennon fixture repeat visits; registered/unregistered pet reuse; cancellation preservation; keyed retry and changed-payload rejection; legacy migration preservation; numeric suffixes above 999; controlled uniqueness failure and rollback. The existing online Undo test now requires fresh booking/pet numbers on re-check-in.
- Existing `GroomingCancellationTest` and `AdminDashboardSummaryTest`: additive tables included in fixtures. The cancellation suite's visit date is now a valid date instead of null. Existing payment, cancellation and Undo Start tests pass.
- Existing `tests/grooming-capacity-submit.php` and the authorization suite's MySQL case: real overlapping registrations and duplicate retries. Stale harness service-day/deadline fixtures were updated to match the existing contracts; Clinic production code was not changed.
- Existing `admin-grooming-board-regression.cjs`, `walk-in-review-regression.cjs`, and `grooming-consent-regression.mjs`: persisted-origin eligibility, rapid confirmation/submission suppression, signature preservation, same-key recovery and browser-crypto fixture support.
- Final relevant Laravel run: **423 tests, 5,229 assertions, one MySQL-only skip**. Covers queue/registration, authorization, cancellation, dashboard, consent, walk-in interfaces, notification interfaces and pricing dependencies.
- Separate disposable MariaDB concurrency run: **1 test, 164 assertions**, passed. Verified shared-lock contention, unique walk-in references, sequential pet queues, and one visit from overlapping identical keyed submissions. Cleanup verified.
- `npm test`: **85/85 regression scripts passed**.
- `npm run build`: passed. Existing outdated Browserslist-data notice remains; dependencies were not changed.
- PHP syntax checks and changed JavaScript syntax checks: passed. `git diff --check`: passed.
- Pint: the new service/migration and modified queue service pass. Existing controller/request formatting findings remain; checks against their original `HEAD` versions confirm the same categories. Broad unrelated reformatting was intentionally avoided. No failing behavior test was removed, skipped or relaxed to obtain these results.

## Hostinger redeployment

Use the existing deployment process and its verified PHP executable. No environment variable, dependency update, manual production customer edit, queue reset or reseed is needed.

1. Back up the production database and current release. Keep intake unavailable during the source/migration switch so old and new generators cannot run together. Use the existing maintenance mechanism (`php artisan down` where applicable).
2. Build the release with the lock-file dependencies (`npm ci` on a clean build machine, then `npm run build`). Upload the changed PHP source, new service and migration, **all changed raw `pages/` and `scripts/` files**, `css/output.css`, and complete `public/build/`. Vite does not bundle these raw admin pages/modules; shipping only `public/build/` omits this fix. Include existing generated OCR/QR/PDF assets when packaging a complete release.
3. Refresh Composer's optimized autoloader through the existing lock-file deployment process so the new service is available, especially if the host uses an authoritative class map.
4. From the Laravel application root, run only the new migration for this release:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_29_000001_add_grooming_intake_sequences.php --force
   ```

   The migration creates `grooming_intake_sequences` and `grooming_walkin_submissions`. Daily high-water values initialize automatically from retained history on first intake or permitted online Undo. There is no manual production sequence seed.
5. Refresh the existing deployment caches and opcode/worker processes as appropriate to that hosting setup. Purge any HTML/CDN cache so the new `intake-reliability=20261029` script versions are delivered. Resume intake (`php artisan up` if maintenance mode was used).
6. Staff can perform the reserved manual acceptance: queued walk-in has Cancel only; online Undo still works; returning registered/unregistered customers can register another visit after cancellation; failed requests keep form/signature data. Check server logs for new errors.

Production deployment was not attempted. Keep the counter and receipt tables with future backups/restores; dropping or rewinding them after use would discard the new durable allocation/retry history. Historical queue fields erased before this fix are preserved as null rather than guessed or rewritten.
