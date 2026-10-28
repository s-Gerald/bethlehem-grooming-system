# Customer authentication implementation plan

**Goal:** Use deliberate, single-use registration magic links and retain secure sign-in OTP, with predictable shared email limits.

**Architecture:** Extend the existing pending-registration, recovery-review, encrypted notification, Sanctum and HttpOnly account-cookie implementation. Bind pending passwords to a random HttpOnly registration-browser cookie; foreign browsers must establish a new password through the existing email-proven review grant. Cross-tab messages are hints only; a server-validated account cookie establishes each tab's session.

**Constraints:** Preserve existing uncommitted changes, name/email/password validation, customer records, staff roles, recovery rules and presentation caches. No production deployment or configuration changes. Browser observation only; the user performs functional browser testing.

## Files and responsibilities

- `app/Services/RegistrationMagicLinkService.php`, existing verification controller and notification: hashed 30-minute links, expiry, atomic replacement and deliberate POST consumption.
- Existing registration/session services and controller, pending model, one additive migration: browser binding, review revocation, cookie-confirmed session restoration and handoff acknowledgement.
- `config/authentication.php`, `app/Services/AuthenticationEmailPolicy.php`: one rolling destination/IP sending policy shared by registration, sign-in and password recovery, using shared Laravel cache locks. Defaults: 60 seconds, 3 emails/15 minutes, 6/hour, 12/day.
- Existing login service/controller: ten-minute absolute challenge deadline, five incorrect attempts per challenge and account/15 minutes, IP budget, single-use issuance.
- Existing API helper and authentication scripts/pages/styles: safe centralized errors, no automatic verification, accessible states, clear cooldown versus longer restriction; keep existing OTP input for sign-in.
- `app/Support/AccountEmail.php`: load the same policy source when cached configuration predates its addition.
- Existing auth PHPUnit and Node regression files: replace obsolete signup-code expectations while retaining identity, authorization, queue, concurrency and recovery coverage.
- `docs/customer-authentication.md`, `.env.example`: operational requirements, transition policy and manual acceptance checks.

## Execution and verification

- [x] Reproduce stale email configuration and current insecure token/retry behavior with focused failing tests.
- [x] Implement shared sending policy and browser-bound registration; verify token hashes, trusted/foreign activation, invalid/expired/reused/replaced links, retention, review expiry, rollback and customer preservation.
- [x] Implement OTP deadline, single-use and persistent budgets; verify repeated challenges, resends, protected APIs, staff and recovery regressions.
- [x] Replace obsolete signup OTP interface/tests; exercise scripts with fake DOM/network and verify no automatic POST, duplicate submissions, safe handoff, storage failure and server-aligned waiting states.
- [x] Run focused PHPUnit/Node checks, then broader regressions/build justified by the shared API and authentication scope. Report actual results and any environment-dependent skips.

## Review focus

- An email owner opening an attacker-started registration in another browser must replace the pending password and invalidate the attacker's edit credential.
- GET/scanner loads must never create users, consume tokens or issue sessions.
- Rejected or failed resends must preserve the current credential; queued obsolete jobs must be suppressed.
- Missing storage, lost responses and closed original tabs must retain a secure dashboard entry point.
- Cached configuration predating a new policy must use the same validation source without leaking internal errors.

The project instructions make planning advisory and authorize implementation in this chat; no additional execution-method approval, worktree or commit is required.
