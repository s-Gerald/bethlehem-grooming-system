# Customer authentication — October 28, 2026

## Current behavior

Signup remains in `pending_customer_registrations`. It queues an encrypted magic-link notification, not a registration OTP. The email links to the configured `FRONTEND_URL` with a 64-character random token in the URL fragment. Only its SHA-256 hash is stored in the database. Loading the page does nothing to the registration; the customer must choose **Verify Email Address** to POST the token. The page captures it in memory and immediately removes query/fragment credentials from history, before loading assets. It uses no third-party resources and declares `no-referrer`.

The original browser is bound through a random, host-only, HttpOnly, SameSite=Lax cookie and a hash on the pending row. Its edit credential alone cannot authorize activation. A trusted-browser confirmation creates the account and issues the first Sanctum session in the same transaction, without a login OTP. The existing HttpOnly account cookie, single-session replacement, lock, inactivity, role and account-state checks remain in use.

A foreign browser receives a ten-minute email-proven review grant. Only then are saved details disclosed. That operation consumes the magic link and revokes the original edit credential and browser binding. Completing the existing registration-review form requires a newly entered password and confirmation; it never activates an attacker-selected pending password by simply clicking the link. Legacy eligible unverified customers use this same review path, preserving their user ID, pets, bookings and history.

The original waiting tab responds to focus/pageshow or a credential-free BroadcastChannel hint. It can restore a session only when `/api/email/registration/session` validates the actual HttpOnly account cookie, customer role, verified email, expected address and existing usable session. A server-recorded acknowledgement controls the verification tab's close-tab wording. Without that acknowledgement, the verified tab offers **Go to Dashboard**. There is no token polling loop or bearer-token cross-tab messaging. Handoff state expires after ten minutes; it cannot create or extend a session.

Subsequent password sign-in still requires a numeric six-digit email OTP. Its HMAC is bound to the private challenge token. Confirmation consumes the challenge atomically with session issuance; concurrent replays cannot create additional sessions. The old `/email/login/complete` endpoint remains an idempotent cleanup compatibility endpoint and cannot authenticate a caller.

## Central defaults

All customer sending policy lives in `config/authentication.php` and `AuthenticationEmailPolicy`, rather than independent frontend timers.

| Control | Default |
| --- | --- |
| Registration magic link | 30 minutes; single use; replacement revokes the prior link |
| Registration review grant | 10 minutes; single use |
| Pending registration retention | Existing configured retention; default 48 hours from creation |
| Sign-in OTP | 10 minutes from initial issuance; resend does not extend the deadline |
| Incorrect OTP attempts | 5/challenge and 5/account per 15 minutes; 25/IP per 15 minutes |
| Minimum email interval | 60 seconds per destination, across relevant auth flows |
| Email requests | Initial request plus 2 further requests in a rolling 15 minutes |
| Destination limits | 6/rolling hour; 12/rolling 24 hours |
| IP sending limits | 30/rolling hour; 100/rolling 24 hours |

Registration, sign-in and password recovery share destination/IP flood protection. Public recovery requests consume the same budget for unknown/ineligible addresses, preventing quota responses from disclosing eligibility. Password-reset code validity, reset grants, eligibility, staff setup and recovery holds retain their separate rules. Existing operator environment values override defaults; explicitly set `LOGIN_CONFIRMATION_TTL_MINUTES=10` when replacing an older 15-minute configuration.

Server responses provide `resend_after`, `retry_after`, `next_eligible_at`, and `resend_limited`. HTTP 429 also includes `Retry-After` and a machine-readable code (`email_cooldown`, `email_rate_limited`, `email_sending`, or `login_code_locked`). Longer sending restrictions never disable entry of an otherwise valid OTP. Rejected/failed resends do not replace the current credential. Destination and IP locks serialize quota reservations through encrypted database-job insertion; job retries do not reserve another email.

## Root causes addressed

- Local logs identify `AccountEmail.php:37` as the source of `Trying to access array offset on null`. The cached config predated `account_email`, so the policy was null before registration could be saved or mail queued. The helper now falls back to the same policy source; validation rules are unchanged. Local config cache was cleared.
- Legacy link initialization automatically POSTed verification, allowing scanners to activate accounts and bypass the review path. Confirmation is now deliberate and legacy accounts require review.
- OTP resend extended `expires_at`; restarting sign-in replaced the challenge-keyed failed-attempt budget. Expiry is absolute and account/IP budgets survive new challenges and resends.
- Short frontend countdowns and independent route throttles described different eligibility times. One shared rolling sending policy now supplies the countdown and longer-restriction state.
- Shared API error handling displayed backend exception messages. Authentication server failures and technical exception text now receive safe messages, while unrelated screens preserve their established safe application errors.

## Compatibility and deployment

No production deployment or hosting configuration was changed. The additive browser-binding migration was applied to the confirmed local MySQL database.

For a Hostinger release, an operator must:

1. Verify the hosting web, CLI, worker and cron PHP versions/extensions against the locked dependencies and the existing README. Automated tests use PHP 8.4; local MySQL migration used the existing PHP CLI. Real Hostinger/MySQL concurrency and delivery have not been verified here.
2. Deploy backend, HTML, JavaScript, generated CSS and `resources/data/account-email-policy.json` together. Run `php artisan migrate --force`, including `2026_10_28_000002_add_registration_browser_binding.php`, before enabling the new registration UI. This adds a nullable hash column and does not rewrite legitimate accounts.
3. Set production `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL` and `FRONTEND_URL`, `SESSION_SECURE_COOKIE=true`, and exact trusted frontend origins. The configured frontend must include any deployment path prefix. Preserve `APP_KEY` so existing encrypted queued work can be decoded. Use `zend.exception_ignore_args=On`; redact verification credentials in proxy/request logging and avoid analytics on verification pages.
4. Keep `QUEUE_CONNECTION=database`, with the same database connection used for issuance, and a shared persistent `CACHE_STORE=database` or `redis`. Ensure cache and cache-lock tables exist when using the database store. Do not clear the application cache during release, which would erase abuse counters. Rebuild configuration with `php artisan config:cache` and restart workers after code/config changes.
5. Preserve the verified Brevo/SMTP sender, credentials and bounded transport timeout. Reject logging transports, including logging failovers. Keep `AUTH_MAIL_DELIVERY=queued` by default and ensure the existing worker/cron processes `AUTH_MAIL_QUEUE` when named. Existing optional `immediate` mode still persists encrypted work before its post-response attempt. No new worker or public queue-processing route is required.
6. Keep `php artisan schedule:run` running each minute. Existing scheduled jobs prune expired pending registrations and login challenges. Rate histories expire automatically; only hashed destinations/IPs and bounded timestamps are retained.
7. Retire old registration codes and old queued registration notifications. They cannot authenticate through the new API. Existing database code columns remain only for safe migration history; the registration-code service, templates, input and handlers were removed. By default pre-cutover links without issuance metadata are rejected. If a deliberate transition is needed, set `REGISTRATION_LEGACY_LINKS_UNTIL` to a fixed near-term ISO timestamp, then remove it and rebuild config after that deadline. Legacy links still require explicit confirmation and password review; they cannot directly issue a session.

SMTP transport acceptance is not proof of inbox delivery. A process crash after SMTP acceptance but before recording `sent` can still cause an ordinary queue retry to deliver twice; exactly-once inbox delivery is not claimed. Queue guards prevent superseded, expired or consumed issuances from being sent.

## Verification and manual acceptance

Final automated results on the local workspace:

- PHP 8.4 / PHPUnit: 1,840 tests, 24,394 assertions; 1,832 passed, 8 MySQL integration cases skipped, no failures or errors. Logs: `storage/logs/magic-link-full.log` and `magic-link-full.xml`. Skips cover six admin-dashboard MySQL fixtures, the grooming MySQL concurrency case, and the phone-verification MySQL timestamp migration case.
- Frontend: all 85 regression scripts passed with `npm test`. The signup and auth/session scripts also passed after the final waiting-state copy/accessibility refinement.
- `npm run build` passed. It emitted the existing outdated Browserslist-data advisory; no dependency update was needed. PHP/JavaScript syntax and Git/new-file whitespace checks passed.
- The local additive MySQL migration is recorded as applied (batch 101). No actual signup, OTP, password-reset or SMTP submission was performed in the localhost browser.

Automated coverage includes pending isolation, scanner GETs, hashed/expired/reused/replaced links, browser binding, ownership review/password replacement, legacy identity retention, email correction, queue rollback/retries/encryption, overlapping token consumption, session replacement, authorization, OTP restart/resend budgets, rolling quotas, input correction, safe errors and cookie-confirmed tab handoff. Existing signup OTP and replay expectations were updated in place.

The user retains all functional browser testing: submit signup, follow the actual delivered link, confirm in the original browser and a different browser/device, close the original tab, test OTP and reset submissions, and check mobile layouts. Refresh after token capture intentionally discards the in-memory secret; it never silently replays verification. A usable registration session can be restored from its HttpOnly cookie, otherwise request a fresh link. If browser storage is disabled, pending verification can still request a link; enable storage before completing review/session setup. The application still requires browser storage for its existing nonsecret session marker and does not silently weaken authentication to bypass that requirement.

Security references: [OWASP token and recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html), [Laravel encrypted queued jobs](https://laravel.com/docs/12.x/queues), and [Laravel database locks](https://api.laravel.com/docs/12.x/Illuminate/Cache/DatabaseLock.html). This work does not claim a Philippine Data Privacy Act compliance assessment; existing retention and customer-data authorization are preserved.
