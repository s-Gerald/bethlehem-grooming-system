# Local MySQL grooming capacity test

The normal `phpunit.xml` deliberately forces in-memory SQLite. The MySQL-only test
`test_concurrent_grooming_submissions_preserve_registration_integrity_on_mysql` therefore
remains skipped in that suite. A SQLite pass/skip is not MySQL concurrency coverage.

## Run locally

```powershell
php tests/grooming-capacity-concurrency.php
```

Prerequisites: a running local MySQL/MariaDB TCP server, PHP with `pdo_mysql` and DOM,
the installed Composer dependencies, and permission to create/drop disposable
databases and their fixture tables. The test account must see its own connections
in `information_schema.PROCESSLIST` to verify actual lock contention. The server's
hostname and port must match this computer and the configured local port; remote
servers, SSH-forwarded remote servers, and differently named containers are refused.

Defaults are local XAMPP settings: `127.0.0.1:3306`, user `root`, empty password.
For a different **local testing** account, set these process environment variables:

```powershell
$env:CAPACITY_TEST_MYSQL_HOST = '127.0.0.1'
$env:CAPACITY_TEST_MYSQL_PORT = '3306'
$env:CAPACITY_TEST_MYSQL_USERNAME = 'your_local_test_user'
$env:CAPACITY_TEST_MYSQL_PASSWORD = Read-Host 'Local test password' -MaskInput
php tests/grooming-capacity-concurrency.php
```

The runner does not boot Laravel or read application `.env`/`DB_*` credentials to
connect. Only these test-specific settings are used. `localhost` is normalized to
literal loopback. There is no remote fallback if the local connection fails.

## Isolation and configuration

- A random `bethlehem_capacity_<12 hex digits>` schema is created without
  `IF NOT EXISTS`. An existing schema never becomes owned by the runner.
- A per-run ownership token is stored in that schema. PHPUnit and both workers
  verify the exact schema, token, driver, host and connection settings before
  fixture writes. Teardown refuses to drop tables if verification failed.
- `phpunit.capacity-mysql.xml` is selected explicitly, forces MySQL, and uses
  `tests/bootstrap-capacity-mysql.php`. The existing normal config/bootstrap and
  production database configuration are unchanged.
- The bootstrap uses an absent, per-run Laravel configuration-cache path and
  clears connection URLs/sockets. Inherited cached configuration cannot redirect
  the fixture. Direct use of the MySQL config without the runner is refused.
- Fixture DDL, test data and workers use only that disposable schema. The runner
  verifies ownership again before dropping it and verifies the schema is gone.
  Owned signal/report files are removed on success or failure.
- Worker deadlines are 25 seconds; lock synchronization is limited to 10 seconds;
  the parent PHPUnit deadline is 90 seconds. Processes are stopped in `finally`.

## What passing means

The first worker owns the real shared intake lock. It waits until MySQL exposes
the second worker's blocked `GET_LOCK` query, so overlap does not rely on a fixed
sleep. The test verifies two 201 responses, unique references, two linked bookings/pets/notifications,
and no booking/pet queue numbers or premature physical capacity use. Online registrations
are no longer limited by forecast workload.

The runner requires exactly the intended JUnit testcase, nonzero assertions, no
failure/error/skip, a successful process exit, and matching MySQL execution proof.
PHPUnit's event log distinguishes incomplete from skipped because PHPUnit 11
represents both as `<skipped/>` in JUnit. A zero exit with no tests is rejected.

| Exit | Meaning |
| --- | --- |
| 0 | The real MySQL test passed and cleanup succeeded |
| 1 | Test failed, skipped, or incomplete; the printed result distinguishes these |
| 2 | Runner/configuration error, missing/wrong test, or missing execution proof |
| 3 | Local MySQL connection/credentials/database operation unavailable |
| 4 | Database or temporary-file cleanup failed; inspect the reported exact name |

Do not interpret PHPUnit's ordinary SQLite skip as a pass or run migrations against
the application's development/production schema to resolve a test prerequisite.

## Historical verification — 2026-10-20

- Reproduced the old bug: inherited MySQL settings were overridden by forced SQLite;
  PHPUnit reported **1 skipped, 0 assertions, exit 0**.
- Real local MariaDB **10.4.32**, `127.0.0.1:3306`: three consecutive runs and a final
  verification each passed **1 test / 30 assertions**, with real observed lock contention and **201/422**.
  Every generated schema was removed and its absence verified.
- A temporary missing-test-filter probe reproduced PHPUnit's zero-test success
  exit. The corrected runner rejected it and still removed its disposable schema.
- Unsafe host, unavailable loopback port, and direct config-without-runner checks
  failed safely before creating a database. Unit tests cover malformed/wrong
  schemas and passed/failed/skipped/incomplete/absent/malformed JUnit results.
- Standard SQLite regression run: **278 passed, 1 intentionally skipped,
  3,763 assertions**, covering Grooming authorization, workload capacity and harness
  safety/result validation. No application locking defect was found; production
  capacity code, notification functionality, routes and schemas were unchanged.

The optional `queue-intake-concurrency.php` runner was inspected but not changed or
executed; its separate harness is not required for this grooming submission test.
No branch changes, commits, pushes, deployment, Hostinger access or production
database access were performed.

## Files changed

- `phpunit.capacity-mysql.xml`
- `tests/bootstrap-capacity-mysql.php`
- `tests/Support/CapacityMysqlHarness.php`
- `tests/grooming-capacity-concurrency.php`
- `tests/grooming-capacity-submit.php`
- `tests/Feature/GroomingAdministrationAuthorizationTest.php`
- `tests/Unit/CapacityMysqlHarnessTest.php`
- `docs/mysql-capacity-tests.md`

## Verification after estimation removal — 2026-10-26

The concurrency check passed on local MariaDB 10.4.32 with 201/201, observed lock contention and 30 assertions. The runner also accepts `--migration-upgrade` (completed/future visit and payment preservation) and `--migration-fresh` (complete clean install). Each mode creates its own verified disposable schema; the migration test rejects all other connections. The test bootstrap disables external SMS in both PHPUnit and its workers. All generated schemas were removed after verification.
