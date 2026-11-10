# Undo Confinement Admission Implementation Plan

**Goal:** Reverse an untouched accidental admission atomically, retain an immutable correction reason, and return the case to its active consultation.

**Architecture:** Add `undoAdmission` to `ClinicConfinementService`, protected by the existing clinical permission and appointment-then-episode locks. Bind each request to `confinement_id` so retries/stale tabs cannot undo a new admission. Use existing cancelled case-service tombstones, relation-owned charge deletion and `ClinicBillingService::updateTotal`. Preserve a required correction reason of at most 200 characters in the existing 255-character activity `context`, prefixed `Reason for correction: `; keep `SAFE_CHANGES` unchanged and need no migration. Extend the current feedback confirmation with an optional required-reason mode, retaining boolean behavior for ordinary confirmations.

**Spec:** User's Undo Admission requirements in this chat and current AGENTS.md. Execute directly in the current checkout; optional skill approval/worktree/commit conventions do not apply.

## Files and responsibilities

- `app/Services/ClinicConfinementService.php`: eligibility, locking, reversal, idempotency, audit and refreshed state.
- `app/Http/Controllers/ClinicConfinementController.php`, `routes/api.php`, `scripts/api.js`: eligibility projection and dedicated clinical endpoint/wrapper.
- `app/Services/ActivityRecorder.php`, `app/Support/ActivityPresentation.php`: stable action label and Clinic filter grouping; no global allowlist changes.
- `scripts/components/admin-clinic-confinement.js`, `scripts/components/clinic-feedback.js`, `pages/admin/clinic.html`: compact secondary action, accessible reason confirmation, busy/retry handling and existing case/list refresh events. `scripts/components/admin-clinic.js` merges sparse list refreshes into loaded modal details to retain Medical Record/vital verification data.
- `tests/Feature/ClinicConfinementTest.php`, focused Undo JS/browser regressions: real reversal, restrictions, audit, rollback, stale target and UI interactions. Existing Monitoring tests stay intact.
- `docs/clinic-confinement-workflow.md`: correction policy and deployment notes.

## Tasks

1. Establish the latest baseline with existing confinement/Monitoring tests. Add failing Undo behavioral tests: reverse only base/admission effects; preserve consultation data/unrelated billing; require reason; block care (including tombstones), terminal/financial states and unauthorized actors; allow readmission; prevent stale/duplicate requests; force rollback after mutation/audit.
2. Implement backend service and endpoint. Return `can_undo_admission` from the existing confinement GET; use existence checks for Monitoring/manual care and bulk/eager relation access for cancellation. Require a matching admission ID, preserve audit on deletion, and fail closed if audit recording fails. Verify PHP tests including existing billing/Monitoring/discharge behavior.
3. Add optional reason mode to the current confirmation store/markup, with a 200-character limit, inline errors, focus restoration/trapping and existing destructive styles. Ordinary `confirm` calls continue returning booleans. Add Undo action beside the unchanged green Discharge button; prevent duplicate submissions and retain exact admission/reason on uncertain responses.
4. After Undo, clear only the case's confinement drafts/state, patch the modal using `clinic-case-patch`, and refresh the active/confined lists through existing events without polling/full reload. Preserve in-memory Medical Record edits and all discharge/Monitoring logic. Test rejection, lost response, readmission/stale tabs and case switches.
5. Run focused Undo tests first, then confinement/Monitoring/Medical Record, activity-log and clinic billing/authorization regressions, browser checks and production build. Review generated CSS changes and keep the diff scoped.

## Review focus

- Old Undo requests must never target a newly admitted episode.
- Removed additional-charge links still permanently block Undo.
- Correction reason must survive global change allowlisting and episode deletion, including Unicode boundary input.
- All mutators acquire the appointment lock first; care/discharge committed before Undo obtains its lock must block reversal.
- Failures after charge/service/total changes or audit insertion must roll back the entire correction.

## Verification outcome

- Latest branch checked by fetching `backend/gerald-frontend`; initial HEAD matched the remote tip (`a31b9ed`) and working tree was clean.
- Baseline: all 38 confinement PHP tests (653 assertions) and existing confinement/Monitoring JavaScript regressions passed before implementation.
- Final PHP: 354 tests passed, 4,258 assertions across `ClinicConfinementTest`, `ActivityLogTest`, `ClinicAdministrationAuthorizationTest` and `ClinicCaseServiceBillingTest`.
- Six true overlapping SQLite writer tests passed: Monitoring/charge/discharge before Undo, Undo before Monitoring/charge, and simultaneous duplicate Undo. Each proved lock contention using a second SQLite writer probe against an owned disposable database.
- All 23 relevant clinic/confinement/activity-log JavaScript regression scripts passed. The new Undo regression also verifies cancellation, reasons/Unicode limits, exact retained retry, reload after a lost response, stale readmission targets, busy guards, case switches, event patches and loaded Medical Record preservation.
- Isolated headless Edge Undo UI test passed at 320/375/414/768px: required reason, immediate error clearing, focus trap/restoration, busy state, duplicate protection, success state and preserved Discharge action. Screenshot visually inspected. Existing Monitoring browser test passed unchanged with no Alpine runtime errors.
- `npm run build` passed with the existing Browserslist data-age warning. Unrelated generated CSS utility removals were restored; no new stylesheet is needed.
- `git diff --check` passed. No migration, live database change or deployment was performed. Existing schema prerequisites remain unchanged; use the normal deployment/cache-refresh process.
