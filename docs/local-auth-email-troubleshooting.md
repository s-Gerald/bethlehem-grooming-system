# Local authentication email diagnosis — 2026-10-18

> For the October 30 independent-challenge migrations, current security defaults, safe local testing and verification results, see [authentication-security-hardening.md](authentication-security-hardening.md). The diagnosis below is historical; authentication mail still cannot use a logging transport.

## Confirmed failure

The localhost sign-in error was a database failure before email queue dispatch:
`SQLSTATE[42S02]`, MySQL error `1146`, missing `login_email_challenges`.
The local log traced it through `LoginEmailChallengeService::send()` to the
sign-in controller's generic email-queue error response. A read-only model query
reproduced the exception.

`jobs`, `job_batches`, and `failed_jobs` existed and had the expected columns.
The missing login table, pending customer registration table, and personal access
token table had already-run migration records. Migration history alone did not
establish that the tables were present. The inspection did not establish what
originally removed them.

The private `.env` and effective configuration agreed: local MySQL, database queue
and cache, `AUTH_MAIL_DELIVERY=immediate`, and Brevo SMTP. Configuration was not
cached. Credentials and sender were present; no credential values were printed,
changed, or tested against Brevo. No SMTP connection or real email was attempted.

## Local repairs

Restored only these absent tables from the existing migrations and their existing
authentication-column additions:

- `login_email_challenges`
- `pending_customer_registrations`
- `personal_access_tokens`

Existing tables were not recreated, data was not reset, and migration records
were not deleted or rewritten. Existing row counts were unchanged.

The local server also has `explicit_defaults_for_timestamp=OFF`. Replaying the
original login migration consequently created `expires_at` with an unintended
`ON UPDATE CURRENT_TIMESTAMP` attribute. Approving a code changed its expiry and
prevented the existing lost-response confirmation retry. A real MySQL check
reproduced that failure.

The existing login-table migration now gives the timestamp an explicit default,
preventing MySQL's implicit automatic-update behavior. Only that attribute was
corrected on the local login table; expiration values, non-nullability, indexes,
and the application's configured expiry window were preserved. No new migration
or global MySQL configuration change was introduced.

PHPUnit previously inherited the local immediate-mail policy. That caused queued
test fixtures and notification fakes to run the immediate path unexpectedly.
`phpunit.xml` now explicitly isolates the test environment, SQLite in-memory
database, array cache/mail, queue policy, and configuration-cache path. The test
bootstrap synchronizes PHPUnit's forced values into `$_SERVER`, which Laravel
reads ahead of `$_ENV`; a configuration-only probe reproduced and verified this
additional inherited-Windows-environment issue. An environment regression test
covers these safeguards. Individual immediate-delivery tests still explicitly
enable and exercise immediate mode.

## Verification

- 139 focused backend tests passed, with 1,793 assertions: email verification,
  password recovery, staff setup/management, session isolation, authentication
  interfaces, and queued grooming completion notifications.
- Four frontend regression scripts passed: authentication/session, signup
  verification, email delivery tracking, and inactivity handling.
- Separate real-Laravel/local-MySQL checks used temporary generated accounts,
  a unique queue/cache namespace, and an in-memory array mail transport. For
  customer, staff, and admin they verified login HTTP 202, post-response sending,
  encrypted persisted jobs, no duplicate send during queue cleanup, HTTP 429
  cooldown, successful resend, HTTP 422 for the replaced code, and HTTP 200 for
  the current code and lost-response confirmation retry.
- Local MySQL registration returned HTTP 201, code verification established a
  customer session with HTTP 200, and queue cleanup did not duplicate mail.
  Forgot-password request, code verification, and reset returned HTTP 202/200/200.
- Temporary fixtures and their queue/cache/session records were removed. Existing
  row counts were preserved. The ordinary local queue was not drained.

## LOCAL next step

Localhost is ready for the owner's manual inbox test using existing accounts.
No additional `.env` edits are required by these findings. SMTP connectivity,
Brevo credential acceptance, and real inbox latency remain unverified. Keep
credentials and received codes out of reports and chat. If testing retries with
a long-running local worker, restart it normally so it uses current settings;
only start or resume real mail processing when ready for real sends.

## HOSTINGER

No Hostinger connection or action was performed. No Hostinger configuration or
infrastructure change is required for this local test. Do not replay this local
schema repair remotely without a separate diagnosis and deployment approval.
