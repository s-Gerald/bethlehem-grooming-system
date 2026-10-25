# Dashboard Completed today — deployment and verification

The existing `/api/admin/dashboard/summary` now returns `summary.completedToday`:

- Admin: `grooming`, `clinic`, `total`.
- Both receptionist roles: `grooming`, `clinic`.
- Veterinarian: `clinic` only, with no grooming queries.

## Counting rules

`app/Services/DashboardCompletionMetrics.php` supplies both dashboard variants. Grooming requires a paid booking in `released`, `completed`, or `archived`, a confirmed paid audit record with `paid_at`, and every non-cancelled pet in `finished` with a finish timestamp. Count eligible pets, not `number_of_pets`. Cancelled siblings are excluded by state and cancellation timestamp. Duplicate paid audit rows use the earliest successful settlement and never multiply pets.

The service finish time is the later of the recorded booking finish and the last eligible pet finish; per-pet timestamps also preserve reliable completion when the booking timestamp is absent. Clinic requires a paid `completed` or `archived` appointment with both consultation-finish and settlement timestamps, counting one appointment. `for_payment` never qualifies.

For both departments, the later service/payment timestamp must fall in the half-open Asia/Manila day range. Archive, appointment, booking and update dates are not substitutes. Historical records without reliable evidence remain excluded; no timestamps are invented or backfilled. Existing settlement/archiving behavior is unchanged.

The Grooming management `today`/`week` counters remain in their existing consumers. Only the dashboard's obsolete `summary.today` is replaced. Revenue, waiting, capacity, activity, queue previews, role permissions, cache invalidation and polling are preserved.

## Changed implementation files

- `app/Services/DashboardCompletionMetrics.php`: dashboard-only SQL aggregates.
- `app/Http/Controllers/AdminBookingController.php`: structured breakdown in both role variants; management counters remain isolated.
- `scripts/components/admin-dashboard.js`: structured completion state and session invalidation, using the existing payload normalization and refresh pipeline.
- `pages/admin/dashboard.html`: existing card with role-specific number-above-label groups and versioned asset references.
- `css/output.css`: generated `order-last` utility for semantic definition-list markup.
- Existing `AdminDashboardSummaryTest.php`, `AdminDashboardMysqlTest.php`, and `admin-dashboard-refresh-regression.cjs` were updated in place.

## Verification

- Related PHP 8.4 run: 623 tests, 7,967 assertions, no failures; one existing skip, `GroomingAdministrationAuthorizationTest::test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql`, requires its separate capacity concurrency harness. No concurrency acceptance is claimed.
- After adding early-payment workflow assertions: final dashboard rerun passed 43 tests / 260 assertions, including multi-pet finalization across midnight.
- Disposable MariaDB 10.4 run through `tests/dashboard-summary-mysql.php`: 6 tests / 38 assertions passed with current migrations. Query count remained 18 for both 5 and 30 queued visits (empty: 15); the compact response still avoids workflow detail loads.
- Frontend regression scripts passed: dashboard refresh, dashboard capacity, admin data cache, admin loading, clinic subrole workflow. Includes cache retention, mutation invalidation, deduplication, session ownership, role changes and state restoration.
- `npm run build` passed. Existing outdated Browserslist data warning remains; no dependency changes were made.
- Changed PHP files pass PHP 8.2 syntax checks; diff whitespace checks pass.
- Inspected Admin in the existing ChatGPT browser. Current local data showed Grooming 1, Clinic 3, Total 4. Card and label/value overflow checks passed at 320, 375, 640, 768, 1024 and 1280px; the original viewport was restored. Other roles were checked through API/frontend regressions, without switching the browser session.
- Manual navigation, data input, OTP, password resets and other role sessions remain with the user.

## Redeployment

No new migration, index, environment variable or dependency is required. No live deployment was performed.

1. Run the existing `npm run build` on the build machine (already passed locally).
2. Include the changed PHP service/controller, raw dashboard HTML and JavaScript, generated `css/output.css`, and the normal complete `public/build/` assets/manifest in the Hostinger release. The raw admin files are not bundled by Vite. Keep the dashboard's changed asset query versions.
3. Follow the existing release procedure for PHP/opcode caches. Do not clear or disable dashboard data caches or modify production data.
