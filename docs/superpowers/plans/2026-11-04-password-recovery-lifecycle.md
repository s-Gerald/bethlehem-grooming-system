# Password Recovery Lifecycle Implementation Plan

**Goal:** Reopened recovery starts at email entry; successful replacement codes supersede old codes and grants.

**Architecture:** Keep the existing per-account recovery row and issuance/queue transaction. Restore browser recovery state only for navigation classified as reload; scrub departing documents and reject their delayed responses.

**Tech Stack:** Laravel/PHPUnit, vanilla JavaScript, Node VM regression harness.

**Spec:** User attachment `Pasted text.txt` (Update Forgot Password Recovery Lifecycle).

## Constraints

- Preserve existing design, clean URLs, account privacy, sending policies, lockouts, and unrelated authentication flows.
- Use the existing browser tab for visual inspection only; the user performs manual functional testing.
- No new dependencies, migrations, polling, worktrees, or commits.

## Tasks

- [x] Modify `tests/Feature/PasswordResetTest.php` to replace the obsolete grant-survives-resend expectation, cover all roles, and prove queue rollback preserves verified authorization. Run these tests before changing production code.
- [x] Extend `tests/frontend-auth-session-regression.cjs` with navigation types and behavioral checks for fresh visits, reload, BFCache, stale asynchronous responses, and sign-in prefill. Confirm new regressions fail first.
- [x] Update `app/Services/PasswordResetService.php` to permit grant replacement while preserving attempts and transactional queue insertion. Keep private restrictions account-safe; use conditional public confirmation copy in `app/Http/Controllers/PasswordResetController.php` and the existing frontend confirmation description.
- [x] Update `scripts/auth/forgot-password.js` to restore only reloads, clear fields/deadlines/errors on abandonment, and ignore responses from abandoned journeys. Bump the script version in `pages/client/forgot-password.html`. Keep the existing neutral confirmation and controls.
- [x] Run the auth/session frontend regression and password-reset PHPUnit tests, including `PasswordResetInterfaceTest.php`. Inspect the initial UI again in the same browser tab without submitting data.

## Review focus

- Reload retains grants and lockouts but never passwords or entered OTPs.
- Back/Forward and fresh navigation discard grants and cooldown display without requests.
- Responses resolving after departure cannot revive credentials or redirect a later journey.
- Replacements preserve incorrect-attempt counts; changing IP cannot unlock an account.
- Queue insertion failure rolls back both replacement and sending reservations.
- Public responses never promise delivery or disclose private account/destination restrictions.

## Verification

- Observed failures first for restored countdowns, verified-grant replacement (all three roles), replacement queue insertion, generic retry copy, retained code fingerprints, and delayed BFCache responses.
- `node tests/frontend-auth-session-regression.cjs`: passed.
- PHP 8.4: `PasswordResetTest.php` and `PasswordResetInterfaceTest.php`: 38 tests, 582 assertions passed.
- PHP 8.4: pending-customer password-recovery case in `EmailVerificationTest.php`: 1 test, 10 assertions passed. Only its expected recovery response copy changed.
- `git diff --check`: passed (existing CRLF conversion notices only).
- Same ChatGPT browser tab: email-entry page inspected at 320, 375, 414, and 768 px; no horizontal overflow and no console warnings/errors. Temporary viewport override cleared.
- No manual email/OTP/password submissions, deployment, or database migrations performed.
