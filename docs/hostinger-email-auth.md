# Hostinger email authentication

The test deployment is https://darkorchid-horse-104804.hostingersite.com/.
Keep Laravel notifications, the encrypted database queue, and the existing Brevo
SMTP relay. Commit `5c12883` commented out the login challenge; the restored
flow requires a six-digit code for customer, staff, and admin password sign-ins.
Registration still requires email verification before creating the account.

## Confirmed test-host inspection — 2026-10-18

Read-only checks through the authenticated Hostinger connector confirmed:

- The deployed sign-in controller still contains the commented-out OTP gate.
- Both the private `.env` mailer selection and Laravel's effective cached mailer
  are `log`. This configuration cannot deliver real inbox mail. Clearing the
  configuration cache alone will not fix the `.env` selection.
- The queue driver is `database`, with zero jobs queued at inspection time.
- The environment is `staging`, debug is disabled, and configuration is cached.
- PHP 8.3 and the required extensions are available.
- No permanent hosting cron jobs were configured. Temporary read-only diagnostic
  crons were removed after collecting their output; the final list was empty.

No Brevo credentials were returned, replaced, or copied. Credential validity,
the verified sender, and actual inbox delivery required separate rollout checks.
The existing Laravel log contained an earlier `storage:link`
failure involving a disabled `exec()` function, not evidence of an SMTP failure.

The approved rollout preflight subsequently found no usable SMTP password in the
private `.env`, effective cached mail settings, or hosting process environment.
Rollout stopped before application files or configuration were changed. Temporary
preflight crons were removed and the final cron list was empty. The owner must
privately configure the existing Brevo SMTP key as `MAIL_PASSWORD` on Hostinger
before the approved rollout can continue. Do not place that key in this document,
the release archive, source control, or chat.

## Approved test-site rollout — 2026-10-18

After the owner privately configured the SMTP key, Brevo SMTP authentication
succeeded. The host setting needed correction to `smtp-relay.brevo.com`.
The 17-file release was installed with server-side backups outside the document
root. Only the required non-secret environment settings were updated; credentials
and the owner's `MAIL_FROM_NAME` were preserved. Laravel's cached mailer is now
`smtp`, its queue is `database`, and the site is out of maintenance.

CLI opcode caching initially retained the previous configuration within the
deployment process. The safety check rolled back the attempted release. Explicit
invalidation of the regenerated configuration cache resolved the mismatch before
the successful rollout. All 17 deployed file checksums matched the release.

One permanent bounded database worker cron is installed every minute. It
successfully processed a harmless queued closure without sending email or
changing accounts. Six failed-job records existed before that diagnostic job
ran; they were preserved and were not automatically retried. Public smoke checks
confirmed the new frontend assets, private receipt-status endpoint, rejection of
unknown login challenges without access tokens, and the existing resend throttle.

The owner used an existing customer account and entered credentials directly in
the deployed UI. The application showed the six-digit code screen, the permanent
worker completed `ConfirmLoginNotification` through Brevo, and the owner confirmed
sign-in with the correct OTP. Browser inspection then confirmed the customer
dashboard redirect. No additional customer account was created, credentials were
not reset, and no test resend was requested.

Both privileged-account tests also reached the OTP screen. The worker completed
each login notification through Brevo, and browser inspection confirmed the
shared staff/admin dashboard redirects after the owner's direct OTP entries.
The owner then confirmed that all accounts were working. No passwords or OTPs
were requested in chat or extracted from application storage.

Fresh customer registration and a live resend were not exercised because the
owner requested existing accounts only and minimum test sends. The focused
automated verification passed 134 backend tests (1,658 assertions) and three
frontend regression scripts, including registration, resends, invalid/expired
codes, attempt limits, throttles, delivery failures, and redirects. These checks
do not substitute for a fresh registration inbox test on the host.

All temporary deployment/verification files and cron jobs were removed. Only the
permanent every-minute queue worker remains. Server-side release backups were
retained outside the document root. SMTP login and worker completion alone do
not establish inbox receipt; record only the owner's confirmation.

Hostinger limits cron commands to 255 characters including its escaping overhead.
Use the absolute Artisan path and short commands. For this deployment, a direct
every-minute worker is also suitable and avoids enabling unrelated scheduled
tasks:

```sh
/usr/bin/php /home/ACCOUNT/domains/DOMAIN/public_html/artisan queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --sleep=1
```

The direct worker cron is the selected setup for this test deployment.

## Hosting environment

Set these values only in the hosting environment or private `.env`. Reuse the
existing `APP_KEY`, database configuration, Brevo SMTP login, SMTP key, and
Brevo-verified sender. Do not regenerate `APP_KEY`: queued notifications are
encrypted with it. The sender address is not the SMTP login.

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://darkorchid-horse-104804.hostingersite.com
FRONTEND_URL=https://darkorchid-horse-104804.hostingersite.com
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<existing Brevo SMTP login>
MAIL_PASSWORD=<existing Brevo SMTP key, not an API key>
MAIL_FROM_ADDRESS=<existing Brevo-verified sender>
MAIL_FROM_NAME="Bethlehem Animal Clinic"
MAIL_TIMEOUT=30
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Port 587 uses SMTP with STARTTLS. Keep certificate verification enabled.
If the host blocks that port, confirm the restriction before using Brevo's
supported alternatives; do not switch providers. Check Brevo's sender status,
transactional account activation, sending quota, SMTP key validity, and allowed
server IPs if the relay rejects mail.

Both CLI and web PHP must load the same `.env`, database, cache, and `APP_KEY`.
Keep the test host's existing `staging` environment; use `production` for the
final production deployment.
The database cache shares delivery status and rate limits across requests and
workers. `array`/`null` cache stores are unsuitable for deployment. Never use
`log`, `array`, or a mail failover containing those transports in production.

## Deployment and cron

Run from the deployed Laravel project root, using the same PHP 8.2+ CLI binary
selected for the site. Replace `/home/ACCOUNT/domains/DOMAIN/APP` with the actual
directory containing `artisan`; the public URL does not reveal that directory.

```sh
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

In hPanel, configure one **Custom** cron job, every minute (`* * * * *`):

```sh
/usr/bin/php /home/ACCOUNT/domains/DOMAIN/APP/artisan queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --sleep=1
```

The diagnostic confirmed `/usr/bin/php` can boot this deployed Laravel app.
Use the actual project path from the connector's website listing. Check the
existing cron list and any supervised worker before adding a duplicate.

The worker exits after draining available jobs or reaching its time limit.
SMTP's 30-second timeout is
below the worker's 45 seconds, which is below the database queue's default
90-second reservation. Hostinger CLI should support `pcntl` for worker timeouts;
check `php -m`. Laravel's database queue reserves each job so concurrent worker
processes do not normally process the same job.
Notification-specific retry attempts and backoff remain unchanged. Delayed retry
jobs run on subsequent cron ticks. Mail can take roughly a minute to start on
shared hosting. The cron output endpoint captures console output; exceptions and
terminal failures remain in Laravel's logs and `failed_jobs`.

If the account provides a supervised persistent worker, use that instead.
Restart it after each release. Do not
run both worker setups unintentionally. Do not expose a public HTTP cron route.

The repository contains no Hostinger credentials, server path, or hPanel cron
configuration. These hosting steps require deployment access; checking the code
alone cannot establish that the cron is installed or that Brevo delivered mail.

## Verification on the test host

Use inboxes you control and dedicated customer, staff, and admin test accounts.
Never log or commit OTPs, passwords, queue payloads, or mail credentials.

1. Register a customer. Confirm a pending registration and queued job exist,
   wait for the cron, and receive the actual Brevo verification email. Enter its
   code: the customer is created, signed in, and redirected to the customer dashboard.
2. Sign out, then sign in with the verified customer's password. No access token
   is issued until the received login code is submitted. Confirm the dashboard.
3. Repeat password, inbox code, and dashboard checks for staff and admin.
4. Resend a login code after the existing countdown. Receive the replacement;
   the previous code fails and the replacement succeeds. Failed dispatch must
   retain the previous challenge. Resend does not reset the invalid-code counter.
5. Check wrong codes, five incorrect attempts, expired codes, expired challenges,
   and the existing resend/request throttles. None may create a session.
6. On the test host only, simulate relay rejection and confirm the current error
   panel appears without credentials or a success claim. Restore the private
   configuration and rebuild its cache. Confirm a retry or resend is delivered.
7. Check `php artisan queue:failed`, queue/scheduler logs, and Brevo transactional
   delivery events. An empty queue alone does not prove inbox receipt. Avoid
   retrying expired authentication jobs; request a fresh code instead.

New jobs include a private delivery receipt in their encrypted queue middleware.
The UI polls every five seconds for at most two minutes and distinguishes queued,
transport-accepted, failed, and delayed delivery. The status endpoint never returns
email addresses, codes, exception details, or credentials. Public email recovery
resends expose no receipt unless the private pending-registration edit credential
matches, preserving the existing generic recovery response. A receipt grants no
authentication rights; code verification remains mandatory.

References: [Brevo SMTP settings](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP),
[Laravel queue workers](https://laravel.com/docs/12.x/queues), and
[Hostinger cron setup](https://support.hostinger.com/en/articles/1583465-how-to-set-up-a-cron-job-at-hostinger).
