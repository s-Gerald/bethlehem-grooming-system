# Progressive Sign-In Protection Implementation Plan

**Goal:** Remove unintended long browser lockouts and enforce bounded progressive password cooldowns on the server.

**Architecture:** Keep identifier/IP isolation, a single expiring cache record per pair, and the existing independent IP RateLimiter. Serialize pair checks and password-state transitions with Laravel cache locks; release before email delivery. The browser displays only explicitly classified server cooldowns in memory.

**Scope:** User task in this chat. No schema changes, dependencies, commits, pushes or deployment. Preserve all account, OTP, recovery, session and email restrictions and existing styling.

## Findings before edits

- AuthController limits failed passwords to 5/pair and 25/IP in a 60-second window, checking before incrementing (the fifth failure currently returns 401).
- LoginEmailChallengeService can propagate AuthenticationEmailPolicy's 429 with a daily-quota Retry-After up to 86400 seconds. The frontend treats any sign-in 429 as a password lockout and persists it.
- signInLockoutUntil accepts any future stored timestamp; neither identifier nor server confirmation bounds restoration. Countdown uses wall-clock differences.
- Sign-in HTML has no-store handling via Laravel; JavaScript is cached for an hour and uses explicit version query strings.
- Documented Hostinger deployment uses CACHE_STORE=database with existing cache/cache_locks tables. No live production response/cache/clock has been inspected.

## Steps and verification

1. [x] Extend EmailVerificationTest in place: first four failures, immediate fifth 60s, next thresholds 300s/900s, bounded blocked retries, exact 900s inactivity, successful verification reset even when email sending rejects, IP isolation/shared-IP budget, phone normalization, persistent database cache and contention. Run red, implement in AuthController, run green. Keep constants fixed to the requested policy; no new settings needed.
2. [x] Extend frontend-auth-session-regression.cjs with controlled-clock real-script behavior: stale/malformed storage, classified 429 only, header precedence and invalid duration handling, reload/server remaining duration, editing identifiers and in-flight responses, expiry, background and page restoration. Run red; update api.js and sign-in.js; run green. Retain verification-code formatter and amber styling.
3. [x] Update sign-in.html API and sign-in script versions together (the page has no API preload); use a separate concise live status for countdown entry/exit so seconds are not announced. Inspect existing markup and style changes for design preservation.
4. [x] Run EmailVerificationTest, IndependentAuthenticationSessionsTest, relevant password recovery/auth tests, frontend auth/session and signup regression scripts, syntax/diff checks. Test database cache with controlled server time. Document results and Hostinger release steps.

## Review focus

- Blocked requests must not extend timers or escalation. A full 900-second cooldown is itself 900 seconds without a checked password failure, so escalation resets upon expiry under the requested inactivity rule.
- Correct passwords clear only pair password state before downstream account/email restrictions; never clear shared IP, OTP or email budgets.
- Identifier changes cannot inherit a pair countdown, including delayed responses; IP countdowns apply to every identifier.
- Malformed/oversized Retry-After cannot become an hours-long browser block. A renewed server rejection remains authoritative.
- Cache locks expire, release in finally, and do not span email delivery or serialize successful staff logins across an IP.


## Completed implementation and evidence

Confirmed by regression: AuthenticationEmailPolicy can return `email_rate_limited` with `Retry-After: 43200` after the daily quota is consumed. The previous sign-in handler accepted this as a password lockout, saved an absolute timestamp, and restored it without server confirmation. A separately seeded future storage timestamp reproduced the excessive lockout before the fix. A backward client clock could further extend the old wall-clock timer. No production HAR, affected browser storage, server clock, or cache contents were inspected, so the exact historical trigger, production clock skew, and stale-script involvement remain unconfirmed.

The server now counts checked incorrect passwords in batches of five per normalized identifier/IP pair. Attempts 1-4 return generic 401; the fifth immediately returns a classified 429 and 60 seconds. Subsequent batches produce 300 and 900 seconds. Level is capped at 3. Each checked failure refreshes the pair record for 900 seconds; blocked requests do not update it. Therefore a completed 900-second cooldown also satisfies inactivity reset, and the next cycle begins at 60 seconds. Correct password proof deletes only that pair state before account/verification/email checks. The separate 25-failure/60-second IP budget is retained and never cleared by password success. Pair locks serialize verification and state transitions; a separate short IP lock protects counter updates. Contention returns a generic one-second busy response, without starting a browser password countdown. Neither lock spans email sending.

The browser deletes the legacy storage key and never writes or restores password cooldown timestamps. Only `sign_in_password_cooldown` and `sign_in_ip_cooldown` responses can start a timer. A valid duration must be finite, positive and at most 900 seconds; malformed/oversized values leave sign-in available for a fresh authoritative server response. API handling gives a valid Retry-After header precedence over the JSON body, preserves zero, rejects nonfinite/negative values, and interprets HTTP-date headers against the response's server Date rather than the device clock. Longer email/OTP restrictions remain enforced by their unchanged backend controls.

Countdowns use one monotonic in-memory deadline. Identifier edits remove pair feedback; IP feedback applies across identifiers. A delayed response cannot apply a pair timer to an edited identifier. Page restoration/visibility updates use the original deadline, and an expired suspended timer is removed before submitting so it cannot erase a later response. Refresh starts with an enabled form; the next response supplies remaining server time. Changing devices on the same IP cannot evade server state. A different IP intentionally has separate state, preserving the existing anti-global-lockout design; distributed attacks are not converted into a global account penalty.

Existing layout, colors, spacing, border radii, Phosphor icons, visibility toggle and Forgot Password link are unchanged. The amber message updates in place, credential fields remain editable, and a separate screen-reader-only status outside the spaced form announces entry/expiry rather than every second. No CSS, generated assets, OTP logic, recovery logic, account/session restrictions or database schema changed. No new endpoint or polling was added.

### Files changed

- `app/Http/Controllers/AuthController.php`: progressive pair state and concurrency protection; remove the old fixed pair RateLimiter hits/check/clear paths.
- `scripts/auth/sign-in.js`: replace persistent wall-clock timer and blanket 429 interpretation with classified in-memory countdowns and accessible state handling.
- `scripts/api.js`: validate Retry-After parsing while retaining the original response classification.
- `pages/client/sign-in.html`: version changed scripts and add a hidden status without altering form spacing.
- `.htaccess`: no-store header for directly served sign-in HTML when Apache mod_headers is available. Laravel's existing static HTML route already sends no-store.
- `tests/Feature/EmailVerificationTest.php`: update existing fifth-attempt expectation and add progression, expiry, scope, reset, email-quota, database-cache and contention regressions.
- `tests/Feature/IndependentAuthenticationSessionsTest.php`: add a deterministic two-process password-failure overlap test using a disposable shared SQLite database cache.
- `tests/frontend-auth-session-regression.cjs`: behavioral storage, countdown, race, lifecycle and API-header regressions with controlled clocks.
- `tests/customer-signup-verification-regression.cjs`: update the existing sign-in fixture with explicit server classification and a monotonic test clock.
- This implementation plan/report.

### Final verification

PHP 8.4.26 / PHPUnit 11.5.55: **253 tests, 3528 assertions, all passed** together:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/EmailVerificationTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/PasswordResetTest.php tests/Feature/PasswordResetInterfaceTest.php tests/Feature/SessionLockTest.php tests/Feature/ActivityLogTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php
```

All five frontend scripts passed:

```text
node tests/frontend-auth-session-regression.cjs
node tests/customer-signup-verification-regression.cjs
node tests/session-inactivity-regression.cjs
node tests/admin-data-cache-regression.cjs
node tests/admin-security-settings-regression.cjs
```

JavaScript syntax checks and PHP controller lint passed; git diff --check passed. Initial new regressions failed against the old implementation as expected. Baseline authentication/session suites passed before edits (175 tests / 2141 assertions). The separate-process fixture initially lacked the normal AJAX header and received the existing 419 origin rejection; the fixture now matches the production client, without changing origin/CSRF enforcement. PHPUnit required sandbox escalation because PHP's Windows is_readable returned false for readable workspace files; no test configuration or checks were weakened. Tests use isolated SQLite/cache fixtures, never the development or production application database. No full production build was necessary because no CSS or bundled/generated assets changed. No live responsive-browser or real inbox acceptance is claimed.

### Hostinger release considerations

- Repository context: local `gerald-frontend` was clean before work. The supplied workspace's remotes name `s-Gerald/bethlehem-clinic-frontend` and `rij-summit/bethlehem-grooming-system`; this differs from the repository name in the task. No remote, branch or checkout was changed.
- Local `.env`, `.env.example`, config/cache.php and the existing Hostinger release documentation use `CACHE_STORE=database`. Keep that shared persistent store and existing `cache`/`cache_locks` tables; no new migration or Redis is needed. Database-backed Laravel locks are supported by the installed Laravel 12 code and [Laravel's database lock API](https://api.laravel.com/docs/12.x/Illuminate/Cache/DatabaseLock.html). Runtime cache-store overrides or an array/null production cache would not preserve state across requests; actual hosted configuration remains to be checked at release.
- Deploy changed backend and static files together, including root `.htaccess`. The new API/sign-in script URLs invalidate old script cache entries; purge any Hostinger/LiteSpeed/CDN cached sign-in HTML so users receive those URLs. Confirm the deployed HTML response has no-store and the browser loads `sign-in.js?v=progressive-20261029`. Existing already-open old documents need a reload.
- Keep the existing PHP/Composer runtime contract. Changes use Laravel 12 APIs and syntax compatible with the declared PHP ^8.2 application floor; automated suites ran on the project's PHP 8.4 test target. Existing README runtime/platform checks still apply.
- Server timestamps are Unix seconds and TTLs, independent of timezone formatting; the browser does not compare its clock with a server absolute timestamp. Keep web/CLI hosts synchronized. No new environment variables, worker/cron setup, URLs or infrastructure dependencies are required.
- Do not clear the entire application cache as a routine release step: doing so would discard unrelated IP, OTP and email budgets. Old pair RateLimiter keys expire naturally after their original 60 seconds. Rebuild config/route caches only according to the existing deployment process if needed; neither config nor routes changed here.
- Production smoke checks after release: four generic errors, immediate fifth 60s response, refresh then authoritative remaining duration, same pair on a second browser, a distinct staff identifier on the same IP, different-IP legitimate sign-in, confirmation email/Remember Me/session completion, and recovery. Do not use real account password failures to load-test production.

No commits, pushes, migrations, live Hostinger inspection or deployment were performed.
