# Verify-first Customer Registration Implementation Plan

**Goal:** Create customer accounts only after email approval, on the original bound browser.

**Architecture:** Pending rows contain email and hashed attempt/browser/link credentials, approval/completion timestamps and the completed session ID. Signup retains all form values in memory. Email POST approves one attempt; bound finalization revalidates details and atomically creates the account and its first session. A domain-separated HMAC of the original browser credentials makes the same first session recoverable after response loss without storing its plaintext or ciphertext.

**Tech Stack:** Laravel 12, Sanctum, existing encrypted database mail queue and immediate delivery, vanilla JavaScript/Tailwind.

**Spec:** User's “Major Customer Account Creation Refactor” request in this chat.

## Constraints and review focus

- One-hour maximum attempt retention; link expiry is separate from approval validity.
- No profile or password persistence before finalization; no durable browser password storage.
- Keep completed accounts, sign-in OTP, password recovery, staff rules, and existing session policy.
- Reject stolen attempt credentials without the HttpOnly browser cookie, including after approval/completion.
- Retry issuance without rotating the link; rejected resends and failed queue inserts preserve prior state.
- Serialize finalization and translate database uniqueness races to recoverable field conflicts.
- Never send SMTP while holding a transaction; reuse durable insertion and post-response immediate transport.
- Lost memory requires re-entry on the original browser; verification device never receives a session.

## Tasks

1. **Verification-only schema and API**
   - Update `tests/Feature/EmailVerificationTest.php`: replace pairing/review/reservation expectations with tests for storage boundaries, cross-device approval, binding, retry, expiry, uniqueness, cleanup and transactional session creation. Preserve unrelated OTP/security tests.
   - Observe new tests fail against the current behavior.
   - Add `database/migrations/2026_11_07_000001_make_customer_registration_verify_first.php`, invalidating only old pending rows and rebuilding this temporary table. Include a scoped reversible schema transition.
   - Simplify `PendingCustomerRegistration`, `CustomerRegistrationService`, `RegistrationMagicLinkService`, `AuthController`, `EmailVerificationController`, notification eligibility and registration routes. Remove `RegistrationPairingService` and the obsolete public registration job.
   - Remove pending-customer reservations from `AccountEmail`, `AccountUsername` and `CustomerPhoneVerificationService`; preserve completed/staff reservations.
   - Verify with the registration feature test, queue tests and directly affected shared validation tests.

2. **Original-page waiting and finalization**
   - Replace frontend pairing/review regressions with observable waiting, polling, recovery, editing, duplicate submission and verification-device tests; observe failures.
   - Simplify `signup-context.js` to opaque attempt metadata; `signup-verification.js` owns waiting/polling/resends; `signup.js` retains details and finalizes.
   - Update `scripts/api.js` to send email-only starts and separate approval from attempt-bound finalization; remove old review/session handoff APIs.
   - Update `signup.html` and `verify-email.html`, preserve the signup design and login OTP markup; simplify only registration logic in `verify-email.js`. Remove pairing-only CSS.
   - Bump affected asset references; verify frontend registration and shared session regressions.

3. **Integration and deployment readiness**
   - Update directly affected password/name/phone/credential tests and fixtures to use finalization for full validation and no pending reservations.
   - Run relevant PHP authentication suites, frontend regressions, migration tests and production build. Inspect final diff for secrets, orphaned code, pairing references and unrelated changes.
   - Update `docs/customer-signup-verification.md` with workflow, security boundaries, migration/rollback caveats, queue/deployment commands and manual HTTPS/two-device acceptance checks. Do not deploy or modify production configuration.

## Execution results

Implemented all three tasks in this chat. Backend: 651 tests / 8,718 assertions, no failures or errors, one existing opt-in MySQL migration skip. Signup, shared API/session and email-delivery frontend regressions pass. Production build passes. Full frontend run: 94/96 scripts, with two unchanged settings harnesses missing their CredentialPolicy dependency. Schema upgrade/rollback and real overlapping SQLite finalizations pass. No deployment or application-database migration performed. Hostinger/MySQL, HTTPS/two-device real-mail and rendered-layout checks remain documented in `docs/customer-signup-verification.md`.

Final review reproduced and fixed stale immediate-delivery recipient snapshots sending consumed links. The final direct mail/OTP/password-recovery follow-up passed 194 tests / 2,511 assertions. Unused legacy issuance parameters/scopes and legacy-link configuration were removed. Historical migration and phone-status coverage was retained; phone/email conformance now runs at finalization.
