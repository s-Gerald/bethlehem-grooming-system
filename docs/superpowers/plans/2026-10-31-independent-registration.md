# Independent Online Registration Implementation Plan

**Goal:** Permit one active registration per service per customer account.

**Architecture:** Keep CustomerPreRegistrationAccessService authoritative, returning both service decisions and an overall OR. Controllers select their own service inside existing locks; selector and form guards consume the same response. Preserve dashboard rendering and cache invalidation.

**Tech Stack:** Laravel/PHP, native JavaScript modules, PHPUnit, Node regression harnesses.

**Spec:** User attachment `C:/Users/Gerald/.codex/attachments/f1fd0000-cbb5-410e-8151-718cf7c50c50/Pasted text.txt`.

## Constraints and review focus

- No commits, pushes, migrations, new polling, or unrelated redesigns.
- Only the existing client browser tab; manual navigation, input, OTP and resets belong to the user.
- Terminal statuses release access, including same-date records.
- Account-level checks must cover different pets/dates; opposite-service visits must never block.
- Keep existing transaction/lock ordering, availability, ownership and phone checks.
- Preserve confirmations, restored-page rechecks, keyboard tabs and cached dashboard loading.

## Steps

- [x] Update CustomerPreRegistrationAccessTest and existing ClinicAdministrationAuthorizationTest cases first; run failing focused PHPUnit checks.
- [x] Refactor the service response and controller service selection; remove redundant same-date checks. Verify focused submission tests and expiry tests.
- [x] Extend online-registration-selector-regression.mjs and grooming-flow-regression.cjs with the four eligibility combinations and restored-page service checks; verify failures.
- [x] Update pre-registration-options.js, booking-form-access-guard.js, booking-pet-step.js and grooming-pre-registration.js; add the existing Phosphor warning icon in pre-register.html. Keep customer-pre-registration-button.js on overall OR eligibility.
- [x] Extend customer-dashboard-history-regression.cjs to exercise simultaneous cards, tracker separation and dashboard entry eligibility. Verify cache invalidation using customer-data-cache-regression.cjs.
- [x] Update asset query versions for changed modules and entry points, rebuild CSS/production assets as needed, run relevant PHPUnit/frontend regressions and inspect final diff.

Execution proceeds in this session under the user's implementation request and AGENTS.md advisory planning guidance; no extra approval or workflow changes are needed.
