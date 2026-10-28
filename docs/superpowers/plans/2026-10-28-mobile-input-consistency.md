# Philippine Mobile Input Consistency Implementation Plan

**Goal:** Validate all real editable Philippine mobile inputs against one recognized-prefix policy while retaining the independent customer OTP eligibility restriction.

**Architecture:** Use `resources/data/philippine-mobile-policy.json` as the general prefix source, loaded through a small Laravel config/support/rule and generated into one existing-style browser utility during the normal build. Keep OTP prefixes in `services.sms_verification.allowed_prefixes` and deliver them through the existing modal state read. Reuse the shared input binding for sign-up, Add Customer, walk-in and the lazy OTP modal; preserve their layouts and error styling.

**Spec:** User's attached system-wide validation follow-up, October 28, 2026.

## Scope and safety

- Preserve all existing uncommitted work; no branch, commit, push, deployment, private environment changes or migrations.
- General policy accepts the supplied prefixes plus mobile prefixes verified against Google's maintained PH numbering metadata; no new runtime package or carrier lookup.
- Retain required/optional fields, uniqueness, account passwords, OTP security, existing verified numbers and paid-send guards.
- Customer Settings phone is read-only and uses the shared OTP modal. Admin/Staff Customers currently has Add and read-only details, with no phone edit endpoint. Do not invent editing features.
- Admin Settings' generic clinic contact placeholder is not a persisted mobile-only field; preserve it. Search inputs and stored/read-only historical contacts are outside strict entry validation.

## Tasks

1. Extend existing signup, phone, Add Customer, walk-in, email and frontend regression locations to reproduce invalid-prefix acceptance, 08 rejection, premature/stale errors and malformed-character normalization. Use existing test fixtures and meaningful observable assertions.
2. Add `PhilippineMobile` support and `ValidPhilippineMobile` rule backed by shared JSON/config. Use in Auth registration, StoreUnregisteredCustomerRequest, both walk-in requests and missing-phone selected-owner fallbacks. Preserve historical selected contacts without validating them as newly entered values.
3. Add one generated-prefix browser utility with normalization, synchronous validation, selection-aware numeric editing/paste and accessible inline binding. Replace local general validation/editing in signup, Add Customer, walk-in and OTP modal. OTP-specific exclusions retain their own generic error and server policy.
4. Update only affected HTML inputs, script load order and asset versions; retain raw static asset deployment and normal build generation. Update affected original tests and remove obsolete validators rather than leaving parallel logic.
5. Run focused PHP/Node tests, then full available suites and production build. Review policy parity, historical contacts, non-mobile exclusions, no extra API requests and unchanged OTP budgets/security. Document results and deployment configuration refresh.

## Review focus

- Completed unrecognized values fail; incomplete typing stays quiet; optional clearing clears errors; required blur/submit still fails.
- Full/selected paste preserves unselected digits and caret; backend rejects injected letters instead of silently deleting them.
- Saved historical contacts remain unchanged; new fallback contact for a phone-less selected owner must validate.
- General Smart/TNT acceptance cannot loosen customer OTP eligibility.
- New frontend utility loads before classic consumers and through the existing lazy module for OTP; no per-keystroke network reads.

## Outcome

Implemented the shared source, PHP policy/rule, generated browser utility and all audited editable mobile consumers. Existing historical and read-only contacts remain compatible. Customer OTP eligibility remains independently configured and guarded before paid-send effects. Full PHP suite: 1,960 tests, 25,445 assertions, eight existing environment-dependent skips; no failures. The original JavaScript regressions and production build were run, with final results in the phone-management notes. No private environment edits, migrations, branch/commit/push or deployment.
