# Authentication security hardening — October 30, 2026

Implemented locally on `gerald-frontend`, starting from clean commit `1c5f582`. No commit, push, deployment, production migration, hPanel/DNS/CDN/WAF change, or real email send was performed. Only the existing localhost browser tab was inspected; the owner reserved browser navigation and form/OTP/password testing.

## Findings and implementation

| Priority | Previous problem | Result |
| --- | --- | --- |
| 1 | A unique account constraint and update-in-place issuance replaced another browser's OTP. Returning to sign-in could queue another email. | Independent cryptographically random 64-character challenge credentials, hashed tokens, challenge-bound HMAC codes, encrypted resumable delivery receipts, and password-proven resume of that browser's existing token. Resend changes only its own code, preserving the deadline and failures. |
| 3 | Five failures restricted the whole account; there was no account/source budget. | Five failures per challenge, five per account/source, 25 per source, and 20 per account. Shared counters retain a 900-second window across challenges, resends and IP changes. |
| 4 | One destination cooldown mixed purposes, while some credential/setup/recovery emails bypassed the shared quota policy. Public recovery exposed destination sending history. | Purpose cooldowns/budgets within destination, IP and provider-wide ceilings. All related authentication issuers use the existing policy and durable encrypted queue. Public recovery countdowns describe only that source's submitted requests, independently of private destination history. |
| 5 | No private aggregated incident, owner review workflow, or deduplicated compromise warning existed. | Bounded private account incidents, short-lived source correlation, significant audit events, one initial warning attempt per rolling day, a compact post-login alert, and owner-only activity/review in existing Settings > Security. |

A regression also caught the browser discarding a pending challenge after a temporary verification 429; transient throttling now preserves it. Authentication responses, including private security errors, use `no-store` through the existing response/middleware paths.

The MySQL integration test reproduced an older `expires_at` definition with `ON UPDATE CURRENT_TIMESTAMP`: saving a failure counter immediately expired the OTP. The new forward migration conditionally removes that attribute without changing stored expiration values. No historical migration was rewritten.

## Authentication and storage guarantees

- Possessing a password never creates a session without the required OTP. Tokens are never put in URLs or exposed to another sign-in response. Resume requires both password proof for that account and its existing challenge credential; an IP or browser fingerprint cannot authorize resume.
- Each challenge retains its original remember-me choice, ten-minute default expiry, one-time consumption and atomic session issuance. The existing session-replacement and customer remembered-device rules remain in place.
- At most ten unexpired challenges per account are accepted by default. An excess request receives 429 with the earliest expiry deadline; existing valid challenges are not evicted.
- Confirmation serializes a source's updates with a cache lock and an account's updates with a row lock, then locks the challenge. Issuance/resend use account-before-challenge ordering. Replays cannot create a second session from the consumed challenge.
- A successful OTP clears the consumed challenge and the current account/current-source guessing counters. It preserves global IP abuse history and other source restrictions. Incident history is independent of those guessing counters.
- Queue insertion failures roll back issuance and restore the reserved mail quotas. Nontransactional caches retain quota locks through the outer transaction and use Laravel commit/rollback callbacks to release or restore reservations if a later issuance write fails. A cache on the same database connection rolls back atomically with issuance. SMTP failure cannot authenticate anyone. Tracking still distinguishes queued, sending, retrying, sent, failed and superseded work; queued and transport-accepted do not mean inbox-delivered.
- Login challenges retain only keyed source hashes, not raw addresses or fingerprints. Incidents contain account ID, type, severity, occurrence times, observed failure count, review status and warning-attempt time. They contain no password, OTP, authentication credential, IP address, device or geographic claim.

The existing scheduled challenge cleanup now removes at most 200 expired challenges and 200 expired incidents every five minutes. Incident retention defaults to 90 days. Source correlation retains at most 30 keyed source hashes per account for 30 minutes. The scheduler remains necessary for physical cleanup; OTP expiration is enforced on requests and queued delivery independently of cron.

## Incident policy and interface

Three wrong OTP submissions in the account's guessing window trigger detection. Three distinct password-validated sources within 30 minutes require additional failed verification evidence or three still-pending sources at least two minutes old. Correlation is checked on authentication activity; ordinary expiry alone does not generate an incident, and there is no new background polling or periodic incident-analysis job.

The account row serializes one incident per account/UTC day. Later related failures update it, including after a legitimate success resets guessing counters. New evidence can return a reviewed incident to Needs review. Account-wide restriction escalates severity. Initial detection, account-wide restriction and acknowledgment use the existing audit recorder as non-operational events, separate from private incident details and transient counters.

A persisted warning-attempt time deduplicates across UTC-day boundaries: at most one initial warning queue attempt per account in a rolling 24 hours. Existing encrypted notification retries remain available. If quota or queue capacity rejects the warning, the incident remains reviewable and authentication continues; no retry flood is created. SMTP's usual delivery/acknowledgment limitations still apply.

Only a fully verified privileged login response supplies the private alert reference. It is scoped to that session in browser storage. The shared sidebar lifecycle inserts one inline alert per document; Settings displays its activity section instead of duplicating that alert. The existing notification bell receives no private incident.

Review activity opens `settings.html?tab=security&incident=ID` and focuses the matching incident without acknowledging it. Settings lazily loads at most 20 recent owner incidents once, in parallel with its existing account load. A requested retained owner incident is prioritized within that same bounded query, so an older unreviewed alert is not hidden behind 20 newer reviewed rows. It displays actual timestamps in Asia/Manila, observed failure counts, reliable grouped-source descriptions when applicable, and Needs review/Reviewed status. No device, location or individual IP information is invented.

Mark as reviewed updates only the selected owner's incident. It does not whitelist a source, clear counters, grant privileges or erase audit history. Secure account focuses the existing current-password field when the account form is ready. The existing current-password check, new-password validation, fresh transaction-specific six-digit password-change OTP and subsequent session rules are unchanged. Login and warning messages cannot authorize credential changes.

The UI keeps Bethlehem's `#315b7e`, white surfaces, rounded cards, existing fonts/buttons and Phosphor assets. Loading/error/empty states, keyboard focus and live status feedback use existing patterns. Shared API/sidebar/Settings/CSS URLs were versioned in 39 HTML consumers so cached documents request the matching implementation. There is no new UI library or separate security page; authenticator apps/passkeys remain outside scope.

These are adapted account-review patterns from [Microsoft Recent activity](https://support.microsoft.com/en-us/accounts-billing/security/what-is-the-recent-activity-page), [Google security alerts](https://support.google.com/accounts/answer/2590353), and [GitHub's personal security log](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/reviewing-your-security-log). Grouped attempts use Mark as reviewed, since they cannot reliably be recognized as a single person's event.

## Configuration and local verification

The existing local app was verified as `APP_ENV=local` with a loopback MySQL connection, database cache/queue and its existing SMTP configuration. The two new migrations were applied only there. Read-only checks confirmed all challenge columns, the replacement index, removal of account uniqueness, the incident table, and absence of automatic expiry updates. Effective local OTP limits are 5/5/25/20; the authentication provider pool is 100/day. No private SMTP setting was changed and no worker was started against that transport.

New defaults are documented in `.env.example`:

```dotenv
AUTH_OTP_ATTEMPTS=5
AUTH_OTP_SOURCE_ATTEMPTS=5
AUTH_OTP_IP_ATTEMPTS=25
AUTH_OTP_ACCOUNT_ATTEMPTS=20
AUTH_OTP_ATTEMPT_WINDOW=900
AUTH_LOGIN_OUTSTANDING_CHALLENGES=10
AUTH_SECURITY_FAILURE_THRESHOLD=3
AUTH_SECURITY_SOURCE_THRESHOLD=3
AUTH_SECURITY_SOURCE_WINDOW=1800
AUTH_SECURITY_INCOMPLETE_SECONDS=120
AUTH_SECURITY_RETENTION_DAYS=90
AUTH_EMAIL_PROVIDER_DAILY_LIMIT=100
AUTH_EMAIL_LOGIN_LIMIT=3
AUTH_EMAIL_LOGIN_SOURCE_LIMIT=15
AUTH_EMAIL_REGISTRATION_LIMIT=3
AUTH_EMAIL_RECOVERY_LIMIT=3
AUTH_EMAIL_PRIVILEGED_LIMIT=3
AUTH_TRUSTED_PROXIES=
```

Existing explicit environment values override defaults. In particular, an older `AUTH_OTP_ACCOUNT_ATTEMPTS=5` must be deliberately updated to 20 when adopting the proposed policy. An empty proxy list trusts no forwarding source; wildcard entries are excluded. Only configure verified proxy IPs/CIDRs. Configuration is read through Laravel configuration, including when cached.

Destination limits remain three per 15 minutes, six/hour and 12/day. IP limits remain 30/hour and 100/day. Purpose cooldowns default to 60 seconds. Login additionally has a 15-per-source/15-minute purpose budget. Post-change informational notices have a separate purpose with no cooldown, still bounded by aggregate limits, so a just-completed credential OTP does not suppress its confirmation solely through a purpose cooldown. Unusual-activity warnings have their own one/day purpose budget.

The 100/day provider setting is a conservative **application authentication pool**, not a verified Brevo plan limit. For a future deployment, allocate it from the actual provider allowance after accounting for other application mail and headroom. Public admission can reserve capacity for work that ultimately sends no message. Purpose separation never guarantees recovery or credential email when aggregate capacity is exhausted; mail outside this policy can still consume the provider's actual allowance.

Automated tests force isolated SQLite, array mail/fake notifications and testing configuration; database-queue cases exercise actual encrypted insertion without SMTP. Real MySQL integration uses the existing guarded runner, which creates and removes only its randomly named loopback test database.

For owner-controlled manual local testing, use a local SMTP capture transport if real inbox sends are not desired. Example settings for an existing capture server (the tool/server must actually be running):

```dotenv
APP_ENV=local
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
AUTH_MAIL_DELIVERY=queued
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Clear only local configuration cache and restart the local server/worker normally after intentionally changing its environment. Use the existing queue worker and scheduler with the capture transport. Array transport is suitable for automated assertions, but does not provide a manual inbox. A logging transport, including a nested logging failover, remains forbidden for authentication secrets. Do not dump notification payloads or OTPs into logs, and do not flush shared rate-limit cache to bypass cooldowns.

## Executed checks

Baseline: **256 PHP tests, 3,571 assertions passed** before editing. New regressions were observed failing for replacement, resume, purpose isolation, incident persistence, transient 429 handling, private cache directives and the legacy MySQL timestamp behavior before their fixes.

Final results: **472 PHP tests / 6,958 assertions passed** (SQLite/testing configuration, 57.943 seconds); **31 MySQL integration cases / 503 assertions passed** (owned disposable local database, 2 minutes 25.951 seconds). All **nine Node regression scripts**, the **production build**, changed-PHP syntax checks and `git diff --check` passed. There are no remaining failures in these checks. Warning delivery was also exercised through an actual encrypted database job and the array mail transport, with no authentication material in the warning.

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $clinicPhp vendor/phpunit/phpunit/phpunit --filter 'AuthenticationEmailPolicyTest|EmailVerificationTest|PasswordResetTest|AdminCredentialChangeTest|AdminStaffAccountManagementTest|IndependentAuthenticationSessionsTest|PrivilegedCredentialSecurityTest|SessionLockTest|TestingEnvironmentTest|AdminSecuritySettingsInterfaceTest|PasswordResetInterfaceTest|StaffSettingsInterfaceTest|ActivityLog'
& $clinicPhp tests/run-staff-email-mysql.php 'test_login_challenge_forward_migration|active_staff_verify|overlapping_email|email_change_migration|completed_email_change|admin_recovery_preserves|overlapping_recovery|recovery_durable_queue|recovery_migration'
node tests/frontend-auth-session-regression.cjs
node tests/email-delivery-regression.cjs
node tests/admin-security-settings-regression.cjs
node tests/customer-signup-verification-regression.cjs
node tests/session-inactivity-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/admin-navigation-performance-regression.cjs
node tests/admin-activity-log-regression.cjs
node tests/staff-email-recovery-regression.cjs
npm run build
git diff --check
```

All changed PHP files were also syntax checked with the explicit PHP 8.4 executable. Existing tests were updated in place for the forward schema, configured stricter account budget, actual elapsed cooldowns, and generic public recovery responses; their code validity, replay, queue rollback and credential/session assertions were retained. The existing overlap harness now tests both registration and login consumption using separate processes. MySQL cases verify the forward migration and actual related row-lock races. The unrelated full repository PHP suite was not run.

The build's only warning was an outdated Browserslist/caniuse-lite dataset. The navigation harness also reports Node's existing experimental VM-module warning. No dependency update was introduced for either warning.

## Performance and remaining owner checks

Queries and cleanup are bounded and indexed. Login adds an outstanding-challenge count and durable receipt storage; privileged authentication adds bounded source tracking and an indexed private-alert lookup after full OTP proof. There is no extra customer-page fetch, synchronous SMTP addition, per-row query loop, new polling loop, external risk API, worker or paid service. Shared mail locks cover durable queue insertion and fail fast under contention; provider-wide reservation necessarily serializes that short section.

Existing frontend cache/navigation regression suites passed. Security activity is fetched only on demand, once per Settings instance. This establishes request reuse and functional behavior, not production latency or Core Web Vitals. Production LCP/INP/CLS, provider latency and high-volume load were not measured.

The owner still needs to navigate and test the current admin/staff UI when those roles are available: review focus/status, mobile/keyboard layout, current/new password input, fresh credential-change OTP, sign-in/resend/expiration feedback, remember-me restoration and the existing redirects. Only the public page's visual baseline was inspected in the single browser tab. No admin UI screenshot or manual workflow pass is claimed.

## Future Hostinger deployment — documentation only

No commands in this section were executed against Hostinger.

1. Stage and back up the actual database/private environment first. Verify the real web, SSH, worker and cron PHP versions/extensions. Application code remains compatible with the repository's PHP `^8.2` requirement; local tests use PHP 8.4. Check Composer's real platform requirements rather than faking a version.
2. Install the exact locked Composer dependencies with `composer install --no-dev --optimize-autoloader` under the verified PHP runtime; do not run `composer update` on the host. The implementation uses the installed Laravel transaction commit/rollback callbacks. Review the new environment defaults, shared persistent cache/limiter connection, database queue on the issuance database, retained `APP_KEY`, existing queue names, verified sender, HTTPS URLs and secure cookies. Do not blindly overwrite the private environment.
3. Deploy matching backend, versioned HTML/JavaScript and rebuilt CSS assets together during the chosen maintenance window. Run the forward migrations once on that host, then rebuild configuration and restart the existing worker:

   ```sh
   php artisan migrate --force
   php artisan config:cache
   php artisan queue:restart
   php artisan schedule:list
   ```

   The migrations preserve challenge rows, add a user/expiry index before removing the foreign key's supporting unique index, conditionally repair old automatic-expiry updates, and introduce indexed private incidents. MySQL DDL is not generally transactional; the challenge migration tolerates its individual schema steps having already completed. These migrations are forward-only. Do not restore account uniqueness or drop incident history during rollback; an older overwrite-based login service is not a safe implicit code rollback.
4. Reuse the existing minute-based `schedule:run` cron. The existing `login-email-challenges:prune` entry now runs every five minutes and also prunes incidents. Verify the actual scheduler/worker PHP path and queue name; do not add overlapping cron jobs or clear abuse counters as a deployment shortcut.
5. Verify schema/indexes, non-cacheable auth responses, proxy-derived source addresses, worker backlog/delivery statuses and provider acceptance using controlled accounts. Repeat independent-challenge, source/global restriction, owner privacy, warning-deduplication, credential OTP and remember-me checks after rollout. Local MySQL tests do not establish the live host's proxy chain, provider quota, inbox delivery or edge configuration.

## Future edge/DDoS tasks — manual, not configured

Application throttles reduce authentication abuse and account-lockout attacks; they do not stop infrastructure-level DDoS. OWASP specifically calls for balancing account lockout against targeted denial of service. See the [OWASP Authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

For the owner's later hosting review:

- Inspect Hostinger's actual Anti-DDoS events and available plan protections. Choose one CDN path. Hostinger documents that [stacking Hostinger CDN with Cloudflare is unsupported](https://www.hostinger.com/support/hostinger-cdn-vs-cloudflare/); both were left untouched here.
- Scope edge controls to the authentication POST paths: `/api/sign-in`, `/api/email/login/confirm`, `/api/email/login/resend`, `/api/register`, `/api/email/resend`, `/api/password/forgot`, `/api/password/code/verify`, `/api/password/reset`, and the staff setup/recovery endpoints. Treat authenticated credential-change endpoints separately from ordinary clinic operations and read endpoints.
- Start by examining normal endpoint/source bursts, including multiple staff sharing clinic Wi-Fi. Choose plan-supported rate-limit/block actions above legitimate observed traffic and tune from logs. Application wrong-code budgets are not suitable raw IP-wide request limits. Available Cloudflare rate-limit features depend on the actual plan; [Cloudflare's best-practice guidance](https://developers.cloudflare.com/waf/rate-limiting-rules/best-practices/) describes this tuning.
- Keep authentication/session/security APIs out of shared response caches. Do not put browser-interactive challenges on JSON fetch endpoints: [Cloudflare documents that full-page challenges interrupt AJAX/XHR](https://developers.cloudflare.com/cloudflare-challenges/challenge-types/challenge-pages/). Do not add a new challenge/provider without a separate product requirement.
- Verify the actual origin/proxy chain before filling `AUTH_TRUSTED_PROXIES`. Confirm that direct-origin clients cannot spoof forwarded IPs and that the selected proxy overwrites forwarding headers appropriately. Retain an origin access policy where the plan supports it; do not trust arbitrary `X-Forwarded-For` or a familiar IP as a trusted device. See [Laravel trusted-proxy configuration](https://laravel.com/docs/12.x/requests#configuring-trusted-proxies).

The account-wide safety budget can still temporarily affect legitimate users during a distributed attack, and shared mail ceilings can still delay recovery. NAT users may share source restrictions. Security detection signals possible misuse, not proven compromise; it never disables an account, resets a password, revokes an active session or bypasses verification. No claim of DDoS immunity or impossible lockouts is made.

## File inventory

New production files are limited to the private incident model/service/notification, trusted-proxy configuration and two forward migrations. The plan and this report are documentation. The generated CSS change supplies the added interface states; existing HTML changes outside Settings only update shared asset versions.

Modified existing files:

- `.env.example`
- `app/Http/Controllers/AdminSecurityController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/LoginEmailChallengeController.php`
- `app/Http/Controllers/PasswordResetController.php`
- `app/Http/Controllers/StaffEmailChangeController.php`
- `app/Http/Controllers/StaffEmailRecoveryController.php`
- `app/Http/Middleware/UseAccountSessionCookie.php`
- `app/Jobs/Middleware/TrackAuthenticationMailDelivery.php`
- `app/Models/LoginEmailChallenge.php`
- `app/Notifications/PasswordChangedNotification.php`
- `app/Notifications/StaffEmailChangedNotification.php`
- `app/Notifications/StaffEmailRecoveryNotice.php`
- `app/Services/ActivityRecorder.php`
- `app/Services/AuthenticationEmailPolicy.php`
- `app/Services/AuthenticationMailDelivery.php`
- `app/Services/LoginEmailChallengeService.php`
- `app/Services/PasswordResetService.php`
- `app/Services/PendingStaffAccountService.php`
- `app/Services/PrivilegedCredentialChangeService.php`
- `app/Services/StaffEmailChangeService.php`
- `app/Services/StaffEmailRecoveryService.php`
- `app/Support/ActivityPresentation.php`
- `bootstrap/app.php`
- `config/authentication.php`
- `css/output.css`
- `docs/customer-authentication.md`
- `docs/hostinger-email-auth.md`
- `docs/local-auth-email-troubleshooting.md`
- `index.html`
- `pages/admin/appointments.html`
- `pages/admin/archive.html`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `pages/admin/dashboard.html`
- `pages/admin/inventory/inventory.html`
- `pages/admin/notifications.html`
- `pages/admin/reports.html`
- `pages/admin/services.html`
- `pages/admin/settings.html`
- `pages/admin/transactions.html`
- `pages/admin/walk-in-booking-confirmed.html`
- `pages/admin/walk-in-booking-review.html`
- `pages/admin/walk-in-booking.html`
- `pages/admin/walk-in-clinic-confirmed.html`
- `pages/admin/walk-in-consent.html`
- `pages/admin/walk-in-grooming-services.html`
- `pages/admin/walk-in-pet-details.html`
- `pages/client/booking-pet-details.html`
- `pages/client/clinic-visit-confirmed.html`
- `pages/client/clinic-visit-reason.html`
- `pages/client/clinic-visit-summary.html`
- `pages/client/confirm-email-recovery.html`
- `pages/client/dashboard.html`
- `pages/client/forgot-password.html`
- `pages/client/grooming-history.html`
- `pages/client/grooming-pre-registration.html`
- `pages/client/my-pets.html`
- `pages/client/notifications.html`
- `pages/client/pet-details.html`
- `pages/client/pre-register.html`
- `pages/client/set-up-password.html`
- `pages/client/settings.html`
- `pages/client/sign-in.html`
- `pages/client/signup.html`
- `pages/client/verify-email.html`
- `resources/views/admin/activity-log.html`
- `resources/views/admin/availability.html`
- `routes/api.php`
- `routes/console.php`
- `scripts/api.js`
- `scripts/components/admin-settings.js`
- `scripts/components/admin-sidebar.js`
- `tests/Feature/AdminCredentialChangeTest.php`
- `tests/Feature/AdminStaffAccountManagementTest.php`
- `tests/Feature/EmailVerificationTest.php`
- `tests/Feature/IndependentAuthenticationSessionsTest.php`
- `tests/Feature/PasswordResetTest.php`
- `tests/Unit/AuthenticationEmailPolicyTest.php`
- `tests/admin-security-settings-regression.cjs`
- `tests/admin-sidebar-cache-regression.cjs`
- `tests/frontend-auth-session-regression.cjs`

New files:

- `app/Models/AccountSecurityIncident.php`
- `app/Notifications/UnusualSignInActivityNotification.php`
- `app/Services/AccountSecurityService.php`
- `config/trustedproxy.php`
- `database/migrations/2026_10_30_000001_isolate_login_email_challenges.php`
- `database/migrations/2026_10_30_000002_create_account_security_incidents.php`
- `docs/authentication-security-hardening.md`
- `docs/superpowers/plans/2026-10-30-authentication-security.md`
