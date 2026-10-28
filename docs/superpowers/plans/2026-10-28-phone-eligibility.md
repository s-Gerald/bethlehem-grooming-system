# Philippine Mobile Eligibility Implementation Plan

**Goal:** Accept optional 11-digit 08/09 registration numbers while restricting authenticated customer OTP sends to the configured prefixes.

**Architecture:** Keep registration independent of SMS eligibility. Configure prefixes in `config/services.php`; enforce in `CustomerPhoneVerificationService::send` before locks, budgets or challenge mutations, including resends. Return only the public prefix policy with the modal's existing authenticated state read. Keep the existing lazy shared modal and OTP lifecycle.

**Tech stack:** Laravel 12, existing PHP/JavaScript and PHPUnit/Node regression harnesses.

**Spec:** User's October 28 implementation request in this chat.

## Constraints

- No commits, branches, pushes, deployments, private environment edits, migrations or real SMS sends.
- Preserve uniqueness, passwords, authorization, locking, budgets, expiry, recovery, cached access and prior verified phones.
- No network-specific customer errors, extra policy requests, provider lookups or new dependencies.

## Tasks

1. Extend existing `PhoneVerificationTest.php`, `EmailVerificationTest.php`, signup and dashboard regression scripts. Confirm missing 08 acceptance, blocked destinations and inline correction fail before implementation. Preserve concurrency/security cases, changing only OTP fixtures that now violate policy.
2. Update `config/services.php`, `CustomerPhoneVerificationService.php`, `PhoneVerificationController.php`, `AuthController.php` and existing signup validation. Test every approved prefix, blocked/unknown prefixes, normalized +63 input, unchanged budgets/challenges, resend policy changes and preserved verified access.
3. Update `customer-phone-verification.js` and existing verification CSS: inline polite error under input; strict digits/11-character input with selection-aware sanitization and paste; completion/blur/submit validation; clear stale errors; prevent invalid and duplicate sends. Consume policy from existing state read. Keep existing OTP screen/recovery/dismissal.
4. Refresh existing asset versions at Settings/pre-registration and signup entry points; document configurable policy, portability and normal deployment/config cache refresh in existing phone documentation.
5. Run focused and complete available PHP/Node suites, production build and diff checks. Inspect modal at mobile/desktop widths if browser tooling permits. Record any actual remaining limitation without claiming live delivery.

## Review focus

- Empty/incomplete edits clear feedback; malformed submissions cannot send.
- Unsupported resends cannot change a historical challenge or consume paid budgets.
- A policy update reaches the frontend on explicit modal open, including restored challenges.
- Previously verified unsupported numbers retain access and replacement protection.
- 08 phone identifiers and pending registration edits remain compatible with accepted accounts.

## Local outcome

All implementation tasks completed. Full PHP: 1,933 tests, 25,223 assertions, eight existing environment-dependent skips; no failures. The one discovered real-clock regression in the unchanged password-reset test was corrected by freezing time, retaining every security assertion. Full JavaScript and production-build results are recorded in `docs/customer-phone-verification.md` and the final report. Browser visual acceptance is limited by unavailable localhost access and Windows URL detection; no real provider delivery is claimed.
