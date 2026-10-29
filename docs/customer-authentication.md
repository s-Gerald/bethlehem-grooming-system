# Customer authentication — October 29, 2026

> Updated October 30 authentication-security limits, independent challenges, private activity, new forward migrations and deployment guidance are covered in [authentication-security-hardening.md](authentication-security-hardening.md). Registration and remembered-device behavior below remains applicable.

## Remember me

The unchecked checkbox remains **Remember me**, with the existing sign-in layout and blue controls. This release adds a separate customer device credential; it does not lengthen Sanctum sessions.

| Account / checkbox | Active session | Browser persistence | Device restoration |
| --- | --- | --- | --- |
| Customer / off | 30-minute inactivity; 24-hour absolute | Session cookie; tab-scoped public reference | None after active session expiry |
| Customer / on | Same active session limits | Auth cookie capped at 24 hours; separate device cookie | 14-day absolute deadline from OTP; 7-day device inactivity limit |
| Admin or staff / off | Password lock after 30 minutes; 12-hour absolute | Session cookie; tab-scoped public reference | No customer device capability |
| Admin or staff / on | Same lock and 12-hour limit | Cookie/reference persist to the original deadline | Normal password/email OTP required after expiry |

Browsers may restore session cookies; closing a browser is not authoritative revocation. Backend idle/absolute limits always apply. A new customer tab may recover the public reference to an already valid active cookie without creating a device or extending the session. Recovery never unlocks privileged sessions. Sensitive actions retain existing password/reauthentication requirements.

### Security and concurrency

- The original checkbox value is stored in the OTP challenge and consumed atomically. Only successful OTP confirmation creates a device; password verification and resends cannot create one.
- `remembered_devices` contains account/session bindings, fixed expiration, last activity and revocation. `remembered_device_credentials` contains public random selectors and SHA-256 validator hashes, retaining consumed generations for replay detection. Secrets appear only in HttpOnly cookies and transient request/response memory, never JSON, URLs, logs or browser storage.
- New full authentication replaces the previous account session and revokes its previous devices. Restoration preserves its own device family while issuing one fresh session. User model security changes revoke devices on password/email/role changes, disable/archive/delete, loss of verified email or recovery hold. Existing password reset also deletes active tokens.
- Account row locks serialize OTP, replacement, rotation, logout and account security changes. Concurrent retries within **60 seconds** derive the identical successor and session using keyed HMAC without storing recoverable secrets. A correctly matched consumed credential after that window revokes its family/current session and records a warning. Guessed invalid validators cannot revoke another device. A current credential cannot be distinguished from its legitimate owner solely by possession; the short retry window deliberately accommodates concurrent tabs.
- Valid active sessions are recovered without replacement. Rotation accompanies fresh session establishment. The 14-day deadline never moves. The existing explicit interaction endpoint writes device activity at most hourly during an active session. The exact final interaction is checkpointed before idle/absolute session deletion, including Sanctum expiry and scheduled/manual token pruning. Restoration also reads retained session interaction timestamps. This enforces the seven-day window from actual activity without a write on every interaction. Background reads and polling do not renew device activity. Every restore checks server deadlines and revocation independently of cleanup.
- Logout can revoke a device after the shorter session expires. Credential history binds logout during restoration to the same device successor; an obsolete logout cannot revoke a new full-login device. Both authentication cookies are explicitly expired. Existing replacement messaging remains intact.

Cookies are **HttpOnly, SameSite=Lax, host-only, Secure in production**, and scoped to the configured application URL path plus `/api`. Secure is also enabled by HTTPS requests, an HTTPS application URL or the existing secure-cookie setting. The device cookie expires at its original 14-day deadline; the database enforces the shorter inactivity limit. Public `id|cookie` references still require their bound authentication cookie for ordinary protected requests.

Restoration/logout require `X-Requested-With: XMLHttpRequest`, validate any supplied Origin against the exact CORS allowlist, and reject `Sec-Fetch-Site: cross-site`. Credentialed CORS remains restricted to configured origins. Restore throttling is separate from password/email limits: 60 requests/minute/IP and 15/minute/presented device or active cookie, with an IP fallback when neither exists. Missing, expired, invalid and revoked credentials share the same response without account details. Authentication responses are not cacheable.

### Frontend and caching

Protected customer initialization verifies an existing session, then shares one controlled restoration attempt when needed. Browser Web Locks coordinate cookie-changing transitions across tabs where supported; authoritative backend transactions/idempotent rotation also handle browsers without that API. The restoration response includes session deadlines, avoiding another status request. Ordinary data reads do not each verify authentication; very recent verification is reused. An idle remembered tab conceals private content and pauses restoration until trusted interaction or a return to the tab, so background polling cannot keep renewing the device.

Private content is gated from the initial HTML paint and on BFCache restoration. Presentation caches remain session-scoped. Mixed temporary/persistent account switches clear only obsolete state in the affected tab and preserve the newer browser login. A valid active-session recovery preserves its cache; a fresh session invalidates authentication-bound presentation through the existing initialization, and cannot reuse another session's data. Failed booking/payment mutations are never replayed. Delayed responses cannot overwrite newer authentication or return uncached protected reads for a former session.

Connectivity failures retain usable references and keep private content gated with Retry. They do not clear credentials as proof of invalidity. Explicit logout hides private content and clears references immediately, broadcasts the existing event, and saves a nonsecret pending-revocation marker plus restoration block. Retry/sign-in first attempts outstanding revocation. If expired/replaced cookies make that impossible (401), only a deliberate complete password/OTP sign-in can clear the block; password verification alone cannot. Network/server errors remain retryable and do not count as confirmation. Successful logout leaves restoration blocked until deliberate sign-in. Restricted storage prevents creating an untrackable restored session. **Offline server revocation cannot be guaranteed until a request succeeds**, and failed requests are not reported as confirmed invalidation.

### Hostinger release and rollback

1. Run `php artisan migrate --force` with the site's verified PHP CLI before activating the new PHP code, or use the existing maintenance/release procedure to publish backend, HTML, scripts and imports together. Migration `2026_10_29_000003_create_remembered_devices.php` adds only the two indexed device/history tables with cascading cleanup. Existing account/session/business rows are preserved. Explicit timestamp defaults prevent older MySQL modes from auto-updating the absolute deadline.
2. Keep `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS canonical `APP_URL`, `SESSION_SECURE_COOKIE=true`, and the existing stable `APP_KEY` across processes. Set `FRONTEND_ORIGINS` to exact HTTPS origins without paths/wildcards. Frontend/API must be on the same site; unrelated cross-site cookie restoration is intentionally rejected. Preserve any existing production HTTPS `BETHLEHEM_API_BASE_URL` configuration. Subdirectory deployments must set that existing override to their application API path, because the default frontend API URL is the origin-root `/api`.
3. Keep the existing shared persistent cache/rate-limit store; database is sufficient. No Redis, queue or worker is added for Remember me. Preserve password/email quota counters and cache-lock tables. Rebuild `config:cache` and any existing `route:cache` normally; do not use `cache:clear` or `optimize:clear` as a release shortcut. Publish dated `remember=20261029` entry/import URLs, including server-rendered admin pages. Purge stale HTML/static CDN assets only; never cache authentication/Set-Cookie responses.
4. The existing `schedule:run` cron performs daily indexed device cleanup and cascades credential history. Security does not depend on cron. Keep the current authentication email queue/worker configuration and restart existing long-lived workers normally when releasing PHP code.
5. Verify live HTTPS cookie attributes, routing and CORS; smoke-test OTP/checkbox choices, returning customers, staff locks, logout and device replacement. Verify migration/locking against the site's MySQL/MariaDB version in a disposable staging database. Local tests use PHP 8.4/SQLite, including real overlapping processes; this does **not** certify live MySQL/Hostinger behavior. The Hostinger account tool did not return a result during implementation; no deployed code, hosting configuration or production data was changed.
6. Take the normal release/database backup. For rollback, revoke devices and disable/remove the restoration route before reverting code/static assets; leave additive tables until the older release is stable. Reverting code without revoking credentials could revive them on later redeploy. Roll back this specific migration only after no deployed code/scheduler uses it; do not roll back unrelated migrations. Down removes only device/history tables.

Security references: [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [Sanctum expiration](https://laravel.com/docs/12.x/sanctum#token-expiration), and [Laravel transactions](https://laravel.com/docs/12.x/database#database-transactions).

### Verification for this release

- PHP 8.4.26 / PHPUnit: **446 tests, 6,319 assertions, all passed** across authentication/session, OTP/email policy, password reset/recovery, credential changes, staff management, customer account/name/access/deletion and related page contracts. Includes real overlapping PHP processes, exact seven-day interaction boundaries before/after idle expiry, Sanctum expiry, manual pruning, and retained expired tokens, plus the fixed fourteen-day limit.
- `npm test`: **87/87 frontend regression scripts passed**, including restoration, blocked unattended renewal, safe-read deduplication, mutation non-replay, stale responses, mixed account storage, logout acknowledgement/retry and cache isolation.
- `npm run build` passed. It reported the existing outdated Browserslist dataset advisory; no dependency was changed. PHP/JavaScript syntax checks and `git diff --check` passed. No unrelated failing tests remained in the executed suites.
- Local results: `storage/framework/testing/remember-me-backend.log`, `remember-me-backend.xml`, `remember-me-frontend.log`, and `remember-me-build.log`. Live Hostinger/native MySQL and functional browser acceptance remain release checks as described above.

### Implementation files

Backend changes are limited to authentication/session policy, cookie transport, device lifecycle, revocation and cleanup. Customer entry pages add the existing authorization gate; administrative entry pages and module-only consumers update shared asset URLs/preloads. The sign-in design and checkbox text are unchanged. Existing regression files were updated rather than adding a competing test suite. `LoginEmailChallengeService`, the verification-page confirmation flow and Sanctum configuration were inspected and reused without requiring edits. No dependency or new infrastructure service was added.

Changed files (including this report and the implementation plan):

```text
app/Http/Controllers/AuthController.php
app/Http/Controllers/LoginEmailChallengeController.php
app/Http/Controllers/SessionController.php
app/Http/Middleware/EnsureAccountIsUsable.php
app/Http/Middleware/EnsureSessionIsActive.php
app/Http/Middleware/UseAccountSessionCookie.php
app/Models/RememberedDevice.php
app/Models/User.php
app/Providers/AppServiceProvider.php
app/Services/AccountSessionService.php
app/Services/RememberedDeviceService.php
bootstrap/app.php
database/migrations/2026_10_29_000003_create_remembered_devices.php
docs/customer-authentication.md
docs/superpowers/plans/2026-10-29-remember-me.md
pages/admin/appointments.html
pages/admin/archive.html
pages/admin/clients.html
pages/admin/clinic.html
pages/admin/dashboard.html
pages/admin/inventory/inventory.html
pages/admin/notifications.html
pages/admin/reports.html
pages/admin/services.html
pages/admin/settings.html
pages/admin/transactions.html
pages/admin/walk-in-booking-confirmed.html
pages/admin/walk-in-booking-review.html
pages/admin/walk-in-booking.html
pages/admin/walk-in-clinic-confirmed.html
pages/admin/walk-in-consent.html
pages/admin/walk-in-grooming-services.html
pages/admin/walk-in-pet-details.html
pages/client/booking-pet-details.html
pages/client/clinic-visit-confirmed.html
pages/client/clinic-visit-reason.html
pages/client/clinic-visit-summary.html
pages/client/confirm-email-recovery.html
pages/client/dashboard.html
pages/client/forgot-password.html
pages/client/grooming-history.html
pages/client/grooming-pre-registration.html
pages/client/my-pets.html
pages/client/notifications.html
pages/client/pet-details.html
pages/client/pre-register.html
pages/client/set-up-password.html
pages/client/settings.html
pages/client/sign-in.html
pages/client/signup.html
pages/client/verify-email.html
resources/views/admin/activity-log.html
resources/views/admin/availability.html
routes/api.php
routes/console.php
scripts/api.js
scripts/auth/sign-in.js
scripts/components/booking-pet-step.js
scripts/components/client-notifications-page.js
scripts/components/client-settings.js
scripts/components/clinic-visit-confirmed.js
scripts/components/clinic-visit-reason-fields.js
scripts/components/clinic-visit-reason.js
scripts/components/clinic-visit-summary.js
scripts/components/grooming-pre-registration.js
scripts/components/pre-registration-options.js
scripts/components/walk-in-clinic-consent-step.js
scripts/services/clinic-visit-service.js
tests/Feature/EmailVerificationTest.php
tests/Feature/IndependentAuthenticationSessionsTest.php
tests/Feature/PasswordResetTest.php
tests/admin-sidebar-cache-regression.cjs
tests/customer-data-cache-regression.cjs
tests/frontend-auth-session-regression.cjs
tests/session-inactivity-regression.cjs
```

## Current behavior

Public signup and registration resend use **Option 2: uniform public responses**. Every syntactically valid unauthenticated request returns the same HTTP 202 body with `registration_state: requested`, `requires_verification: true`, and “Request received. Email delivery is not confirmed.” The response supplies no account details, edit credential, delivery receipt, or destination-specific quota history. Invalid syntax can still receive field validation errors, independent of account existence. Both new and conflicting signup attempts navigate to the same neutral **Registration requested** card.

Account lookup, identifier collision checks, pending creation and magic-link issuance run in an encrypted `ProcessPublicRegistration` job on the existing database/authentication queue. Public requests perform the same password hashing and quota reservation before queueing, without account queries; duplicate detection therefore does not create an avoidable response-timing shortcut. Stored work contains a password hash, never the plaintext password. Duplicate/conflicting work changes no existing details or sessions and issues no replacement link. A transaction couples pending creation/link issuance to durable encrypted mail insertion; retries do not create duplicate accounts or rotate an already committed link.

Public requests reserve the existing destination/IP budget even when no account can be created. A destination restriction queues no additional work and leaves the current link intact, while retaining the same neutral public response. The public `resend_after` is the configured minimum interval for the UI, not a promise of server eligibility: longer destination restrictions remain enforced without disclosure. Route-level IP throttles remain in force. There is no fake delivery receipt or polling endpoint that can turn this neutral response into an account-existence oracle.

Specific pending status is available only with a pre-existing edit credential and its matching HttpOnly browser binding. A trusted repeat POST reports a resumed registration, no new email, the actual resend restriction and a verified link deadline, without changing the saved profile or sending again. A public first signup intentionally receives no edit credential. Existing browser credentials remain usable only for continuation and authorized resend; all PUT updates now require the email-proven recovery grant. Returning to signup alone neither edits nor resends.

Signup remains in `pending_customer_registrations`. It queues an encrypted magic-link notification, not a registration OTP. The email links to the configured `FRONTEND_URL` with a 64-character random token in the URL fragment. Only its SHA-256 hash is stored in the database. Loading the page does nothing to the registration; the customer must choose **Verify Email Address** to POST the token. The page captures it in memory and immediately removes query/fragment credentials from history, before loading assets. It uses no third-party resources and declares `no-referrer`.

The original browser is bound through a random, host-only, HttpOnly, SameSite=Lax cookie and a hash on the pending row. Its edit credential alone cannot authorize activation. A trusted-browser confirmation creates the account and issues the first Sanctum session in the same transaction, without a login OTP, unless the customer deliberately requested review. The existing HttpOnly account cookie, single-session replacement, lock, inactivity, role and account-state checks remain in use.

Returning signup uses a small **Registration in progress** section when the entered email matches an unexpired browser request context. **Continue verification** opens the existing waiting card without a registration request or resend. **Edit registration details** records review intent at `POST /api/email/registration/review`, then opens that card. This endpoint performs no account lookup, returns the same neutral response for unknown addresses, discloses no details, and cannot edit or activate anything. The intent uses the existing shared cache, scoped to the HttpOnly browser binding and normalized email. It survives opening the original email link in another tab. Queue processing carries intent selected before pending creation through the pending row's actual retention deadline, including delayed work and transaction retries. Old intent timestamps do not apply to a later fresh registration.

The magic-link card also offers **Edit registration details**, which sends `review: true` with the existing verification POST. Either deliberate path consumes the valid single-use link into the existing ten-minute recovery grant, rather than creating the original account. `PUT /api/register/pending` now rejects browser-edit credentials without that grant. The existing signup form becomes **Review your details**, keeps the verified email fixed, requires password reentry and explicit **Complete registration**, and creates the account only after successful shared validation. Customers who do not choose editing keep the normal direct confirmation path.

`signup-context.js` shares non-secret presentation context across the signup, waiting and confirmation scripts, using local storage with a session fallback. Context is bounded to one hour from the browser's request response and is never email proof or a server existence lookup. Proposed name, username and phone changes are allowlisted, bounded, email-matched and marked as proposed when shown after email verification. Passwords and confirmation values never enter drafts. Missing, expired, malformed, mismatched or blocked context leaves ordinary signup/recovery available; the server remains authoritative. The review credential, its server expiration and email-proven details stay in tab-scoped session storage. A different browser can edit saved details after proof without the previous draft. Browsers must permit session storage for that cross-page review; completely blocked storage requires allowing storage and requesting a new link. Local presentation deadlines are conservative and do not extend server retention.

A foreign browser receives a ten-minute email-proven review grant. Only then are saved details disclosed. That operation consumes the magic link and revokes the original edit credential and browser binding. Completing the existing registration-review form requires a newly entered password and confirmation; it never activates an attacker-selected pending password by simply clicking the link. Legacy eligible unverified customers use this same review path, preserving their user ID, pets, bookings and history.

Unverified pending registrations expire exactly **60 minutes after their server `created_at`**, including existing rows at release. Editing, resending, refreshing, leaving the page, closing the browser, switching apps and suspending a phone do not restart or cancel that lifetime. The pending model enforces the hour independently of old configuration caches or hours/days environment overrides. No user row exists until deliberate verification and any required review finish successfully.

Each new link expires at `min(issued_at + 30 minutes, created_at + 60 minutes)`. The configured link duration can shorten the maximum but cannot exceed 30 minutes. Validation, authorized resume guidance and queued delivery also cap older stored issuances by that effective deadline. A resend at minute 45 lasts 15 minutes; one at minute 50 lasts 10. The email gives the exact expiration date/time, including seconds and **Asia/Manila, UTC+08:00**, rather than a relative duration that could overpromise after queue delays. No additional website countdown was added; existing resumed-link guidance uses the server-derived effective expiration.

Issuance and delivery require at least 60 seconds of validity remaining. Rejected near-expiry resends preserve the previous link; authorized callers see a short restart message, while public recovery stays neutral. A correctly matched expired registration link shows “This registration has expired. Start again.” and signup navigation without resend. An expired link for an active registration shows “This link has expired. Request a new one.” with recovery. Once a pending row is physically pruned, an old token cannot identify its former registration; it receives the existing generic invalid-link feedback. Public recovery never discloses whether an address is expired, missing or occupied.

A review grant obtained by proving email ownership before the hour retains its original ten-minute completion window, even across the registration deadline. Public resends cannot revoke or extend an active pending review grant. It cannot extend magic-link validity or permit unverified completion. Cleanup preserves that grant only until its expiry, with an absolute ceiling of `created_at + 70 minutes` for pre-existing or malformed stored grants. Those email-proven details remain reserved during this short window. Other expired pending rows release email, phone and username on the next signup transaction, even when cron is late. Stale public-processing work is discarded one hour after its request timestamp; replay cannot recreate an expired registration.

The original waiting tab responds to focus, back/forward restoration or a credential-free BroadcastChannel hint. There is no speculative initial session check or duplicate initial pageshow check; simultaneous checks are deduplicated. It can restore a session only when `/api/email/registration/session` validates the actual HttpOnly account cookie, customer role, verified email, expected address and existing usable session. A server-recorded acknowledgement controls the verification tab's close-tab wording. Without that acknowledgement, the verified tab offers **Go to Dashboard**. There is no token polling loop or bearer-token cross-tab messaging. Handoff state expires after ten minutes; it cannot create or extend a session.

The centered pending card retains the clinic logo, white surface and existing blue controls. Its only normal navigation actions are **Resend link** and **Back to sign up**. The latter uses the existing edit form only when matching editing context is available. Removed email-change actions and their handlers are gone. Login OTP and emailed-link screens retain their sign-in navigation and **Verify Email Address** action respectively.

Authorized mail receipts distinguish queued, sent, failed, delayed and superseded work. **Check your email** and “We sent a verification link” appear only after `sent` transport acceptance; queue insertion alone shows a sending state. Public requests never receive these receipts. Transport acceptance is not a guarantee of inbox arrival.

Subsequent password sign-in still requires a numeric six-digit email OTP. Its HMAC is bound to the private challenge token. Confirmation consumes the challenge atomically with session issuance; concurrent replays cannot create additional sessions. The old `/email/login/complete` endpoint remains an idempotent cleanup compatibility endpoint and cannot authenticate a caller.

## Central defaults

All customer sending policy lives in `config/authentication.php` and `AuthenticationEmailPolicy`, rather than independent frontend timers.

| Control | Default |
| --- | --- |
| Registration magic link | At most 30 minutes, capped by the original pending deadline; single use |
| Registration review grant | Original 10 minutes after email proof; single use; at most 70 minutes from pending creation |
| Pending registration retention | Exactly 60 minutes from server creation; old hours/days overrides ignored |
| Sign-in OTP | 10 minutes from initial issuance; resend does not extend the deadline |
| Incorrect OTP attempts | 5/challenge; 5/account + source and 20/account per 15 minutes; 25/IP per 15 minutes |
| Minimum email interval | 60 seconds per purpose/destination; separate bounded security-notice and warning purposes |
| Email requests | Initial request plus 2 further requests in a rolling 15 minutes |
| Destination limits | 6/rolling hour; 12/rolling 24 hours |
| IP sending limits | 30/rolling hour; 100/rolling 24 hours |
| Authentication provider pool | 100/rolling day by default; configure against the verified provider allocation |

Registration, sign-in and password recovery share aggregate destination/IP/provider flood protection within purpose-aware budgets. Public password-recovery feedback uses a separate source/address request budget, identical for known and unknown addresses, so private sending history is not disclosed. Password-reset code validity, reset grants, eligibility, staff setup and recovery holds retain their separate rules. Existing operator environment values override defaults; explicitly set `LOGIN_CONFIRMATION_TTL_MINUTES=10` and `AUTH_OTP_ACCOUNT_ATTEMPTS=20` when adopting the updated policy over older explicit values.

Authorized server responses provide `resend_after`, `retry_after`, `next_eligible_at`, and `resend_limited`. Their HTTP 429 also includes `Retry-After` and a machine-readable code (`email_cooldown`, `email_rate_limited`, `email_sending`, or `login_code_locked`). Public signup/resend deliberately withhold destination-specific restrictions as described above. Longer sending restrictions never disable entry of an otherwise valid OTP. Rejected/failed resends do not replace the current credential. Destination and IP locks serialize quota reservations through encrypted database-job insertion; job retries do not reserve another email.

The shared formatter displays `576` seconds as `9m 36s`, `65` as `1m 05s`, `45` as `45s`, and long restrictions as hours/minutes/seconds. Zero ends the visible restriction. Password and OTP lockouts and resend timers use absolute deadlines, retain existing refresh persistence, stop on page exit and resume correctly after back/forward restoration. Failed Sign-In requests restore the **Sign In** label while an actual lockout keeps the button disabled. Updating the shared formatter also required removing obsolete colon-format padding from the existing phone-verification consumer; its behavior and quotas are unchanged.

## Root causes addressed

- Local logs identify `AccountEmail.php:37` as the source of `Trying to access array offset on null`. The cached config predated `account_email`, so the policy was null before registration could be saved or mail queued. The helper now falls back to the same policy source; validation rules are unchanged. Local config cache was cleared.
- Legacy link initialization automatically POSTed verification, allowing scanners to activate accounts and bypass the review path. Confirmation is now deliberate and legacy accounts require review.
- OTP resend extended `expires_at`; restarting sign-in replaced the challenge-keyed failed-attempt budget. Expiry is absolute and account/IP budgets survive new challenges and resends.
- Short frontend countdowns and independent route throttles described different eligibility times. One shared rolling sending policy now supplies the countdown and longer-restriction state.
- Shared API error handling displayed backend exception messages. Authentication server failures and technical exception text now receive safe messages, while unrelated screens preserve their established safe application errors.

## Compatibility and deployment

No production deployment or hosting configuration was changed. This countdown/uniform-response change requires **no new migration or schema change**, and no local application database was migrated during this task. The earlier browser-binding migration remains an existing prerequisite.

For a Hostinger release, an operator must:

1. Verify the hosting web, CLI, worker and cron PHP versions/extensions against the locked dependencies and the existing README. Automated tests use PHP 8.4; local MySQL migration used the existing PHP CLI. Real Hostinger/MySQL concurrency and delivery have not been verified here.
2. Deploy backend, the new encrypted job class, HTML, JavaScript and normal build assets together. This change requires no migration. If an older environment lacks the already-existing browser-binding schema, review that earlier migration separately before releasing this code; do not treat it as a new migration authorized by this task.
3. Set production `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL` and `FRONTEND_URL`, `SESSION_SECURE_COOKIE=true`, and exact trusted frontend origins. The configured frontend must include any deployment path prefix. Preserve `APP_KEY` so existing encrypted queued work can be decoded. Use `zend.exception_ignore_args=On`; redact verification credentials in proxy/request logging and avoid analytics on verification pages.
4. Keep `QUEUE_CONNECTION=database`, with the same database connection used for issuance, and a shared persistent `CACHE_STORE=database` or `redis`. Ensure cache and cache-lock tables exist when using the database store. Do not clear the application cache during release, which would erase abuse counters. Rebuild configuration with `php artisan config:cache` and restart workers after code/config changes.
5. Preserve the verified Brevo/SMTP sender, credentials and bounded transport timeout. Reject logging transports, including logging failovers. Ensure the existing worker/cron processes the database queue and `AUTH_MAIL_QUEUE` when named. Public signup/resend now always depend on this worker, including when `AUTH_MAIL_DELIVERY=immediate`: they have a public-processing stage followed by the mail stage. No post-response SMTP callback is retained by public processing in long-lived workers. Existing immediate behavior for other authorized authentication requests is preserved. Restart workers with `php artisan queue:restart`; no new worker or public processing route is required.
6. Keep one `php artisan schedule:run` cron running each minute. The existing `pending-customer-registrations:prune` job now runs every five minutes with `withoutOverlapping()`. It uses the existing `pending_registration_created_idx`; no migration or extra index is needed. The indexed DELETE takes the same row locks as verification, preserves active email-proven review grants and never touches `users`. Server deadlines work even when cron is late. Rate histories expire automatically; only hashed destinations/IPs and bounded timestamps are retained.
7. Retire old registration codes and old queued registration notifications. They cannot authenticate through the new API. Existing database code columns remain only for safe migration history; the registration-code service, templates, input and handlers were removed. By default pre-cutover links without issuance metadata are rejected. If a deliberate transition is needed, set `REGISTRATION_LEGACY_LINKS_UNTIL` to a fixed near-term ISO timestamp, then remove it and rebuild config after that deadline. Legacy links still require explicit confirmation and password review; they cannot directly issue a session.
8. Remove obsolete `PENDING_REGISTRATION_RETENTION_HOURS` and `PENDING_REGISTRATION_RETENTION_DAYS` environment entries. Retention is fixed at one hour; `config/app.php` reports `1`, and the pending model enforces it even with a stale cached value. Keep `REGISTRATION_MAGIC_LINK_MINUTES=30` for the maximum. Run `php artisan config:cache`, then `php artisan queue:restart`; do not flush the shared cache or reset abuse counters. Deploy `verify-email.html` with its versioned scripts. No migration or production-data operation is required for that pending-registration retention correction. Existing pending records use their original creation timestamp and are never granted a fresh hour at deployment.

SMTP transport acceptance is not proof of inbox delivery. A process crash after SMTP acceptance but before recording `sent` can still cause an ordinary queue retry to deliver twice; exactly-once inbox delivery is not claimed. Queue guards prevent superseded, expired or consumed issuances from being sent.

The signup and verification entry pages now include the shared `scripts/auth/client/signup-context.js` and dated cache keys for their changed scripts. These earlier signup/email changes need no additional infrastructure; the Remember me release above additionally requires its new migration. Deploy these pages, scripts and Laravel changes together; refresh an existing route cache through the normal deployment process. Keep the existing shared persistent cache and HTTPS/cookie configuration. A queued registration worker must be restarted through the normal release process to load the updated service. Affected auth and shared phone-verification import URLs have dated cache keys. Publish their entry pages and scripts together so browsers do not mix module versions. Preserve shared cache/rate-limit data; do not flush abuse counters or unrelated production caches. A cron-only worker can add its existing scheduling delay before the first link is sent. Live Hostinger latency, MySQL concurrency and actual SMTP delivery still require operator verification; uniform public bodies and moving account-dependent work out of the request are not a claim of mathematically constant network response times.

## Verification and manual acceptance

October 29 returning-registration update: the existing PHP and frontend test files were updated in place. Coverage includes deliberate original-browser review, pre-worker review intent, no private lookup on intent, unchanged original links, rejected pre-proof edits, newly confirmed account details/password, grant consumption, stale/mismatched drafts, secret filtering, blocked storage, duplicate clicks and expiry. Final focused validation: 262 PHP tests passed with 2,903 assertions; the signup/verification, frontend auth/session, email-delivery, email-entry and human-name regression scripts passed. `npm run build`, changed PHP/JavaScript syntax checks and whitespace checks passed. The build emitted only the existing outdated Browserslist-data advisory. The existing signup page was inspected read-only in the ChatGPT browser; new continuation/review states are covered by automated harnesses. Manual navigation, real signup input, OTP submission, resets, live SMTP and production MySQL concurrency remain operator checks.


October 29 validation of the one-hour change:

- PHP 8.4: 258 focused authentication, password-reset, independent-session, session-lock, name-update and shared email-policy tests passed, with 2,769 assertions. Existing signup tests were updated in place; coverage includes exact expiry, 45/50-minute resends, delayed mail timestamps, queue replay, bounded review grants, old cached retention, identifier reuse and overlapping verification requests. An isolated SQLite `EXPLAIN QUERY PLAN` of the actual cleanup DELETE used `pending_registration_created_idx` for both the expiry range and null-creation branches, with no table scan.
- Shared email availability consumers: 156 staff-account/credential-change tests passed (2,341 assertions), plus six privileged credential-security tests. Their fixtures now load the already-existing registration-recovery migration; email reservation tests cover both the exact one-hour release and an active review grant. Across these focused PHP runs, 420 distinct tests passed; the full unrelated application suite was not rerun.
- The signup/verification, frontend auth/session, email-delivery and shared email regression scripts passed. `npm run build` passed with the existing Browserslist-data advisory. No new dependency or migration was introduced.
- The current signup page was inspected read-only in the ChatGPT browser at 320, 375, 414 and 768 pixels; no horizontal overflow was observed. Its established card, colors, typography and icons were preserved. Browser navigation, data entry, OTP and password-reset testing remain with the user. Waiting/error states were verified with automated frontend harnesses, without navigating the localhost browser.
- Hostinger cron/worker configuration, actual SMTP delivery and native production MySQL query plans/concurrency remain operator checks; no production data or hosting settings were changed.

Historical October 28 automated results (before the one-hour change):

- PHP 8.4 / PHPUnit: 1,845 tests, 24,466 assertions; 1,837 passed, 8 MySQL integration cases skipped, no failures or errors. Logs: `storage/logs/auth-feedback-full-phpunit.txt` and `auth-feedback-full-phpunit.xml`. Skips cover six admin-dashboard MySQL fixtures, the grooming MySQL concurrency case, and the phone-verification MySQL timestamp migration case. The final focused authentication suite also passed: 111 tests, 1,171 assertions, after ensuring fixtures retain original credentials rather than restoring revoked ones.
- Frontend: all 85 regression scripts passed with `npm test`. After the final cache-key update, signup/verification, auth/session, dashboard/phone verification and online-registration selector regressions also passed.
- `npm run build` passed. It emitted the existing outdated Browserslist-data advisory; no dependency update was needed. PHP/JavaScript syntax and Git/new-file whitespace checks passed.
- No schema changes or local application migrations were performed for this change. The existing pending recovery card was inspected visually in the ChatGPT browser. No actual signup, OTP, password-reset or SMTP submission was performed in that browser.

Automated coverage includes uniform public content/status, absence of account lookups before public work is queued, encrypted public jobs without plaintext passwords, replay safety, pending isolation, scanner GETs, hashed/expired/reused/replaced links, browser binding, ownership review/password replacement, legacy identity retention, email correction, queue rollback/retries/encryption, overlapping token consumption, session replacement, authorization, OTP restart/resend budgets, rolling quotas, accurate delivery headings, countdown expiry/back-forward cleanup, input correction, safe errors and cookie-confirmed tab handoff. Existing tests were updated in place for the neutral public contract, compact durations and removed navigation. The first broad frontend run caught obsolete phone countdown padding; its existing regression now verifies `42s` and preserves request-count/session assertions.

The user retains all functional browser testing: submit signup, follow the actual delivered link, confirm in the original browser and a different browser/device, close the original tab, test OTP and reset submissions, and check mobile layouts. Refresh after token capture intentionally discards the in-memory secret; it never silently replays verification. A usable registration session can be restored from its HttpOnly cookie, otherwise request a fresh link. If browser storage is disabled, pending verification can still request a link; enable storage before completing review/session setup. The application still requires browser storage for its existing nonsecret session marker and does not silently weaken authentication to bypass that requirement.

Security references: [OWASP authentication responses and enumeration guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [OWASP token and recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html), [Laravel encrypted queued jobs](https://laravel.com/docs/12.x/queues), and [Laravel database locks](https://api.laravel.com/docs/12.x/Illuminate/Cache/DatabaseLock.html). This work does not claim a Philippine Data Privacy Act compliance assessment; existing retention and customer-data authorization are preserved.
