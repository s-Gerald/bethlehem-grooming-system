# System-wide Email Validation Implementation Plan

**Goal:** Apply one email entry policy to existing signup, customer-contact, walk-in, and staff-account workflows while preserving legacy identity and verification flows.

**Architecture:** Maintain provider domains, explicit exception domains, typo mappings and limits in resources/data/account-email-policy.json. Laravel AccountEmail consumes the configured policy; a build script embeds it in the shared classic frontend utility for local use without fetching. Existing consumers keep their workflows and field components.

**Tech Stack:** Laravel 12, PHP validation rules, vanilla JavaScript, Alpine, existing Node regression harnesses and PHPUnit.

**Spec:** User attachment: System-Wide Email Validation Improvements.

## Constraints and review focus
- Preserve local-part identity; normalize surrounding whitespace and domain case only. Keep account comparisons case insensitive.
- Entry requires 3–64 ASCII local-part octets, established syntax, approved domains and existing column lengths (150 for accounts/contacts; 255 for walk-in snapshots).
- No additional requests, dependencies, DNS, OTP for contact entry, commits, pushes or deployments.
- Existing sign-in/reset/resend and unchanged verified legacy registration details remain compatible.
- Ignore stale field errors after edits; clear error and styling on input; validate on blur/submit; focus first invalid field.
- Typed contact email alone must not link a clinic walk-in to an existing account; retain explicit staff customer selection.

## Tasks
- [x] Add focused policy regression coverage in tests/Unit/AccountEmailTest.php and extend EmailVerificationTest, AdminUnregisteredCustomerTest and AdminStaffAccountManagementTest. Confirm rejection tests fail before implementation.
- [x] Extend app/Support/AccountEmail.php with shared rule/message handling and safe normalization; add app/Rules/ValidAccountEmail.php, config/account_email.php, JSON policy and build script. Wire AuthController, three existing Form Requests and staff service/controller consumers. Verify policy and HTTP tests, including unchanged legacy details and OTP boundaries.
- [x] Add scripts/utils/account-email.js and bind current signup, Add Customer, shared walk-in owner, staff create/correct/change/recovery inputs using their existing error nodes/styles. Update existing regression harnesses and obsolete fixtures/assertions. Verify syntax, suggestions, input dismissal, blur revalidation and stale response handling without localhost interactions.
- [x] Update cache versions for changed entry scripts/styles; integrate policy generation into npm build. Verify generated assets and run relevant Node/PHP suites plus production build. Review diff for scope and document deployment regeneration/config cache considerations.
