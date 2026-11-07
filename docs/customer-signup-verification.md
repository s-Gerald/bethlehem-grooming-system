# Verify-first customer account creation

Current implementation: November 7, 2026, branch `gerald-frontend`.
This replaces the former pending-profile, registration review, pairing and session-handoff architecture. Historical migrations remain unchanged so existing databases can upgrade.

## Workflow and boundaries

1. The existing signup form validates locally. Its submitted values, including password and confirmation, remain only in the active page's JavaScript memory.
2. `POST /api/register` receives only `email` and a randomly generated 64-character `registration_token`. It creates an email-verification attempt, not a customer. `Check your email` appears inside the original signup card; the page does not unload.
3. The verification email links to `/verify-email#token=...` using `FRONTEND_URL`. The fragment is captured in memory and removed before external resources load. The initial GET is inert. Clicking **Verify Email Address** posts the token to `/api/email/verify`.
4. The POST locks and approves exactly that attempt. It consumes the link, issues no session, creates no user and discloses no profile or browser credential. The verification device sees **Email verified! Return to the device where you started creating your account.**
5. The original page checks `/api/email/registration/status` about every five seconds. Checks pause when hidden and resume promptly on visibility/focus. Status and all attempt controls require both the opaque attempt token and the HttpOnly `bethlehem_registration_browser` cookie, plus the existing XHR/origin checks.
6. On approval, the original page submits its retained values to `POST /api/register/finalize`. The backend revalidates names, optional username/Philippine mobile, exact approved email, current customer password policy and confirmation, and affirmative Terms acceptance. The server supplies the accepted Terms version/date. Password screening and hashing occur before acquiring the finalization row lock.
7. A database transaction rechecks the binding, approval and one-hour deadline, checks completed/staff account reservations, creates the verified customer, and issues the first session through `AccountSessionService`. The existing middleware returns an HttpOnly account cookie and a public `id|cookie` reference. Signup accepts the session and opens `/account`; there is no immediate sign-in OTP.

The verification page never finalizes an account, including when opened in the same browser or another tab. Sign-in's six-digit email OTP, Remember Me, account/session expiration, password reset, staff recovery and mobile verification remain independent.

## Stored state and retry behavior

`pending_customer_registrations` contains only: ID, email, hashed attempt/browser/link credentials, link issuance/expiry, approval/completion timestamps, completed session ID, and timestamps/indexes. It contains no name, username, phone, password/hash, Terms evidence or encrypted session secret. Pending email is indexed but not unique.

The browser's `bethlehem_signup_attempt` session-storage entry contains only the opaque token, absolute attempt deadline and resend deadline. Delivery tracking may store an opaque receipt separately. No password or form draft is written to browser storage. Existing obsolete signup drafts are removed. Without browser storage, the current page still completes normally; after reload the customer may need a fresh attempt.

Refresh, browser eviction or navigating away discards the password. With valid original binding, status supplies the attempt email and the customer re-enters missing details on signup. An existing approval can finalize without another verification. An expired/unavailable binding leads to a fresh attempt. Email alone cannot recover or redeem an approval.

Duplicate starts with the same credential resume the same row without rotating a usable link or sending another email. Different browsers can create isolated attempts for the same email, subject to existing destination cooldowns and abuse limits; they receive no earlier credentials or grants. Changing email requires a new attempt. Other fields can change before finalization.

Finalization is idempotent under its attempt lock. A retry after a lost response returns the same usable first session. Its secret is derived using a domain-separated HMAC over both original browser credentials and the attempt ID; only its hash lives in Sanctum and only the session ID lives in the attempt. It cannot restore revoked/replaced/locked/expired sessions or issue a second session. A compromised database alone does not contain a recoverable session bearer. The application key is required for response recovery, so key rotation ends this limited recovery window.

Completed-account uniqueness remains database-backed. Concurrent finalizations of independent attempts can create only one account for colliding email/username/mobile values; the losing attempt keeps its approval and can correct recoverable field conflicts. Staff reservations still apply. Unfinished customers reserve no username, mobile or email, including during staff/profile/mobile operations.

## Links, delivery and retention

Links last at most 30 minutes and never beyond the attempt's original one-hour deadline. Approval remains usable until the attempt's one-hour deadline even after the consumed link expires. Reopening a consumed link only reports that it has already been used; it cannot approve twice or create accounts/sessions. Invalid/replaced, expired, consumed and expired-attempt responses are distinct. An unknown hash cannot safely distinguish a random invalid link from a replaced one, so that response accurately says invalid or replaced.

Resends require both original credentials. The existing email policy enforces cooldown, destination/source/provider quotas and persistent shared locks. A rejected resend leaves the existing link intact. A successful resend rotates only that attempt's link. An approved attempt does not resend or discard approval. Failed queue insertion rolls back issuance and quota reservations. Expiry is security/cleanup policy, never a mandatory customer lockout.

The existing Laravel/Brevo path is unchanged: encrypted notification work is durably inserted with the issuance transaction; `AUTH_MAIL_DELIVERY=immediate` attempts transport after commit/response termination, and the existing database worker provides fallback. No SMTP waits inside a database transaction. Receipt/issuance guards suppress stale, consumed, cancelled and expired jobs. Jobs cannot rotate links or recreate deleted attempts. The obsolete public-registration processing job is removed; old such jobs cannot authorize the new flow.

The existing `pending-customer-registrations:prune` scheduler removes expired metadata every five minutes. Account records and sessions are not deleted by this cleanup. Expiration checks reject attempts at the deadline even if scheduled cleanup has not run.

## Upgrade and Hostinger deployment

No deployment, production environment edit, live email send, shared-cache flush or application database migration was performed by this refactor.

Deploy backend, HTML, JavaScript and CSS together. Signup and verification asset URLs carry `registration=20261107-v1` / `v=20261107`. Do not serve old registration scripts against the new API. Have existing browsers refresh signup after deployment.

Use the actual Hostinger PHP CLI path and application directory. Review the existing queue/scheduler configuration; do not add duplicate workers. In a maintenance window:

```sh
# Allow the existing bounded worker to finish; pause its cron during the code/schema switch.
# Drain already-queued work with the old release before removing its obsolete job class.
php artisan down
# Install/upload the new release and its built assets.
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan up
# Resume the existing bounded worker cron.
php artisan schedule:list
```

Build artifacts locally/CI with `npm ci` and `npm run build`, or build on hosting if Node is available. Include the generated `css/output.css`, versioned frontend files and `public/build` outputs used by the existing deployment layout.

Migration `2026_11_07_000001_make_customer_registration_verify_first.php` replaces **only** the disposable pending-customer table. It invalidates old unfinished signup attempts, including old review/pairing tokens. It does not reset the database, migrate passwords into the new table or delete completed users/business records. The migration can resume if the temporary table is missing or partially created; a completed repeat invocation preserves new attempts. Completion requires all 12 verification-only columns, the primary key and all four explicitly named indexes, with their intended uniqueness. Run in maintenance because MySQL DDL is not fully transactional. Take the normal database backup first.

The November 7 MySQL identifier repair uses `pending_reg_edit_token_uq`, `pending_reg_email_token_uq`, `pending_reg_email_idx` and `pending_registration_created_idx`; all names fit MySQL's 64-character limit. The former autogenerated edit-token unique name was 66 characters. MySQL could commit the columns before rejecting that index, and the former two-column guard then incorrectly treated this partial table as complete. Incomplete tables are now rebuilt within this temporary-table scope, including removal of any obsolete unique email reservation.

For the reported failure while the migration is still **Pending**, upload the corrected migration and rerun only that file:

```sh
php artisan migrate --path=database/migrations/2026_11_07_000001_make_customer_registration_verify_first.php
php artisan migrate:status --path=database/migrations/2026_11_07_000001_make_customer_registration_verify_first.php
```

This recovery was executed successfully against the existing local `bethlehem_grooming` database on MariaDB 10.4.32. Status changed from Pending to **Ran**, batch 114; inspection confirmed 12 columns, five indexes, no profile/password columns, zero pending attempts and the unchanged count of 27 users. No unrelated migration was run. `VerifyFirstRegistrationMigrationTest` covers missing token indexes, a missing column, obsolete email uniqueness and preservation of complete attempts in SQLite and an owned disposable local MySQL schema. Opt in to its MySQL case with `REGISTRATION_MIGRATION_MYSQL_TEST=1`; it never uses the application database for its fixtures.

The migration follow-up passed **260 tests / 4,622 assertions**, including registration, sign-in OTP, password reset and account-session regressions. Log: `storage/logs/registration-mysql-migration-tests.txt`.

Rollback requires the old application release plus this migration's `down()`, which restores the old temporary table schema empty. Temporary attempts cannot be restored; completed account records remain. Do not run `migrate:fresh`, database reset, or shared authentication cache clearing.

Preserve existing verified sender/Brevo credentials, HTTPS `APP_URL`/`FRONTEND_URL`, allowed origins, database queue and shared database/Redis cache. Keep `AUTH_MAIL_DELIVERY=immediate` where already configured. No always-running queue daemon is required. The existing bounded worker remains suitable:

```sh
php artisan queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --sleep=1
php artisan schedule:run
```

These are examples for the existing worker/scheduler, not instructions to install duplicate cron entries. Any configured `AUTH_MAIL_QUEUE` must remain included in the current worker's queue list.

## Verification

- Final affected backend run: **651 tests, 8,718 assertions, no failures/errors; one opt-in MySQL phone migration test skipped.** Focused backend security/workflow, queue and real concurrent SQLite tests are in `VerifyFirstRegistrationTest` and `EmailVerificationTest`. Shared schema and concurrency fixtures live in `tests/Support`.
- Final delivery-guard follow-up: **194 tests, 2,511 assertions, all passing**, including registration, OTP, password recovery and privileged mail. A reproduced stale-recipient race is fixed: immediate delivery checks the current approval row even when its recipient object predates approval.
- Existing OTP/security/session, password policy/reset/change, email/name/mobile and staff-account regressions remain in the affected test run. Obsolete pairing, profile reservation and foreign-device review expectations were replaced.
- Frontend behavior is covered by `customer-signup-verification-regression.cjs`, `frontend-auth-session-regression.cjs` and `email-delivery-regression.cjs`, including original-page waiting, duplicate requests, edit/email changes, reload recovery, hidden-tab polling, storage denial, safe verifier behavior and stale-session response protection.
- `npm run build` passes with the existing outdated Browserslist-data notice.
- The full frontend run passed 94/96 scripts. The unchanged `client-settings-loading-regression.cjs` and `customer-data-cache-regression.cjs` harnesses fail because they do not load `CredentialPolicy` before executing the unchanged customer-settings module. Those unrelated harnesses were not modified.

Backend tests use isolated in-memory/disposable SQLite, fake breach screening and non-sending mail. On this Windows environment use the repository's PHP 8.4 test runtime; the sandbox cannot read the tracked test bootstrap, so tests run through the authorized external PHP process. Logs: `storage/logs/verify-first-php-final.txt`, `verify-first-mail-final.txt`, `verify-first-frontend.txt`, `verify-first-build.txt`.

Before deployment approval, manually verify on staging/Hostinger:

- Staging/Hostinger upgrade/rollback and concurrent finalization with the existing case-insensitive unique user email/username indexes and unique mobile index. Local MySQL clean installation and partial-schema recovery are covered by the migration follow-up above.
- HTTPS cookie/origin behavior, same-device new tab and real separate-device approval. The verification device must receive no account cookie; the original signup page must open `/account` without another OTP.
- Real Brevo transport acceptance, delayed worker retry and existing worker/scheduler cron execution.
- Mobile page eviction/reload with approved email, corrected finalization conflicts and lost-response recovery.
- Rendered signup/waiting/edit/error/verification states at 320, 375, 414 and 768px, keyboard focus and reduced motion. Browser inspection could not run here: the in-app browser could not reach the loopback preview, and native browser control stopped because it could not establish the current URL for policy enforcement. No rendered-layout or real-mail claim is made.
