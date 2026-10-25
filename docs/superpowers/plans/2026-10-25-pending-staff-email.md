# Pending Staff Email Correction Implementation Plan

**Goal:** Correct an existing pending staff email, replacing its setup link without changing account identity.

**Architecture:** Keep authorization and shared email validation in PendingStaffAccountService and token issuance in PasswordResetService. Use the existing details dialog with an internal email view. Keep setup-request → staff locking consistent with completion and resend; enqueue replacement mail inside the database transaction.

**Tech stack:** Laravel, PHPUnit, Alpine, Tailwind, existing Node regression harness.

**Spec:** User attachment `Pasted text.txt`, Phase 1 admin email correction, supplied 2026-10-25.

## Constraints
- Only pending, nonarchived staff; active administrator password checked against a locked fresh record.
- No migrations, dependencies, OTP flow, active-account email changes, commits, or deployment.
- Browser inspection only; user performs navigation and manual input testing.
- Preserve account identity, roles, sessions, existing setup expiration and configured URLs.

## Files and responsibilities
- `app/Services/PendingStaffAccountService.php`: shared staff email rules/availability; transactional correction and administrator reauthentication.
- `app/Services/PasswordResetService.php`: atomic setup-token rotation and durable mail queueing, reused by correction and resend.
- `app/Http/Controllers/AdminSecurityController.php`, `routes/api.php`: PATCH pending setup email endpoint, safe validation/error response and updated account payload.
- `scripts/api.js`: authenticated correction request.
- `scripts/components/admin-settings.js`: modal view state, validation, submission, focus and stale-list response rejection; shared account mapping.
- `pages/admin/settings.html`: email-adjacent action and accessible inline modal form; asset versions.
- Existing account-management, interface and frontend regression tests: extend relevant coverage.
- Generated `css/output.css` and build artifacts as produced by existing build tools.

## Review focus
- Queue insertion failure must roll back email and token, leaving the original link valid.
- Setup completion or resend before mutation must be rechecked through fresh locked records.
- Existing and unexpired registration email claims are rejected case-insensitively; DB uniqueness remains authoritative.
- An older list response must not overwrite a corrected account.
- Cancel/close during submission must not abandon a request or expose a saved password.

## Task 1: Backend correction and atomic issuance
- [x] Extend AdminStaffAccountManagementTest for all three roles, preserved fields, old/new setup links, validation, authorization, queue rollback, corrected resend/cancel, and mutation-time state changes.
- [x] Run new tests and observe missing-endpoint failures.
- [x] Implement `PendingStaffAccountService::correctEmail(User $requester, User $staff, string $email, string $password): User`; reuse `PasswordResetService::resendStaffSetupLink(User $staff): array` inside the same transaction. Lock issuance before target, matching existing setup completion.
- [x] Add PATCH `/api/admin/security/staff/{staff}/setup-email`, restricted to email/current_password and throttled like sensitive admin changes.
- [x] Run account-management and password-setup tests; failures must be investigated before proceeding.

## Task 2: Existing dialog correction view
- [x] Extend existing Node regression with validation, visibility, cancellation/focus, recoverable errors, duplicate submission, local update, and stale-list response checks; observe failure before implementation.
- [x] Add `API.correctStaffSetupEmail(staffId, email, currentPassword)` and internal details/email view state, without another dialog or list fetch on success.
- [x] Add accessible field errors, password visibility, focus containment, in-flight dismissal protection, and responsive footer using current styles.
- [x] Update obsolete interface expectations and version references; run relevant Node and PHP interface tests.

## Task 3: Verification and delivery
- [x] Run focused login OTP/single-session/password-reset regression suites and production build.
- [x] Inspect diff for unintended changes, raw token exposure, hardcoded hosts, migrations, unrelated cache/session changes.
- [x] Record test results, concurrency verification limits, deployment steps, and manual tests reserved for the user.

Execution proceeds in this session under the user's implementation request and AGENTS.md advisory planning policy.

Verification results and remaining manual/production acceptance are recorded in `docs/pending-staff-email-correction.md`.
