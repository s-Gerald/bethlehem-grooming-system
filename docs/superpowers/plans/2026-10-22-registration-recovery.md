# Customer registration recovery implementation plan

**Goal:** Recover interrupted customer signup securely without creating users before verification or losing legacy history.

**Architecture:** Keep pending signup in its existing table. Reuse registration OTPs to exchange ownership proof for a hashed, ten-minute, single-use review grant when the original edit credential is absent. Complete reviewed details through the existing pending-update endpoint; reconcile legacy users in place. Centralize completion and retention rules shared by controllers, mail issuance, and the existing scheduler.

**Tech stack:** Laravel 12, transactional database queue, vanilla JavaScript, PHPUnit, Node regression harness.

**Spec:** User's October 22 registration lifecycle requirements in this chat. Execute in this session; no commits, deployment, or worktree required.

## Constraints and review focus

- Registration OTP: 10 minutes; resend: 45 seconds; five attempts. Login and password reset remain independent (15-minute defaults).
- Retention: 48 hours from creation, configurable; honor an explicitly configured legacy days setting.
- Never disclose saved details or replace a password using email alone. Recovered completion requires a fresh password and cannot change the proven email.
- Prefer an existing user when both tables contain the email; never copy pending credentials onto that user. Preserve IDs and relationships.
- Failed queue insertion must roll back issuance. Used, superseded, expired, or locked codes cannot complete registration or send outdated mail.
- Cleanup, edits, issuance, and verification must agree on retention at the exact deadline.
- Verified, disabled, archived, deleted, and noncustomer accounts must never enter customer recovery.

## 1. Reproduce and cover backend lifecycle

Files: `tests/Feature/EmailVerificationTest.php`, `tests/Feature/PasswordResetTest.php`.

- [x] Record existing baseline; extend existing tests for exact expiry, abandoned signup, lost credential, forged grant, replay, retention, collisions, and legacy recovery.
- [x] Run focused regressions and confirm expected failures before implementing.
- [x] Add forward migration for recovery grant fields on both existing registrant tables and indexed pending `created_at`; retain existing uniqueness constraints.
- [x] Implement shared `CustomerRegistrationService` completion and model retention helpers; update existing controllers and OTP service.
- [x] Require verified customer eligibility at every normal password-reset stage, preserving admin/staff behavior.
- [x] Verify existing queued-mail rollback, retry, replacement suppression, and authentication tests.

## 2. Integrate existing UI and mail

Files: `scripts/api.js`, `scripts/auth/client/signup.js`, `signup-verification.js`, `verify-email.js`, `scripts/auth/sign-in.js`, `pages/client/signup.html`, `verify-email.html`, `forgot-password.html`, existing registration mail templates and notification; relevant existing interface tests.

- [x] Extend `tests/customer-signup-verification-regression.cjs` to exercise recovery, explicit reviewed completion, preserved fields, duplicate-submit protection, loading/error/resend behavior.
- [x] Reuse the signup form for ownership-authorized review, keeping email fixed and requiring a new password; no new page or modal.
- [x] Add concise recovery navigation, masked-email instruction and ten-minute expiry copy; retain OTP autofill, focus styling, alerts, and cooldown.
- [x] Match HTML and text email expiry to the service constant, preserving existing branding.

## 3. Verify and document

- [x] Run focused backend authentication/customer-list tests and frontend regressions; investigate failures. Verify migration up/down and real database concurrency where available.
- [x] Review changed consumers and obsolete expectations, run appropriate wider checks once, inspect diff for unrelated changes.
- [x] Update `docs/customer-signup-verification.md` with all five data states, deployment/retention compatibility, temporary link compatibility, performance, limitations, commands/results, and localhost/Hostinger manual checks.

## Verification outcome

220 focused backend tests passed (2,682 assertions); 76/76 frontend regression scripts passed, with signup/session regressions rerun after final UI changes. The separate overlapping-verification test passed again after adding its runtime capability probe. Broader backend run: 1,433 tests, one confirmed pre-existing service-history total failure, seven skips. Local MySQL and browser access to localhost were unavailable; staging InnoDB and responsive visual checks remain in the implementation report. No deployment or commits performed.
