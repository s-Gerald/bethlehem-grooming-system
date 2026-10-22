# Bethlehem Animal Clinic & Grooming System

## PHP runtime and tests

PHP **8.4** is the development and test target. Select the actual interpreter;
changing Composer constraints or an editor setting does not change PHP. PHPUnit's
shared bootstrap rejects PHP older than 8.4, including subprocess fixtures.
Application deployment compatibility remains `^8.2` in `composer.json` and
`composer.lock` until Hostinger's web, CLI, cron and queue runtimes are all verified
on 8.4. Do not set a fake Composer `config.platform.php` value or ignore platform
requirements to bypass that deployment check.

The current local portable interpreter is already installed separately from XAMPP:

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
$clinicComposer = 'C:/ProgramData/ComposerSetup/bin/composer.phar'
& $clinicPhp -v
& $clinicPhp --ini
& $clinicPhp -m
& $clinicPhp $clinicComposer validate --strict --no-check-publish
& $clinicPhp $clinicComposer check-platform-reqs --lock
& $clinicPhp artisan test --compact
& $clinicPhp artisan test --compact --filter=test_simultaneous_stock_in_requests_preserve_exact_stock_and_unique_references
```

`composer test` uses the PHP interpreter that runs Composer. The test command no
longer clears the development application's configuration cache: `phpunit.xml`
already forces an isolated testing cache path, SQLite database and test services.
It also sets the full-suite memory budget to 512 MB, matching the previous XAMPP
test budget. This is a PHPUnit-only limit; the portable runtime defaults to
128 MB, and Artisan does not forward a parent `-d memory_limit` to PHPUnit.
Tests retain strict stock/ledger assertions and use `PHP_BINARY` for their workers.
The stock-in fixture proves an overlapping SQLite writer lock instead of relying
on the configured transaction mode as evidence. PHP 8.2 uses a deferred transaction
in the installed Laravel SQLite implementation; PHP 8.4 honors `IMMEDIATE`.

Laravel requires Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE,
PDO, Session, Tokenizer and XML. This application additionally needs `pdo_mysql`
for its production database; tests need `pdo_sqlite`, XMLWriter and `proc_open`.
Check the actual loaded extensions, not only Composer's declared requirements.
The installed portable PHP 8.4 currently enables SQLite but does **not** enable
`pdo_mysql` or cURL in its private `php.ini`; compatible DLLs are already present.
For a single CLI command you can enable them without editing configuration:

```powershell
& $clinicPhp -d extension=pdo_mysql -d extension=curl -m
```

These `-d` flags are not automatically inherited by `PHP_BINARY` subprocesses.
For multiprocess MySQL tests, enable the extensions in the selected interpreter's
configuration yourself, or use a process-scoped `PHP_INI_SCAN_DIR` pointing to an
owned temporary directory containing an extension-only INI file. Do not point it
at private environment files. The existing isolated MySQL concurrency runner is
`tests/grooming-capacity-concurrency.php`; see
[its isolation and local-only connection rules](docs/mysql-capacity-tests.md).
A normal SQLite suite skip is not a MySQL concurrency pass.

### Manual local XAMPP actions

1. Keep the existing XAMPP installation unchanged while validating portable PHP
   8.4. Confirm the executable, loaded INI, required extensions and
   `PDO::getAvailableDrivers()` with the commands above. Enable `pdo_mysql` and
   cURL in the portable runtime's own INI when you are ready; do not copy an old
   XAMPP INI or PHP DLL into that runtime.
2. Use the explicit 8.4 executable for Artisan, Composer and workers. For a
   temporary PowerShell terminal, prepend its directory to `$env:Path`, then
   confirm `Get-Command php`, `php -v`, `php --ini` and `composer --version`.
   This changes that terminal and its children, not the system PATH.
3. XAMPP Apache's PHP module is independent of CLI PATH. The installed portable
   8.4 binary is NTS and is not a replacement for XAMPP's threaded Apache PHP
   module. For local HTTP testing, you can start `artisan serve` explicitly with
   the portable executable while keeping XAMPP MySQL. If you need Apache itself
   on PHP 8.4, plan a compatible Apache/TS runtime or supported FastCGI setup,
   back up the configuration and verify its requirements before changing it.
4. Restart local web/queue processes under the chosen interpreter and verify the
   localhost workflows yourself. No application migrations are needed for this
   runtime/test change.

### Manual VS Code actions

1. In your User or Workspace Settings, set
   `php.validate.executablePath` to
   `C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe`.
2. Set your PHP language extension's language target to 8.4 and its test runner's
   interpreter to that same executable, using that extension's settings.
   VS Code's linter path does not set the terminal, debugger or test runner PHP.
3. Configure terminal PATH or use the explicit executable; reopen the terminal
   and check `php -v`. Configure a matching PHP 8.4 debugger/Xdebug build only
   if you use debugging. No personal editor settings were changed here.

### Manual Hostinger actions before raising the Composer minimum

1. Confirm PHP 8.4 availability for the actual hosting plan. Record the current
   site's web runtime, the SSH CLI binary, all scheduler/cron commands and queue
   workers. They may use different PHP versions. No hosting settings or
   production databases were accessed in this task.
2. Prepare a matching staging release, required extensions and a rollback plan.
   Verify web and CLI PHP 8.4, run Composer's real platform checks, the automated
   suite and isolated database tests on staging/disposable databases, then do
   the cash/inventory/browser checks yourself. Do not run fixture tests against
   production or run `composer update` on the live site.
3. When ready, switch the site's PHP configuration through hPanel and update
   CLI/cron/queue commands to the verified absolute PHP 8.4 binary. Restart
   long-lived workers using your existing deployment process and check logs.
   Preserve private `.env`, database contents and historical financial records.
4. **Only after all deployment runtimes are verified**, change the root PHP
   requirement to `^8.4` in a separate reviewed change. Refresh lock metadata
   with Composer under PHP 8.4 without changing package versions, review the
   complete diff, and repeat platform/test checks. Deploy the matching lockfile
   with `composer install --no-dev --optimize-autoloader`, never
   `--ignore-platform-reqs`. Do not fake the resolver platform to make an older
   server appear compatible.

Runtime references: [Laravel 12 support](https://laravel.com/docs/12.x/releases),
[PHPUnit support](https://phpunit.de/supported-versions.html),
[Laravel server requirements](https://laravel.com/docs/12.x/deployment#server-requirements),
[VS Code PHP executable settings](https://code.visualstudio.com/docs/languages/php),
[Hostinger PHP configuration](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/).

### Verified results (October 23, 2026)

- PHP 8.4.26, PHPUnit 11.5.55, Laravel 12.66.0; SQLite 3.53.4.
  The unchanged stock-in test passed on 8.4 and failed on XAMPP PHP 8.2.12 /
  SQLite 3.39.2. A disposable two-connection probe confirmed only the 8.4
  Laravel connection acquired the requested immediate writer lock.
- Strengthened exact stock-in test: **1 passed, 35 assertions**. Composer's
  existing test command also passes that exact case using the selected 8.4 CLI.
- Focused inventory/POS/cash/pricing/history suite: **162 passed, 2,728 assertions**.
- Complete PHP suite: **1,460 passed, 8 skipped, 0 failures; 19,639 assertions**.
  Six skips are the separate Dashboard MySQL fixture, one is the dedicated
  MySQL capacity test, and one is the MySQL timestamp-migration fixture.
  The capacity case was separately executed successfully, as described next.
- Existing isolated MySQL concurrency runner: **1 passed, 30 assertions** on
  local MariaDB 10.4.32, with overlap proof and verified disposable schema
  removal. This is Grooming intake-lock coverage, not a claim of a separate
  MySQL stock-in test. Stock-in contention is exercised by its SQLite fixture.
- `npm test`: **73/73 scripts passed**. `npm run build`: passed, with the existing
  outdated Browserslist-data notice. No browser workflows were performed.
- Composer strict validation, installed/locked platform checks (including
  `--no-dev --lock`), and locked dependency conflict checks pass for PHP 8.4.26.
  No packages or lockfile changed. MySQL/cURL were enabled only for the test
  process tree through an owned temporary extension INI, not private settings.

The full 8.4 run also reproduced a history-only half-cent discrepancy in newer
SQLite: `ROUND(0.5 * 0.03, 2)` returned 0.01 instead of the intended 0.02. The
existing pet-history aggregate now scales two-decimal quantities/prices and
rounds each line in cents before summing. Its six regression cases pass and
match existing MariaDB DECIMAL rounding in a local SELECT-only check. No paid
amounts, pricing rules, database records or settlement logic were changed.
Pagination and query count are preserved; the correction adds no network or
per-row database queries. PHPUnit's 512 MB limit also prevents the portable
runtime's lower default from aborting the full suite.

Local validation is complete. System/XAMPP/VS Code/Hostinger standardization and
raising Composer's minimum remain pending the manual actions above. No system
packages, private INI/.env files, production databases or hosting settings were
changed. No commits, pushes or deployments were performed. There are no new or
deleted tracked files: changes are confined to this README, `composer.json`,
`phpunit.xml`, `tests/bootstrap.php`, the existing InventorySecurityRegression
and AdminPetProfile tests, AdminPetProfileController's history aggregate, and
`docs/cash-only-cleanup.md`'s follow-up status.

## Email verification setup

Registration verification is a one-time email ownership check. Entering a
valid signup verification code creates the customer's first signed-in
session. Later customer, admin, and staff sign-ins confirm the password first, then send a
separate six-digit email code that must be entered in the original browser tab.
The code is bound to that tab's private login challenge, and a successful entry
creates the session and continues to the appropriate dashboard.

For local development, use Brevo SMTP or a local SMTP catcher in `.env`, set
`FRONTEND_URL` to the browser-facing application root, and use a valid sender.

Queued signup-verification and login-confirmation mail require a running worker:

```powershell
php artisan queue:work
```

The provided `composer run dev` command starts the queue listener together with
the web server. Production must not use the `log` mailer or an `example.com`
sender; registration rejects that unsafe configuration instead of claiming an
email was delivered. Registration codes cannot use a log mailer even locally.

See [Hostinger email authentication setup](docs/hostinger-email-auth.md) for the
Brevo environment settings, every-minute worker cron, deployment commands, and
live verification checklist. Queued messages are shown as pending until the
worker's mail transport accepts them; worker delivery failures use the existing
verification error messages. Delivery receipts are private to the signup tab or
password-confirmed login and never contain an OTP or SMTP credentials.

## Privileged seed accounts

Admin and staff seeders do not contain default passwords. Set the
`ADMIN_SEED_*` and `STAFF_SEED_*` values shown in `.env.example` before running
`php artisan db:seed`; passwords must contain at least 12 characters. Changing
the seeders does not rotate credentials already stored in an existing database.

Rotate an existing privileged account with an interactive, hidden password
prompt (the command also revokes that account's current sessions):

```powershell
php artisan account:rotate-privileged-password admin@example.com
```
