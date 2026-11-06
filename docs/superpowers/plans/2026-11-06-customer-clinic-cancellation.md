# Customer Clinic Cancellation Implementation Plan

**Goal:** Allow customers to cancel eligible online Clinic registrations through the same confirmation used by Grooming.

**Architecture:** Keep one Clinic eligibility method on `ClinicAppointment`, consumed by the customer details and locked cancellation operations in `ClinicWalkinController`. Extend the existing visit summary and dashboard confirmation with a service-aware target; reuse customer cache invalidation and the existing toast component.

**Tech Stack:** Laravel/PHP 8.4, Eloquent transactions, vanilla JavaScript modules, native dialogs, PHPUnit and existing Node regression harnesses.

**Spec:** `C:/Users/Gerald/.codex/attachments/3ba0e1aa-1ea8-4ec8-90b0-a95ddced0b9b/Pasted text.txt`

## Constraints and review focus

- Preserve Grooming cancellation rules/limits, staff endpoints, printing, blue styling and Phosphor icons.
- Require customer ownership, online registration and untouched operational/payment state; recheck persisted state under the same settings/appointment lock order as intake and expiry.
- Use exact `Cancel`, service-specific confirmation titles, `Confirm cancellation`, `Cancelling...` and success wording.
- Repeated or stale requests must not change an operational visit or duplicate activity/notifications.
- Keep nested-dialog focus inside the active dialog and restore to a connected control after cards disappear.
- Invalidate bookings/access caches and refresh details after errors without new polling or page reloads.

## Tasks

1. Extend `tests/Feature/ClinicAdministrationAuthorizationTest.php` with customer success, ownership/role denial, operational/payment guards, stale check-in/expiry, duplicate submission and eligibility synchronization cases. Run them red. Add model eligibility and owner-scoped cancellation route/controller operation; rerun relevant Clinic/Grooming/access tests green.
2. Extend `tests/customer-data-cache-regression.cjs` for the authenticated Clinic cancellation method and bookings/access invalidation; run red, then update `scripts/api.js` and verify green.
3. Extend `tests/customer-grooming-visit-summary-regression.mjs` and `tests/customer-dashboard-history-regression.cjs` for both service targets, shared confirmation, explicit confirmation, retry/loading/error states, stale targets, success refresh and nested focus. Run red; update `scripts/components/grooming-visit-summary.js`, `scripts/components/client-dashboard.js`, `pages/client/dashboard.html`, and the existing pre-registration button refresh hook. Verify green.
4. Run the affected regressions and PHP suites. Inspect desktop/mobile dialogs with fixture-only browser tests, preserve single-role-tab usage, and verify asset cache keys, syntax and diff scope. No commits, deployment, migrations against local application data, or manual data/OTP/password workflows.

## Verification completed

- Affected PHP suite: 327 tests, 3,013 assertions passed. Follow-up coverage of the shared unresolved-payment scope: 7 clinical/payment-lock cases passed, including pending, settled, and reconciliation-required payments.
- Customer dashboard, saved visit summary, customer data cache, and Clinic submission Node regressions passed.
- The dashboard regression's optional `--browser` mode passed with one isolated customer tab and mocked APIs: exact copy, both services sharing numeric ID 17, dismissals/focus, conflict recovery, duplicate prevention, success refresh, and 320/375/414/768/1440-pixel dialog checks.
- Desktop/mobile screenshots were inspected. JavaScript syntax and `git diff --check` passed. Existing staff Clinic cancellation and Grooming count/payment safeguards remained covered; earlier admin-label work was preserved.
