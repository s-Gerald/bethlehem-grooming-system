# Grooming Reports MySQL/MariaDB Compatibility Plan

**Goal:** Make the Grooming report work with strict grouping enabled on local XAMPP and the deployed Hostinger database, preserving the existing reporting definitions and records.

**Scope:** Query compatibility, focused regression coverage, concise report errors, and a deployment checklist. This is a plan; application code and database records have not been changed for this investigation.

**Architecture:** Calculate customer keys, historical service labels/types, and completion dates in ungrouped derived queries, then aggregate their named output columns. Keep the existing server-side aggregates and response shape. Keep `strict => true` and `ONLY_FULL_GROUP_BY` enabled.

**Stack:** Laravel 12, PHP 8.2+, MySQL/MariaDB, existing SQLite tests, Alpine Reports frontend.

## Confirmed evidence

- The configured local Laravel connection uses `mysql` against `127.0.0.1`, database `bethlehem_grooming`; the actual server is **MariaDB 10.4.32**.
- Laravel's connection has `ONLY_FULL_GROUP_BY` and strict transaction/date modes enabled.
- Read-only SELECTs over constant derived rows reproduce error 1055 for the current customer identity grouping, service label/type grouping, and completion-date grouping. They do not query or change business records.
- The proposed derived-column customer/service/date queries succeed on that same connection and retain expected values: two bookings for `user:7`, one package service, and one completion on October 18.
- The payment-date aggregate succeeds in the read-only reproduction; preserve it unless the complete endpoint tests reveal an additional defect.
- The Hostinger database engine/version/mode has not yet been inspected. Hosted compatibility is a release verification step, not an assumption.

## Files and responsibilities

- Modify `app/Services/GroomingPerformanceReport.php`: customer, service, and completed-visit date query shape only; preserve the returned JSON contract.
- Modify `tests/Feature/GroomingPerformanceReportTest.php`: strict MariaDB regressions, safe per-test fixture cleanup in a disposable database, and the report-error response test.
- Create `tests/grooming-reports-mysql.php`: repeatable runner with an isolated `bethlehem_reports_test_<random>` database. Refuse an application/production database, bypass application config caches in the test process, and verify the actual connection and SQL mode before fixture DDL.
- Modify `app/Http/Controllers/ReportController.php`: log report database failures and return a concise unsuccessful HTTP 500 response. Keep admin checks and HTTP 422 validation outside the database-error handler.
- Modify `tests/admin-reports-regression.cjs` only if frontend behavior changes; existing Retry behavior and hiding unavailable metrics must remain covered.
- Create `docs/grooming-reports-deployment.md`: exact local test and Hostinger release/smoke-check steps.
- Leave `config/database.php`, the existing snapshot migration, and unrelated workflows unchanged. No new migration is needed to fix query grouping.

## Task 1: Reproduce with real strict SQL

- [x] Add the isolated MySQL/MariaDB runner using the existing concurrency runner as a convention reference. Restrict creation/cleanup to the newly generated test database and confirm its name before touching fixture tables. Tests must never inherit the cached application database.
- [x] Run the reporting endpoint tests against local MariaDB with `ONLY_FULL_GROUP_BY` enabled. Confirm the expected error 1055 before altering report queries.
- [x] Ensure fixture teardown removes only this test's tables inside the verified disposable database so consecutive tests are independent. Preserve the normal in-memory SQLite path.

## Task 2: Correct all reproduced grouping failures

- [x] In `customers()`, derive `customer_key` and `booking_id` first; group by the derived key with `COUNT(DISTINCT booking_id)`. Preserve registered/unregistered walk-in identity, reporting-period eligibility, and the new/returning definition.
- [x] In `services()`, derive historical `name`, `kind`, and `booking_service_id`; aggregate by the derived name/type with distinct line counts. Preserve cancelled-pet exclusions, snapshots, sorting, packages, and add-ons.
- [x] In `build()`, derive the completed pet's reporting `day`; count rows grouped by that named day. Preserve the authoritative per-pet completion filter and Manila boundaries.
- [x] Run the entire report test class on MariaDB and SQLite. Verify daily/weekly/monthly totals, multi-pet/partially cancelled visits, linked walk-ins, historical services, payment exclusions, duplicate settlement rows, both payment key variants, empty periods, and authorization.
- [x] Inspect emitted SQL and query counts for accidental joins that multiply counts or per-record database queries. Do not change SQL modes or use arbitrary values to bypass grouping checks.

## Task 3: Keep report failures concise and truthful

- [x] Add a test that forces a database failure at the report boundary and expects HTTP 500, `success: false`, a concise message, and no SQL/query text in the response. Server logs retain the exception.
- [x] Implement the focused database-exception handler. Preserve existing HTTP 403/422 behavior and avoid a success response containing zero metrics.
- [x] Run `node --test tests/admin-reports-regression.cjs` and verify Retry restores a successful report after an error.

## Task 4: Verify migration and deployment compatibility

- [x] Exercise the existing snapshot migration on disposable MariaDB fixtures with legacy names and early online-payment snapshots. Confirm existing booking/payment amounts remain unchanged and historical grooming-only reporting remains correct.
- [x] Verify the normal migration ledger applies that migration once and subsequently reports no pending work. Do not edit an applied migration to deliver this SQL fix.
- [x] Verify the local browser's actual `/api/admin/reports/grooming` response and UI for every preset plus custom dates, Collections/Visits toggling, popular services, and customer insights. Use read-only existing records for this smoke check.
- [ ] Record the deployed Hostinger connection's actual server version and Laravel session SQL mode before release verification. Run the strict report tests on a separate matching test/staging database when that engine differs from local MariaDB. Never run fixture-creating tests on production.

## Hostinger release order

1. Back up the current release/database and verify the correct Laravel directory and PHP 8.2+ CLI. Keep the hosted `.env` and database credentials; use the tested `gerald-frontend` release and its matching Reports assets.
2. Clear stale application caches with `php artisan optimize:clear`. Confirm Laravel resolves the intended hosted database, then review `php artisan migrate:status`.
3. Run the reviewed pending migrations using `php artisan migrate --force`. If the snapshot migration already ran successfully, Laravel should leave it applied; the grouping fix itself needs no new migration.
4. Run `php artisan config:cache`, confirm `APP_DEBUG=false` for production, and rerun `php artisan migrate:status`. Verify that the served backend and versioned frontend files belong to the same release.
5. Sign in as admin and smoke-test the actual report endpoint and dashboard across date presets, custom dates, chart toggles, service/customer sections, and Clinic Coming Soon. Confirm staff/customer access remains denied and the Laravel log has no new report SQL errors.
6. Compare one known reporting period with read-only source aggregates. Verify payment dates versus completion dates, cash inclusion, test-mode exclusions and historical names. Confirm the existing grooming/payment pages still load.

**Completion criteria:** All report calculations pass on strict local MariaDB and SQLite; the required migration is applied exactly once on the target database; the hosted endpoint returns successful real aggregates; and the deployed UI and logs show no report SQL failures. Hosted success is confirmed only after these checks on the actual deployment.

## References

- Local project deployment conventions: `docs/paymongo-grooming.md`, `docs/hostinger-email-auth.md`.
- [MariaDB GROUP BY rules and aliases](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/group-by).
- [MariaDB's reported expression-grouping issue](https://jira.mariadb.org/browse/MDEV-25133).
- [Laravel 12 deployment guidance](https://laravel.com/framework/docs/12.x/deployment).

## Local implementation result

Implemented and verified locally on October 18, 2026. MariaDB 10.4.32 passed 12 report tests / 96 assertions with strict grouping enabled; SQLite passed the same 12 tests / 84 assertions. All 6 report JavaScript regressions passed. An independent read-only reference audit of existing local records matched all metrics, service/customer/outcome counts, and individual chart buckets for four presets plus custom daily/weekly/monthly and empty periods; report query count remained 13. Browser verification used the actual Reports files and Laravel endpoint with the same existing database, via a temporary read-only loopback harness with a transient verification identity. Loading, empty and sanitized error/Retry states, chart toggles, custom dates, service expansion, Clinic keyboard tabs and desktop/mobile layouts passed.

The existing migration was reviewed and left unchanged; its apply-once behavior was verified in the disposable MariaDB test database. The normal local database migration was already applied and was not rerun. No business records or application SQL modes were changed. Deployment instructions are in `docs/grooming-reports-deployment.md`.

The hosted-environment item remains intentionally unchecked: the user will handle GitHub and Hostinger deployment. No push, deployment, hosted database access or hosted migration was performed.
