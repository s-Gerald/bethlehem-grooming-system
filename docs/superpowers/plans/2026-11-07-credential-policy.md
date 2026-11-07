# Credential Policy Implementation Plan

**Goal:** Standardize newly selected passwords and usernames while preserving existing authentication and credential verification.

**Architecture:** One Laravel password rule owns deterministic format validation and the existing fail-closed HIBP screening. AccountUsername owns username format and reservation checks. One lightweight browser utility mirrors format rules and manages field feedback; server screening remains authoritative.

**Tech Stack:** Laravel 12, PHP, vanilla JavaScript, Alpine, PHPUnit, Node regression scripts, Vite.

**Spec:** User attachment `Pasted text.txt`, System-Wide Password and Username Standardization.

## Constraints

- Customer 8–64; admin/staff 15–64; printable ASCII, exact internal spaces, no boundary spaces, no composition requirement.
- Screen only new passwords; preserve bcrypt and legacy verification. No schema, deployment, invitation, OTP, cooldown, or automatic credential migration changes.
- Usernames 3–30 ASCII characters, letter first, letter/number last; unchanged legacy usernames remain valid.
- Preserve Bethlehem styling, field order, visibility controls, autocomplete and security revocation.

## Review focus

- Screening failure must preserve pending registrations, recovery grants and credential changes.
- Screening must occur outside database locks; saving must recheck current authorization.
- Recovery minimum must come from the server-side grant, including after a reload.
- Username reservations include pending customers and pending staff, with existing self exceptions.
- Editing/deleting fields clears stale server errors and refreshes dependent confirmation feedback.

## Tasks

1. [x] Add behavioral policy tests; run them red. Implement `App\Rules\AccountPassword::rules`, `messages`, role-specific format/screening, and AccountUsername format checks. Run policy tests green.
2. [x] Integrate registration, customer settings, privileged requests, recovery, staff setup, rotation and seeders. Screen before mutation transactions and retain locked authorization rechecks. Extend existing feature tests for boundaries, grant preservation, legacy credentials and username-only changes; run relevant PHPUnit tests.
3. [x] Add browser utility behavioral tests; run red. Implement shared password/username format and field feedback binding; integrate signup, setup, recovery and settings. Replace obsolete instructions/checklists; retain established layouts. Run affected Node regressions.
4. [x] Update proven obsolete assertions/fixtures, inspect the diff, run relevant authentication regressions and production frontend build. Document verification and deployment considerations.

Execution follows the user's authorization in this chat; no worktree, commit or deployment is required.

## Verification and deployment

- Verified the 11 affected PHP suites: 556 tests, including sessions, legacy credentials, grant preservation, reservations and security-page contracts. The existing `registration_pending` response contract is preserved.
- Passed the credential-policy, signup-verification, admin-security-settings, frontend-auth-session and session-inactivity Node regressions.
- Production `npm run build` passed. Its existing Browserslist database age warning remains informational; no dependency updates are part of this task.
- No schema migration, deployment seeding, automatic password rotation or forced migration was added. Deploy PHP and frontend assets together. New password operations use the existing outbound HIBP endpoint and fail closed when it is unavailable.
- Unchanged seeder passwords retain their existing hash and do not trigger another safety lookup.
