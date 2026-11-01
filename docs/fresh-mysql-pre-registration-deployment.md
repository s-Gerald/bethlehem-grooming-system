# Fresh MySQL initialization and automatic registration expiration

## Correction

The October 9 status migration called the current `PreRegistrationExpiry` service.
That service loads `ClinicSetting::current()`, whose groomer count queries
`pet_groomers`. The table is only created by the October 16 named-groomer migration.
This produced MySQL error 1146 on a fresh database, even with no registrations.

The correction removes only the runtime expiry call from the historical migration.
Its enum expansion, historical unused-No-Show normalization, legacy-status audit
column, notification relationship and history-preserving rollback remain intact.
No migration is renamed or added. Laravel does not rerun this migration on an
installation where it has already been recorded as applied.

Runtime expiration remains in the existing command and request safeguards.
`routes/console.php` still schedules `pre-registrations:expire` every minute in
`Asia/Manila`, with `withoutOverlapping()`. The service retains its settings/row
locks, transactional status changes, activity records and notifications. Its
independent service hours, saved deadlines, closure/rescheduling exceptions,
checked-in/paid/exempt protections and active-grooming protections are unchanged.
Eligible rows become `no_show`; they are retained and excluded from Incoming.
The existing presentation sends staff notifications for both services and a
customer notification for grooming. This correction adds no notification policy.

## Local verification

Use the PHP 8.4 test interpreter documented in the README, with `pdo_mysql` enabled:

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $clinicPhp tests/grooming-capacity-concurrency.php --pre-registration-fresh
& $clinicPhp vendor/phpunit/phpunit/phpunit --filter 'QueuePreRegistrationModelTest|GroomingAdministrationAuthorizationTest|ClinicAdministrationAuthorizationTest|CustomerPreRegistrationAccessTest|ActivityLogTest|NamedPetGroomersMigrationTest'
```

The MySQL option reuses the existing guarded local-only capacity harness and its
`CAPACITY_TEST_MYSQL_*` settings; see [isolation details](mysql-capacity-tests.md).
It does not use the application's database credentials. It creates a random owned
schema, applies ordinary migrations, inserts test fixtures and verifies cleanup.
Do not run fixture tests directly against an existing application database.
The full-schema case skips on the normal SQLite configuration and must pass with
the MySQL option above; a SQLite skip is not MySQL verification.

Verified locally on MariaDB 10.4.32 with PHP 8.4.26:

- The full MySQL integration case passed with 59 assertions. The related SQLite
  run passed 545 tests with one MySQL-only case skipped (546 total, 6,804 assertions).
  The final strengthened historical-migration case passed separately with 15 assertions.
- Before correction: fresh initialization reproduced error 1146 at October 9.
- After correction: all 141 migrations completed; `pet_groomers` was absent after
  October 9 and present immediately after its intended October 16 migration.
- Relevant groomer-assignment and clinic-notification foreign keys remained intact.
- Grooming closed at 16:00 and clinic at 18:00 in fixtures. Both remained Incoming
  before their deadlines and expired through the Artisan command at their own
  closing times, before any request could process them.
- No Show and legacy expired rows were excluded from actual staff Incoming responses.
- Checked-in, paid, exempt and active-grooming rows were protected. Related records,
  staff/customer notifications and activity history remained; repeats added no
  duplicate No Show events or notifications.
- An ordinary `migrate --force` rerun reported nothing to migrate and left populated
  fixture tables and migration history unchanged. Existing-database compatibility
  was verified on this disposable populated installation, not on a live database.
- All disposable schemas were removed. Existing local and Hostinger databases were
  not modified. The production change only removes a call; it introduces no PHP
  syntax or dependency changes and retains PHP 8.3 compatibility. Tests ran on 8.4.

## Hostinger release and scheduler

After reviewing, committing and pushing this correction yourself, pull it into the
intended new `bethlehem-app` directory using your normal release process. The app
stays outside `public_html`. No frontend build or replacement migration is needed.

Use the actual absolute app path (`pwd` inside `bethlehem-app`) wherever
`/ABSOLUTE/PATH/TO/bethlehem-app` appears below. Verify the PHP binary on your plan:

```sh
/opt/alt/php83/usr/bin/php -v
```

Hostinger documents [version-specific PHP executable paths](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/).
If your verified PHP 8.3 CLI has a different absolute path, use that executable
consistently for deployment and cron. No hosting connection or runtime check was
performed remotely during this task.

In the correct new app directory, confirm its environment points to the intended
new database before running these deployment commands yourself:

```sh
cd /ABSOLUTE/PATH/TO/bethlehem-app
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan schedule:list
```

Use ordinary `migrate`, never `migrate:fresh`, reset or refresh on an existing
database. An installation that previously stopped at October 9 can retry ordinary
`migrate --force`: the migration preserves existing enum values and guards its
added columns. Already applied migrations remain recorded and are not rerun.

In **hPanel > Websites > Manage site > Cron Jobs**, select **Custom** and set
minute, hour, day, month and weekday each to `*`. Enter this command:

```sh
/opt/alt/php83/usr/bin/php /ABSOLUTE/PATH/TO/bethlehem-app/artisan schedule:run
```

The equivalent complete crontab entry is:

```cron
* * * * * /opt/alt/php83/usr/bin/php /ABSOLUTE/PATH/TO/bethlehem-app/artisan schedule:run
```

Laravel requires [one scheduler invocation every minute](https://laravel.com/docs/12.x/scheduling#running-the-scheduler).
Hostinger's [cron configuration](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)
uses UTC, but every-minute execution needs no offset; the application's deadline
calculation and scheduled event use Manila time. Keep one scheduler entry for this
app. Do not add a second cron calling the expiry command directly.

The underlying command is `php artisan pre-registrations:expire`. For a manual
deployment check, use the verified PHP executable and full Artisan path with that
command after migrations finish. Automatic processing uses `schedule:run` and needs
no web visit. Check `schedule:list` for the every-minute expiry event and inspect
cron output after enabling it. Use a persistent configured cache store (the current
default is `database`) for overlap locks; its tables must be migrated and accessible.
No separate queue worker is required for the expiry service's database notifications.
