# Admin Dashboard reliability — local investigation and validation

Date: 2026-10-21. Branch: `gerald-frontend`.

## Root cause and evidence

The user-supplied Hostinger log contains **77** exceptions whose stack traces reach `AdminBookingController::dashboardSummary()`. All report:

```text
Call to a member function format() on string
app/Http/Controllers/AdminBookingController.php:229
```

The first matching timestamp is `2026-10-20 08:12:37`; the last is `2026-10-21 12:57:58`, as recorded in the file. These records are tagged `staging.ERROR`. Unrelated filesystem/console and authentication entries were excluded. The uploaded log itself was not copied into the repository.

Stack frames 0–4 map the queue collection at controller line 223; frame 5 enters `dashboardSummary()` through `ControllerDispatcher`. The failing expression was:

```php
$booking->dropped_off_at?->format('g:i A')
```

`Booking` does not cast `dropped_off_at` to a datetime. A populated database value is therefore a string. Null-safe method access protects nulls, but still calls `format()` on a non-null string. A current-day checked-in/in-progress booking with a recorded check-in, among the five returned preview cards, causes the whole summary to fail. Empty queues and legacy null check-ins do not trigger it. This explains why initial fixtures with null check-in times passed on both database engines.

Adding a recorded check-in reproduced the exact exception on SQLite and HTTP 500 through the authorized route on native MySQL 8.4 before the correction. Afterward the route returns HTTP 200, preserving `9:45 AM` formatting and null legacy values.

There is no SQLSTATE for this PHP exception. No evidence supports a missing migration, invalid aggregation, queue ordering incompatibility, capacity query failure, or deadlock as the cause of these 77 failures.

## Implementation

- Parse the queue check-in timestamp using Carbon, matching the existing booking formatter in the same controller. Keep SQL, response keys, model serialization, metrics, and permissions intact.
- Allow the existing cache loader to notify the Dashboard when retained data is returned after a transient refresh failure. Other cache consumers retain their existing behavior.
- Keep previous metrics/activity visible with a concise outdated-data message. Keep initial failures concise. Clear errors after successful recovery.
- Back off automatic failed section refreshes to 60 seconds, then 120 seconds maximum. Successful requests reset the failure count; normal polling stays at 30 seconds. Manual Retry bypasses the delay and shares an existing request.
- Make polling setup idempotent and prevent Dashboard loads for hidden, inactive, or destroyed components. Ignore late responses after destruction.
- Keep in-flight Dashboard requests registered across mutation invalidation. Revision checks reject their superseded responses, and subsequent requests start after settlement; invalidation does not launch a parallel summary request.
- Update the Dashboard's API preload/script URL and component version together so the revised assets are requested after deployment.

## Expiry decision

Keep `PreRegistrationExpiry::expire()` on the summary request for now. `routes/console.php` schedules `pre-registrations:expire` every five minutes with `withoutOverlapping()`, but the declaration does not prove the deployed scheduler runs. Removing the read fallback would delay expiry and no-show notifications when cron is absent, particularly across midnight. The actual exception occurs after expiry, while formatting queue cards.

Expiry rules, transaction retries, row locks, atomic notification writes, customer notifications, and grooming/clinic no-show categories remain unchanged. Existing regression coverage verifies rollback on notification failure and overlapping expiry calls; migration-based tests verify repeated summary reads do not duplicate events.

## Files

Production changes:

- `app/Http/Controllers/AdminBookingController.php`
- `scripts/api.js`
- `scripts/components/admin-dashboard.js`
- `pages/admin/dashboard.html` — asset versions only

Tests and documentation:

- `tests/Feature/AdminDashboardSummaryTest.php`
- `tests/Feature/AdminDashboardMysqlTest.php`
- `tests/dashboard-summary-mysql.php`
- `tests/admin-dashboard-refresh-regression.cjs`
- `tests/admin-data-cache-regression.cjs`
- `docs/superpowers/plans/2026-10-21-dashboard-reliability.md`
- This report.

## Tests and commands

All commands run from `C:/bethlehem-clinic-frontend`.

```powershell
& C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe vendor/phpunit/phpunit/phpunit tests/Feature/AdminDashboardSummaryTest.php tests/Feature/QueuePreRegistrationModelTest.php tests/Feature/AdminNotificationCenterTest.php --no-progress
```

Result: **54 tests, 456 assertions passed**, using the forced in-memory SQLite configuration. This includes the regression that first failed with the production exception and the existing SQLite expiry concurrency/rollback coverage.

```powershell
$env:DASHBOARD_TEST_MYSQL_PORT='33318'
php tests/dashboard-summary-mysql.php
```

Result on disposable native **MySQL 8.4.0**: **6 tests, 38 assertions passed** after all current migrations. Before the fix the recorded-check-in test returned HTTP 500 with the production exception. The runner creates a random schema on a literal loopback address, forces testing configuration, and drops only that generated schema afterward. It reads no application database credentials. A persistent local test server and explicitly supplied test credentials are required to rerun it; temporary servers used for this investigation are stopped afterward.

The same final runner also passed **6 tests, 38 assertions** against disposable **MariaDB 10.4.32** on port 33317, using an explicit synthetic test password.

```powershell
node tests/admin-dashboard-refresh-regression.cjs
node tests/admin-data-cache-regression.cjs
node tests/admin-navigation-performance-regression.cjs
node tests/admin-dashboard-capacity-regression.cjs
node tests/admin-loading-regression.cjs
node tests/admin-notification-center-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/session-inactivity-regression.cjs
```

All eight scripts pass. Coverage includes initial loading, retained/fresh/expired caches across HTML documents, other-page navigation, HTTP 500/503 handling, manual Retry, recovery, activity retention, role/session ownership, session verification, visibility, timer/listener cleanup, request sharing, and mutation invalidation. The navigation test emits Node's existing experimental VM-loader warning; it passes.

PHP lint for changed PHP files, `node --check` for the changed scripts, and `git diff --check` pass. The normal PHP 8.2 sandbox could not read the PHPUnit bootstrap; tests ran with approved local execution outside that sandbox. PHP 8.4 was used for SQLite concurrency support; XAMPP PHP 8.2 supplied the MySQL PDO driver.

## Performance

| Check | Before | After |
| --- | --- | --- |
| Summary requests during five simulated minutes of repeated HTTP 500 responses | 11 | 4 |
| Duplicate polling setup | Two intervals/listener registrations | One interval and one listener per event |
| New summary requested during an invalidated pending summary | Starts a second request | Shares the pending request; superseded data is rejected |
| Fresh summary reused across three other-page navigations | One summary request total | One summary request total |
| Backend SQL / query count | Existing queries | Unchanged by timestamp parsing |

The five-minute comparison executes both `HEAD` versions of the API/component and the working versions with the same deterministic clock and HTTP 500 transport.

On native MySQL, the final warm controller samples measured **17 queries / 18.25 ms** for an empty dataset and **20 queries / 17.83 ms** with five visits, including real owners and pets. Increasing the queue to thirty visits still uses **20 queries** and returns five preview cards. The existing compact fixture remains **18 summary queries versus 33 full-board queries**. These are local samples, not production latency or Core Web Vitals measurements. The fix adds at most five timestamp parses and no database queries or network requests.

## Deployment and limitations

No migration, production cache-clear command, environment change, or cron change is required for this correction. The existing scheduler configuration is preserved; this change does not depend on adding a scheduler.

All implementation and tests are local on `gerald-frontend`. No branch switch, commit, push, Hostinger access, deployment, production database operation, or production configuration change was performed. The user retains deployment control.

After your normal review/push/deployment, verify the Dashboard with a current checked-in booking whose check-in time is populated. Confirm the summary returns HTTP 200, navigate to Grooming/Clinic and back, hard-refresh, and check that notifications/activity still load. If desired, simulate an offline/failed refresh after loading metrics and confirm the prior data stays visible, Retry recovers, and the warning clears.

The supplied production exception is established and reproduced locally. The deployed fix, actual Hostinger scheduler execution, field latency, full browser rendering, and Core Web Vitals have not been verified against the live site. Automated frontend checks run the real component/API code in a simulated browser environment; they are not a full browser end-to-end recording.
