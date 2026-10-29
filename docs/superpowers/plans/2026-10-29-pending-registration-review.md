# Pending registration review implementation plan

**Goal:** Recognize returning browser registrations and require email proof before editing or creating an account with revised details.

**Architecture:** Keep the public encrypted registration job, existing magic links, and recovery grants. Store only presentation context and non-secret proposed drafts in the browser. Record explicit review intent in the existing shared server cache, scoped to browser and email, so an original-browser link opened in another tab cannot activate the original details prematurely.

**Tech stack:** Laravel, plain JavaScript, existing Tailwind authentication styles, PHPUnit and Node VM regression tests.

**Scope:** User's attached pending registration recovery brief. No production deployment or manual browser functional testing.

## Constraints and review focus

- Public retries stay generic and cannot overwrite credentials or reveal saved details.
- Preserve one-hour retention, thirty-minute maximum single-use links, ten-minute review grants, queueing, cooldowns and locking.
- Browser context expires conservatively; missing, malformed, mismatched or blocked storage must preserve ordinary recovery.
- Review intent must work across tabs, never become editing authorization, and never resend or consume a link.
- Only email-proven review can prefill saved details; drafts are explicitly identified, allowlisted and contain no passwords.
- Keep Bethlehem typography, signature blue, input styles, spacing and Phosphor icons. Reuse the signup form.

## Tasks

1. Update `tests/Feature/EmailVerificationTest.php` to reproduce deliberate original-browser review, unchanged links, generic intent responses, and rejected pre-proof updates. Run failing cases, then update `AuthController`, `EmailVerificationController`, `CustomerRegistrationService` and the existing API routes. Replace obsolete pre-proof editing assertions with email-proven completion tests.
2. Update `tests/customer-signup-verification-regression.cjs` for returning context, expiration, drafts, blocked storage and explicit review. Add a shared `signup-context.js` helper consumed by signup, waiting and link confirmation. Update `api.js`, existing signup scripts and the two existing HTML pages; remove the obsolete pre-proof form mode.
3. Run the focused PHP and frontend authentication regressions, relevant session/password-reset checks, asset build and syntax/diff checks. Inspect the signup UI without entering data or submitting forms. Update `docs/customer-signup-verification.md` with deployment behavior and remaining manual checks.

Execute in this workspace; no worktree, commits, deployment, new database tables or dependencies are needed.
