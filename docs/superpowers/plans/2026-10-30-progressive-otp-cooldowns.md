# Progressive OTP Cooldowns Implementation Plan

**Goal:** Support 1/3/5-minute source cooldowns and in-page resend recovery within the original ten-minute password-confirmed transaction.

**Architecture:** Keep issued-code exhaustion in `login_email_challenges.failed_attempts`. Use the existing shared cache and Laravel rate limiter for bounded source escalation history and active cooldown, serialized by the existing account row and verification IP locks. Preserve independent account/IP budgets and the authentication email policy.

**Tech stack:** Laravel/PHP, shared database or Redis cache, database mail queue, vanilla JavaScript, Node VM regression harness.

**Spec:** User request in this chat, “Implement Progressive OTP Cooldowns With In-Page Resend Recovery”. Execution remains in this session under AGENTS.md; no commits, worktrees, migrations, or deployment required.

## Constraints

- Five failures per issued code; five genuine source failures per escalation round; cooldowns 60/180/300 seconds, maximum 300.
- Source history resets after 1800 seconds without a counted failure or successful full authentication for that source; resend/navigation/blocked requests never reset or increase it.
- Preserve account 20/900s and IP 25/900s limits, password throttling, quotas, queue safeguards, sessions, remember-me and suspicious activity detection.
- Original expires_at never changes on replacement. All endpoints remain authoritative.
- Existing feedback shows the only restriction timer; disabled Resend Code has the existing Phosphor lock-key icon. No redesign, polling, automatic resend, or user-facing “penalty”.

## Review focus

- Resending before exhaustion must reset only the issued-code allowance, while partial source rounds still accumulate.
- Concurrent confirmation/replacement must share account/challenge row lock order; failures cannot resurrect an old code.
- Stronger budgets and email quotas can outlast the transaction; expiry must win over an unusable recovery action.
- Refresh/BFCache must preserve exhausted UI state and deadline without treating browser storage as authorization.
- Busy/transient/email HTTP 429 responses must retain distinct behavior and never imply exhaustion or successful mail issuance.

## Tasks

- [x] Update `tests/Feature/EmailVerificationTest.php` in place: progression, blocked retries, source reset, distributed limits, replacement allowance, fixed expiry, failed queue rollback, resume and sign-in remaining time. Run focused PHPUnit and observe expected failures.
- [x] Update `LoginEmailChallengeService.php`, `LoginEmailChallengeController.php`, `AuthController.php`, `config/authentication.php`: separate source history and active rate limiter; retain DB issued-code count; allow atomic replacement with zero issued-code failures; expose exact transaction remainder and stable exhausted response. Run EmailVerificationTest.
- [x] Update `tests/frontend-auth-session-regression.cjs` in place: remove obsolete input re-enable and no-OTP-countdown assertions; verify lock, single timer, manual recovery, quota failures, reload, expiry and busy behavior. Observe expected failures.
- [x] Update `scripts/api.js`, `scripts/auth/client/verify-email.js`, `scripts/auth/sign-in.js`, verification/Sign In HTML and relevant CSS: preserve pending challenge on temporary restriction, persist UX deadline/exhaustion, render one restriction timer, manual replacement, exact expiry and typed Sign In countdown. Preserve existing shared countdown utilities.
- [x] Run relevant Laravel authentication/security/session/email tests and Node auth/signup tests; inspect diff, syntax, cache-busting and icon/accessibility states. Add local verification and future Hostinger deployment notes with actual results.

## Completion evidence

- PHP authentication feature suites: 245 tests / 3656 assertions passed.
- Email quota unit suite: 8 tests / 44 assertions passed.
- Both relevant Node regression scripts and changed-script syntax checks passed; diff whitespace check passed.
- In-place obsolete expectations replaced. No deployment or migration.
- Browser visual QA blocked by localhost timeout / Windows browser URL confidence; manual desktop/mobile steps are in `docs/progressive-otp-cooldowns.md`.
