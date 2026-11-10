# Confinement Monitoring Validation Implementation Plan

**Goal:** Require three monitoring vitals using Medical Record clinical validation, warnings and observation-specific veterinarian evidence.

**Architecture:** Retain the JSON clinical catalog and its generated JavaScript in `admin-clinic.js`. Share pure vital validation/message/warning helpers between Medical Record and Confinement without moving unrelated workflows. Share backend exception validation/evidence helpers through `ClinicClinicalRules`. Add nullable observation `verification_history` JSON using a new migration.

**Tech stack:** Laravel/PHP, MySQL production and SQLite tests, Alpine/vanilla JavaScript, existing Tailwind classes.

**Spec:** User's confinement-monitoring requirements in this chat; current repository AGENTS.md takes precedence over optional skill execution conventions. Implement in the current checkout without commits or workflow changes.

## Constraints

- Preserve admission, charges, pricing, billing, discharge, history ordering/pagination and Medical Record behavior.
- Require temperature, heart rate and respiratory rate; notes optional, 500 characters.
- Reuse catalog ranges/precision and existing veterinarian identity logic; no extra API calls.
- Each exceptional observation requires fresh value-bound evidence, reason 5–500 characters, server-generated identity/time.
- Preserve request-key retry behavior and session storage; validate both current and pending values.
- Use existing Bethlehem required/error/amber/focus/accessibility patterns; no page redesign.

## Tasks

1. Add failing behavioral regressions in `tests/Feature/ClinicConfinementTest.php` and `tests/clinic-confinement-regression.cjs`. Cover missing/invalid inputs, species warnings, veterinarian reason/value binding, server evidence, fresh observation authorization and retries. Update only old partial-monitoring fixtures intentionally superseded by this requirement.
2. Add `database/migrations/2026_11_10_120002_add_verification_history_to_confinement_observations.php` and the model cast. Extend `ClinicClinicalRules` with shared exception validation/evidence helpers, use them in Medical Record and `ClinicConfinementService::recordObservation()`. Require all vitals, forbid client audit metadata, persist only generated evidence, preserve locked idempotent insertion. Verify focused PHP tests and migration rollback/forward preservation.
3. Extract pure shared vital helpers within `scripts/components/admin-clinic.js`; delegate existing Medical Record methods without behavior changes. Consume helpers in `admin-clinic-confinement.js`, add monitoring error/verification lifecycle, validate current and retained payloads before requests, map server errors and focus first invalid input. Verify focused JavaScript tests including existing Medical Record behavior.
4. Update `pages/admin/clinic.html` Monitoring only: required labels, text numeric inputs, red/amber/error associations, veterinarian reason controls, concise helper and compact exceptional-reading details in history. Bump affected script cache keys. Update `docs/clinic-clinical-validation.md` with deployment migration/evidence behavior.
5. Run relevant confinement, Medical Record, clinic authorization/billing/frontend regressions, clinical generation check and production asset build. Review diff for unrelated changes and report concrete results/limitations.

## Review focus

- Notes-only retained requests cannot bypass new validation or lock users out of correction.
- Medical Record saved verification cannot authorize any new observation, including one with the same value.
- Lost-response retries return the original observation/evidence even after discharge.
- Multiple exceptional fields each retain their own evidence and cannot carry client identities/times.
- New nullable evidence column preserves legacy partial observations and rolls back on SQLite/MySQL-compatible schema operations.

## Verification outcome

Changed files:

- `pages/admin/clinic.html`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-clinic-confinement.js`
- `app/Support/ClinicClinicalRules.php`
- `app/Http/Controllers/AdminClinicController.php`
- `app/Services/ClinicConfinementService.php`
- `app/Models/ClinicConfinementObservation.php`
- `database/migrations/2026_11_10_120002_add_verification_history_to_confinement_observations.php`
- `tests/Feature/ClinicConfinementTest.php`
- `tests/clinic-confinement-regression.cjs`
- `tests/clinic-confinement-vitals-regression.cjs`
- `tests/clinic-confinement-vitals-browser.cjs`
- `docs/clinic-clinical-validation.md`
- `docs/clinic-confinement-workflow.md`
- This implementation plan/verification record.

- PHP 8.4: `ClinicConfinementTest`, `ClinicAdministrationAuthorizationTest`, `ClinicCaseServiceBillingTest`: 329 passed, 3,769 assertions, including migration round-trip, legacy history/pagination and Medical Record verification.
- All 21 clinic/confinement JavaScript regression scripts passed, including the new required-vitals/verification/retry suite.
- `tests/clinic-confinement-vitals-browser.cjs`: passed using isolated headless Edge fixtures, production HTML/scripts and existing CSS. Checked 320/375/414/768px, initial required timing, focused first error, red/amber states, correcting inputs, stale reason removal, exceptional save and compact six-column audit history. No Alpine runtime errors. Screenshot visually inspected.
- `npm run build`: passed; existing Browserslist data-age warning only. Build-time removal of unrelated pre-existing CSS utilities was undone; Monitoring needs no new generated CSS utilities.
- Focused `git diff --check`: passed. No live migration or deployment performed. Apply the additive migration before releasing the updated API.
