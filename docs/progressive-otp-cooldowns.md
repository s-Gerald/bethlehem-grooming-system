# Progressive OTP cooldowns — implementation report

Implemented locally on `gerald-frontend` on October 30, 2026. The starting working tree was clean. No deployment, production data changes, environment changes, or migrations were performed.

## Original behavior and root causes

The independent login challenge already had a ten-minute `expires_at`, a database failure count, atomic consumption, and queued mail with issuance guards. Five wrong codes also exhausted an account/source rate-limiter window for 900 seconds. A second counter keyed to the transaction duplicated the issued-code allowance. Resend and password-confirmed resume both rejected an exhausted challenge. The browser API discarded its pending token on `login_code_locked`, preventing in-page recovery. The verification page re-enabled the exhausted input when its timer ended, and Sign In treated that same server restriction as a plain error without a timer.

## Implemented policy

Every five counted incorrect codes for an account and trusted server-observed source starts a 60-, 180-, then 300-second cooldown. Further rounds stay at 300 seconds. The progression is configured by `authentication.otp_source_cooldowns`; round size remains `authentication.otp_source_attempts`. Source history stores only a partial-round count and capped escalation level, under a distinct expiring `:history` cache key. The active cooldown uses Laravel's rate limiter separately.

History expires 1800 seconds after the last counted failure, configurable through `AUTH_OTP_SOURCE_RESET_SECONDS`. Successful full authentication clears the current source history and the existing account failure budget; it preserves the IP budget and other sources' histories. Password confirmation alone does not clear OTP history. Reads, blocked requests, refreshes, navigation, and resends do not count failures, extend cooldowns, or refresh history retention.

The independent account limit remains 20 incorrect codes per 900-second window and the IP limit remains 25 per 900-second window. Responses use the longest applicable remaining restriction. Password throttling, outstanding challenge limits, suspicious activity incidents, session cookies, and remembered devices retain their existing behavior.

## Issued-code exhaustion and replacement

`login_email_challenges.failed_attempts` is the authoritative allowance for the current issued code. Five genuine mismatches permanently exhaust that issuance. It cannot authenticate when the source cooldown ends. The obsolete second issued-code counter and its queued-mail check were removed; unknown-token flood protection remains separate.

After cooldown, the existing Resend Code action can atomically replace the code while the password-confirmed transaction is valid. Replacement uses a different cryptographically generated six-digit code, resets only the issued-code count to zero, and retains cumulative guessing history. Account/challenge row locks, the verification IP lock, existing mail quota locks, and queue insertion rollback remain in use. A rejected replacement cannot resurrect the exhausted code or report successful issuance.

No replacement changes `expires_at`. Sign In and resend responses expose `expires_in`; password-confirmed resume also exposes `requires_new_code`. An expired transaction returns `login_transaction_expired`, HTTP 422, `expired: true`, and “Sign-in expired. Please sign in again.” Expiry is checked before resend quota handling and again under row locks.

Active guessing restrictions retain `login_code_locked`, HTTP 429, numeric `retry_after`, `Retry-After`, and no-store headers. An exhausted code without an active restriction returns `login_code_exhausted`, HTTP 422, and `requires_new_code: true`. Busy responses, email quotas, password cooldowns, and outstanding challenge restrictions remain distinct.

## UI behavior

The existing feedback area is the only visible incorrect-code countdown: “Too many incorrect codes. Request a new code in 0m 42s.” During that state, input, verification, and resend are disabled. Resend Code stays visible with the existing Phosphor `lock-key` sprite immediately beside the label. The normal resend countdown is hidden and emptied. The countdown uses `role="timer"` and `aria-live="off"`; resend is associated with its feedback through `aria-describedby`. Back to Sign In remains available.

When cooldown ends, its message and lock disappear. Resend becomes eligible subject to the original email restrictions, while exhausted input remains disabled. Timer completion sends no request. Successful replacement clears the old digits and feedback, restores input focus, and enables verification only with six digits. Quota or transport failures do not enable an exhausted input. Transaction expiry disables recovery and displays the existing error state.

Pending transaction lifetime and exhaustion are retained across refresh for display only. The server independently authorizes every verification and resend. No new polling is added; the local display uses one interval, cleaned up on navigation and restarted on BFCache restoration. Existing email delivery tracking remains unchanged.

Sign In now recognizes `login_code_locked` and uses its existing message container and monotonic countdown utility to show “Too many verification attempts. Try again in 40s.” The duration comes from the new server response after password confirmation. Returning after 20 seconds of a 60-second restriction does not restart it. Countdown completion does not submit Sign In or send mail.

The Bethlehem layout, six cells, signature blue, typography, spacing, controls, and existing responsive styles are retained. The only added visual is the small lock. Changed auth assets have `otp=progressive-20261030` cache-busting on both entry pages.

## Files changed

- `app/Services/LoginEmailChallengeService.php`: source history/cooldown policy, bounded history reset, recoverable replacement, unchanged deadline, shared response metadata.
- `app/Http/Controllers/LoginEmailChallengeController.php`: durable issued-code allowance, counted-failure recording, exhausted/expired responses, successful-authentication reset.
- `app/Http/Controllers/AuthController.php`: returns transaction remainder and exhausted state on issuance/resume.
- `app/Jobs/Middleware/TrackAuthenticationMailDelivery.php`: removes obsolete duplicate issued-code limiter check; durable failure/hash/expiry guards remain.
- `config/authentication.php`, `.env.example`: source cooldown configuration and 1800-second inactivity reset setting.
- `scripts/api.js`: retains pending verification on temporary restrictions, stores server transaction metadata, clears expired/disabled transactions.
- `scripts/auth/client/verify-email.js`: single countdown/lock, exhausted state, deliberate resend recovery, expiry and lifecycle handling.
- `scripts/auth/sign-in.js`: distinct OTP remaining-time countdown.
- `scripts/auth/verification-code.js`: optional minutes display while retaining existing formatting for other consumers.
- `pages/client/verify-email.html`, `pages/client/sign-in.html`, `css/components/verification-code.css`: lock markup, small icon style, accessible association and asset versions.
- `tests/Feature/EmailVerificationTest.php`: updated obsolete resend allowance expectations; added progression, cap/reset, remaining-time, fixed-expiry, source/account/IP and failed-replacement coverage.
- `tests/Feature/IndependentAuthenticationSessionsTest.php`: extends existing two-process overlap coverage for the fifth failure racing the correct old code and for simultaneous resends.
- `tests/frontend-auth-session-regression.cjs`: replaces obsolete post-cooldown re-enable/no-Sign-In-countdown assertions and covers recovery, lock, single message, quotas, refresh, expiry, busy responses, and pending API state.
- `docs/superpowers/plans/2026-10-30-progressive-otp-cooldowns.md` and this report.

`AuthenticationEmailPolicy.php` was inspected and reused without modification; its existing quota regression suite was run.

## Actual verification

The first four focused PHP regressions failed against the previous implementation (900-second cooldown, missing deadline metadata, and replacement retaining the issued-code count), then passed after implementation. The frontend regression first failed because a temporary OTP restriction deleted the pending transaction, then passed after correction. Important existing expectations were preserved or replaced with the requested observable behavior.

The documented PHP 8.4 interpreter was used. Default XAMPP PHP is 8.2; the initial sandboxed invocation could not read PHPUnit's bootstrap via PHP's readability check. Runs below succeeded outside that Windows sandbox, using the repository's forced in-memory SQLite/test cache and fake or array mail delivery. No real SMS or email was required.

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $clinicPhp vendor/phpunit/phpunit/phpunit tests/Feature/EmailVerificationTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/PrivilegedCredentialSecurityTest.php
# OK: 245 tests, 3656 assertions; 26.836 seconds.

& $clinicPhp vendor/phpunit/phpunit/phpunit tests/Unit/AuthenticationEmailPolicyTest.php
# OK: 8 tests, 44 assertions.

node tests/frontend-auth-session-regression.cjs
# frontend auth/session regression checks passed

node tests/customer-signup-verification-regression.cjs
# Customer magic-link waiting, signup validation and recovery regression checks passed

node --check scripts/api.js
node --check scripts/auth/sign-in.js
node --check scripts/auth/verification-code.js
node --check scripts/auth/client/verify-email.js
git -c core.safecrlf=false diff --check
# All exited 0.
```

The PHP set covers replay rejection, durable queue guards and rollback, remembered devices, authenticated sessions, suspicious activity, password throttling, distributed account/IP budgets, and real overlapping processes. Email policy tests cover destination, source, provider, hourly/daily/rolling quotas and reservation rollback. Node tests execute the real scripts with controlled DOM/API/time fixtures.

## Localhost verification steps

1. Start the existing local frontend, Laravel runtime, and configured database queue worker using the project's documented PHP interpreter. Use an existing local test account and test inbox.
2. Sign in with the correct password. Confirm the transaction starts with a ten-minute deadline. Enter five incorrect six-digit codes.
3. Confirm one 1m 00s feedback countdown, disabled input/verification/resend, a small lock beside Resend Code, and an accessible Back to Sign In link. Check keyboard navigation and a narrow mobile viewport.
4. Return to Sign In after about 20 seconds and submit the same correct credentials. Confirm approximately 40 seconds remain, with no new email. The restriction does not restart.
5. Resume the same pending transaction after cooldown. Confirm no automatic email, disabled exhausted input, and available Resend Code when email eligibility allows.
6. Click Resend Code. Confirm old digits clear only on successful issuance, the new code is different, and input is enabled. Check the old code is rejected. Repeating five counted failures produces the next 3-minute cooldown, then 5 minutes after another replacement round.
7. Confirm a quota rejection shows the existing quota feedback and keeps exhausted input disabled. Default email quotas permit three emails per 15 minutes; they may prevent a fourth email before this ten-minute transaction ends. This is intentional.
8. Refresh during cooldown and after it ends. Confirm the displayed remaining time and disabled exhausted input remain coherent. No automatic resend should occur.
9. Let the original deadline pass, including after a late resend. Confirm “Sign-in expired. Please sign in again.” and no usable resend action. Complete a fresh password-confirmed sign-in to continue.

## Limitations and future deployment

Automated behavior checks passed. Rendered browser visual QA could not finish: the in-app browser timed out reaching localhost, and Windows computer use stopped because it could not confidently determine the browser URL. The temporary mocked preview server was stopped. Desktop/mobile rendering, assistive-technology behavior, actual MySQL concurrency, and live Brevo delivery still need environment-level verification; no claim is made that those checks ran.

Normal email quotas and stronger guessing restrictions can outlast the remaining transaction. Recovery must then wait or require a fresh password-confirmed transaction after expiry; nothing silently extends it. Locally stored display state is not an authorization source. Existing email OTP is retained; this change does not claim NIST authentication-assurance compliance.

For a future authorized Hostinger deployment:

1. Deploy the reviewed PHP, configuration, auth JavaScript, CSS, and versioned HTML together through the existing release process. No migration is required for this change.
2. Keep the existing shared persistent database/Redis cache and rate-limiter store, MySQL connections, HTTPS/trusted-proxy configuration, session settings, Brevo SMTP configuration, and durable database queue. Preserve the current ten-minute TTL and quota values. The new reset setting defaults to 1800 seconds if absent.
3. Rebuild the Laravel configuration cache with the hosting environment's verified PHP runtime and restart the existing queue workers so they load the new issuance guard. Do not flush the authentication cache: account/IP failure and email quota keys intentionally retain their current names and history.
4. Publish the changed frontend entry files and their versioned assets through the existing asset pipeline. Only clear static asset/CDN caches as needed; do not clear server authentication budgets.
5. Verify a controlled test-account sign-in, cooldown/remaining-time behavior, manual replacement, queue delivery, expired transaction rejection, and session/remember-me behavior before general use.

No Hostinger, hPanel, DNS, Cloudflare, production database, or live environment action was taken.

## Reference rationale

The in-page resend interaction follows the pattern supported by [Auth0's login email verification screen](https://auth0.github.io/universal-login/auth0-acul-js/classes/Screens.LoginEmailVerification.html#resendCode), which provides code submission and explicit resend on the same screen. Replacement invalidates the prior issuance, consistent with [Auth0's email OTP replacement behavior](https://support.auth0.com/center/s/article/understanding-email-otp-behaviour-in-auth0-passwordless-authentication). The requested 1/3/5-minute policy is this application's policy, not a claim that Auth0 uses those exact limits.

Preserving cumulative failures across replacement follows [NIST SP 800-63B's failed-attempt guidance](https://pages.nist.gov/800-63-4/sp800-63b.html#out-of-band) and the account-level throttling principles in [OWASP's Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#login-throttling). NIST separately excludes email as an approved out-of-band authenticator; these retry safeguards do not change that classification.
