# Registration and Account Recovery Implementation Plan

**Goal:** Fresh signup after abandonment, immediate credential conflicts, and accurate password recovery eligibility.

**Architecture:** Keep pending registrations, browser binding, email-proven review, and guarded encrypted mail jobs. Establish the pending row and queue its verification mail in one transaction before returning a scoped attempt credential. Delete only a matching, authorized pending attempt on deliberate exit; expiry handles browser closure. Keep final account uniqueness checks and the existing mail policy.

**Tech Stack:** Laravel/PHPUnit, vanilla JavaScript, existing Node VM regressions.

**Spec:** User attachment `877d0964-f932-4769-9d74-182fa621216a/Pasted text.txt`.

## Constraints

- Preserve signup layout, branding, fields, optional phone/username, terms, and default footer.
- One inline conflict area; email takes priority; only confirmed conflicts use it.
- Preserve current uncommitted recovery lifecycle work, staff setup, email proof, sessions, quotas, and retention.
- One browser tab for visual inspection only. User performs all manual functional input.
- No new services, dependencies, migrations, commits, or deployment.

## Tasks

- [x] Add failing backend regressions in `EmailVerificationTest.php` and `PasswordResetTest.php`: immediate all-field conflicts, reserved staff email, normalization, scoped cancellation, replacement, stale job rejection, and recovery eligibility.
- [x] Update `AuthController.php` and `CustomerRegistrationService.php`: validate availability before mail, transactionally replace only browser-and-attempt-authorized rows, return structured errors, queue guarded mail, and expose idempotent cancellation through `routes/api.php`.
- [x] Retire public job creation from `ProcessPublicRegistration.php`; keep generic resend processing and reject old creation jobs. Preserve verification review and bounded cleanup; remove obsolete review-intent endpoint/helper.
- [x] Extend `customer-signup-verification-regression.cjs` with conflict priority, independent clearing, stale responses, preserved unrelated errors/terms, fresh navigation, and explicit cancellation. Remove obsolete resume/draft expectations.
- [x] Update signup HTML/JS and `signup-context.js` to remove continuation UI, retain only active session context, and cancel on explicit exits. Keep authorized email-proven review. Update API helpers and verification consumers.
- [x] Update recovery eligibility in service/controller and inline email feedback in HTML/JS. Extend existing frontend auth regression coverage; keep codes, grants, reload behavior and rate limits.
- [x] Run focused frontend/PHPUnit suites, build assets, and inspect desktop/mobile in the same ChatGPT browser tab. Record results and deployment notes.

## Review focus

- Queue failure rolls back replacement and leaves the old valid link usable.
- Cross-browser email knowledge never authorizes replacement or cancellation.
- Cancelled/superseded/expired jobs cannot recreate pending rows or send usable old mail.
- Editing either conflicting input clears only that input's conflict; unrelated errors remain.
- Reload preserves active verification; returning to ordinary signup clears fields and continuation, including BFCache.
- Actual account records and staff setup are never deleted or overwritten; cross-browser review requires a new password.

## Verification completed

- PHP 8.4: `EmailVerificationTest.php`, `PasswordResetTest.php`, `PasswordResetInterfaceTest.php`, and `AdminStaffAccountManagementTest.php`: **308 tests, 4,005 assertions passed**. Includes actual overlapping verification workers, staff setup, cooldowns, queue rollback, and expired/superseded issuance guards.
- `node tests/customer-signup-verification-regression.cjs`: passed. Covers shared conflict priority, independent clearing, unchanged values/terms, stale responses, explicit exit cancellation, fresh BFCache return, and non-destructive reload.
- `node tests/frontend-auth-session-regression.cjs`: passed. Covers recovery feedback, stale feedback clearing, sessions, delivery states, OTP grants, reload-only restoration, and delayed responses.
- `npm run build`: passed. Existing Browserslist database-age notice remains non-blocking; dependencies were not changed.
- `git diff --check` and JavaScript syntax checks: passed.
- One ChatGPT browser tab used for inspection only. Desktop 1440 px and mobile 375 px signup and recovery inspected; no horizontal overflow. No manual data entry, OTP submissions, password changes, migrations, or deployment. Original viewport restored; tab returned to `/sign-up`.
- Desktop screenshot saved at `storage/app/registration-desktop-review.jpg` (ignored local QA artifact).

## Lifecycle and deployment notes

- Browser close/crash cannot guarantee cancellation. One-hour retention and existing scheduled pruning remain authoritative, with the existing bounded email-proven review grace. There are no destructive unload/pagehide requests.
- Returning to ordinary signup discards drafts and active continuation display. Only a tab-scoped attempt credential may be retained until its original expiry to authorize replacement; it never prefills form details or creates a session.
- Foreign pending collisions cannot be overwritten by email knowledge. The response points to the existing verification email or bounded expiry; email proof supports fresh password selection. Completed accounts and staff invitations are never cancelled by this endpoint.
- Deploy the PHP and frontend changes together, retain the database mail queue and pruning schedule, and restart long-running workers to load the new code. No schema migration or dependency change is needed.
- Old queued public registration-creation payloads are ignored safely. Unprocessed signups from before deployment must be submitted again; guarded mail for existing valid attempts continues normally.
