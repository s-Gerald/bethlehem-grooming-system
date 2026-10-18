# Grooming Reports: local verification and Hostinger deployment

## Compatibility fix

The report now derives customer identities, historical service labels/types, and pet completion dates before grouping their named columns. It preserves the existing calculations, payment exclusions, date boundaries and response format. Laravel strict mode and `ONLY_FULL_GROUP_BY` remain enabled. Database failures are logged server-side; Reports displays a concise message and Retry without showing unavailable metrics.

The SQL uses derived tables and ordinary grouped columns supported by MySQL and MariaDB. Verification was performed on local **MariaDB 10.4.32**, not solely on SQLite. The hosted database has not been contacted or changed.

## Repeatable local checks

From the project root, with XAMPP MySQL running:

```sh
php tests/grooming-reports-mysql.php
node --test tests/admin-reports-regression.cjs
```

The PHP runner reads the configured MySQL server credentials, creates a random `bethlehem_reports_test_<random>` database, and runs only the reporting tests there. It bypasses cached application configuration for the child test process, verifies strict grouping is enabled, and removes only the database it created. It never creates fixtures in the application database. The database user needs permission to create that disposable test database. Do not run fixture tests against a production database.

For the SQLite counterpart when local configuration is cached, use this PowerShell invocation:

```powershell
$env:APP_CONFIG_CACHE = "$PWD/storage/reports-test-no-config-cache.php"
php vendor/phpunit/phpunit/phpunit tests/Feature/GroomingPerformanceReportTest.php --no-progress
Remove-Item Env:APP_CONFIG_CACHE
```

This points tests at a nonexistent cache file without deleting or rebuilding the application's actual cache. The reporting test class refuses fixture DDL unless the connection is in-memory SQLite or the guarded disposable MySQL/MariaDB database.

## Verification completed locally

- MariaDB: 12 tests, 96 assertions, all passing; SQLite: 12 tests, 84 assertions, all passing.
- JavaScript: all 6 reporting regressions passing, including raw SQL error suppression and recovery.
- An independent reference audit read existing local records inside a MariaDB read-only transaction. Every overview metric, new/returning count, booking outcome, service count and individual chart bucket matched across Today, Last 7 Days, This Month, Last Month, custom daily/weekly/monthly ranges, and an empty future range. Each report executed 13 database queries, independent of period size.
- Browser verification used the shipped Reports HTML/component and the real Laravel route/controller against those same local records through a temporary loopback-only read-only harness. Its transient test identity created no user, token or session records. Verified both chart modes, all presets, weekly/monthly custom dates, service expansion, keyboard Clinic tabs, loading skeletons, legitimate empty results, and an injected HTTP database failure followed by successful Retry. Desktop and mobile layouts were checked for overflow. The temporary harness was removed afterward.
- The normal local Reports URL still redirects unauthenticated visitors to Sign In. Endpoint tests preserve admin-only access, invalid-date validation and HTTP 401/403 behavior.

## Migration

No new migration is required for this query fix. The existing `2026_10_18_000002_snapshot_grooming_report_services.php` migration is unchanged. It preserves available historical service labels and adds frozen grooming totals to existing gateway JSON snapshots.

The test suite runs this migration through Laravel's migration ledger twice against disposable MariaDB fixtures. It is recorded once; the second run leaves snapshots unchanged, and booking/payment records and amounts are preserved. The migration was already applied in the existing local database and was not rerun there.

## Hostinger release steps

Use the actual directory containing `artisan` and the site's PHP 8.2+ CLI. Back up the current release/database and keep the hosted `.env`; do not upload the local `.env` or local cache files. Deploy the matching backend, Reports HTML and versioned `admin-reports.js` from the tested release. This fix adds no dependency or build requirement.

1. Confirm PHP and remove stale release caches:

   ```sh
   php -v
   php artisan optimize:clear
   ```

2. Confirm Laravel is using the intended hosted database and inspect its actual engine/session modes. The following prints no credentials:

   ```sh
   php artisan tinker --execute="dump(DB::selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS modes'));"
   php artisan migrate:status
   ```

   Keep strict mode enabled. Review any pending migrations before proceeding. This query fix itself has no additional migration. If the snapshot migration is already listed as run, leave it applied.

3. Apply the reviewed pending migrations and rebuild configuration:

   ```sh
   php artisan migrate --force
   php artisan config:cache
   php artisan migrate:status
   ```

   Confirm `APP_DEBUG=false` in the hosted environment. Do not edit migration history or rebuild the database to resolve report SQL errors.

4. Sign in as admin and open Reports. Check the `/api/admin/reports/grooming` request returns HTTP 200 and `success: true` for the four presets and a custom range. Confirm all cards/sections update together, both chart modes work, historical services remain available, and Clinic shows Coming Soon. Compare a known period with payment/completion records. Confirm staff/customer access remains denied and inspect Laravel logs for unexpected report errors.

5. If old frontend behavior persists, confirm the deployed Reports HTML references `admin-reports.js?v=grooming-mariadb-20261018` and the changed PHP files are in the active release. Use the site's normal PHP OPcache/cache refresh procedure only if the active release still serves older code.

No push, deployment, hosted migration, or Hostinger configuration change was performed as part of this local fix. The final hosted smoke checks remain part of the deployment you control.
