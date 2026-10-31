# Grooming estimation removal — 2026-10-26

Historical release record: simplified advisory durations and physical queue forecasting were subsequently restored on October 31. See [the current estimate implementation](grooming-queue-estimates.md). The retired schema, factors, arrival-window forecasts, and admission rejection remain removed.

The duration estimator, workload/reservation forecaster, their API routes and middleware, factor inputs, duration snapshots, ETA warnings, and forecast requests are removed. Haircut choices now live in `config/grooming_services.php` and travel through the existing catalogue/admin responses. Pricing estimates remain unchanged.

Actual session start/finish and undo behavior, per-pet queue ordering, 20-pet on-site admission, groomer concurrency and assignment, Today's Intake, operating hours, closures, expiry, consent, permissions, payments, notifications and clinic workflows remain. The customer tracker retains its original three circles, connecting lines, accessible states and multi-pet aggregation. Visit details derive each completed pet's actual duration from its timestamps; missing timestamps produce no duration.

`arrival_end_time` remains an accepted schedule snapshot. Existing historical and future snapshots are preserved so confirmations, reminders and expiry stay consistent. New registrations and reschedules save the configured window end. Rescheduling notifications retain the old accepted window and show the full new window.

## Database audit

All active consumers of `booking_pets.grooming_estimate_min`, `grooming_estimate_max`, `grooming_estimate_factors`, and `services.duration_minutes` belonged to prediction. Actual grooming uses `grooming_start_time`/`grooming_end_time` and booking event timestamps; service prices and payments use separate columns. Historical estimator backfills no longer call deleted classes.

`2026_10_26_000003_retire_grooming_duration_estimates.php` drops only those four obsolete columns. It is forward-only: rollback requires restoring the matching application release and database backup. No booking, queue, preference, actual timestamp, service price, payment or arrival snapshot is deleted. The migration has only been executed against isolated disposable test databases.

## Controlled deployment

1. Back up the deployed database and application release; verify restoration procedures. Confirm the reviewed branch and the existing Hostinger PHP CLI/environment. Do not reset the database.
2. Build with `npm run build`. Deploy changed PHP, raw HTML/JavaScript/CSS and normal generated assets together. Vite does not bundle the standalone pages/scripts. Remove the deleted estimator scripts, stylesheet and backend files from the release. Updated asset URLs invalidate browser caches.
3. Keep intake requests and queue workers paused during the coordinated release. Use the existing maintenance/release process; do not leave old PHP workers running against the retired columns. From the verified release directory run `php artisan migrate --force`, then refresh the existing config/route/view caches and restart workers through the normal process. No new environment variables or dependency installation is required by this change.
4. Reopen the application after confirming late-day online registration, walk-in admission/check-in, full on-site rejection, Start/Finish/Undo, payment/release, preferences, the three-step customer tracker and per-pet visit details. Verify one existing future arrival snapshot, a new full-window registration/reschedule, Today's Intake and an unrelated clinic visit. Inspect browser/network errors for missing assets or retired endpoints.

No deployment, live cache commands or migration against the current localhost/production database was performed.

## Verification

- Frontend regression suite: `npm test` — 81/81 scripts passed.
- Production build: `npm run build` passed.
- Backend: the full suite passed in fresh batches of 1,626 tests (8 environment-specific skips, 22,248 assertions) and 102 email tests (1,101 assertions). After the final saved-pet eligibility fix, the complete admission suite passed: 243 tests, 1 MySQL-only skip, 3,507 assertions. All use PHP 8.4 and forced disposable SQLite fixtures. The original core schema contains MySQL-only SQL, so the SQLite clean-install test exercises the affected historical migration chain; the complete migration history was also verified on MariaDB.
- Local MariaDB 10.4.32: real overlapping online submissions passed (201/201, unique references, no premature queue allocation); upgrade preservation and complete fresh installation passed. Each owned test schema was removed and its absence verified.
- Reproduce the MySQL checks with `php tests/grooming-capacity-concurrency.php`, `php tests/grooming-capacity-concurrency.php --migration-upgrade`, and `php tests/grooming-capacity-concurrency.php --migration-fresh`. These use verified loopback-only, randomly named disposable schemas, never the application database.
- Forecast requests/queries were eliminated. Existing cache/deduplication/invalidation and query-count regression checks remain. No replacement polling or forecasting was added; the existing arrival-calendar refresh remains schedule-only. No production Core Web Vitals measurement or production smoke test was performed.

The initial single-process backend run reached the existing mail-worker memory ceiling in `EmailVerificationTest::test_resend_does_not_extend_retention_and_expired_rows_are_not_mailed` (worker exit 12). The test and all 102 email tests pass in a fresh process; authentication/mail production code and memory settings were not changed. Old forecast expectations and asset-version source assertions were corrected and their subsequent runs passed. There are no remaining observed test failures. Optional full-browser/visual smoke checks were not run because this workspace has no Playwright installation; tracker, modal, state, pricing and accessibility contracts were exercised by the existing runtime regressions.
