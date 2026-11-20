# Clinic Manual Treatment Implementation Plan

**Goal:** Save current-visit Manual and inventory treatment charges immediately, preserving legacy history and finalization-only stock consumption.

**Architecture:** Persist `record_source` and `manual_price` on vaccination records. A focused treatment charge service reuses medicine price review and Clinic billing totals for saves and finalization. The existing form, refresh event and shared history presenter remain the UI boundaries.

**Tech stack:** Laravel, MySQL/MariaDB-compatible migrations, Alpine, PHP 8.4 test CLI, Node regressions.

**Spec:** User's agreed Clinic Treatment refactor in this chat. Repository AGENTS.md takes precedence over optional skill execution conventions; implement directly in this checkout without a separate approval or commit workflow.

## Constraints

- New sources: manual (default) and inventory. Legacy non-inventory records remain historical and unbilled.
- Manual price is a final single-treatment amount, decimal 0.01–10000.00 with at most two fractional digits. Existing global money limits remain unchanged.
- Save treatment and source charge atomically; incomplete drafts have no charge. Inventory use occurs only at finalization and price/weight review must run even if a charge exists.
- Keep established form styling, clinical requirements, history relations, payment source of truth, audit and void behavior.

## Files and responsibilities

- New migration: persist/backfill source and nullable Manual price; never create charges during migration.
- VaccinationRecord and AdminVaccinationController: source/price validation, response, transactional save/delete integration and explicit legacy compatibility.
- New ClinicTreatmentChargeService: readiness, authoritative charge synchronization and existing total calculation.
- ClinicTreatmentFinalizer / ClinicMedicineChargeService: share pricing and charge writes; keep consumption at publish boundary.
- ClinicTreatmentPresentation / PetProfileHistoryService: share the staff treatment response, including Manual medicines in patient history.
- admin-vaccinations.js / clinic.html: Manual default, safe money input, full clinical validation, one scoped Billing refresh after successful mutation.
- clinic-record-presentation.js and its existing script consumers: persisted source and Manual price in shared history; update asset versions.
- Existing PHP/Node Clinic tests: replace obsolete entry/timing expectations; preserve legacy fixtures and read regressions.
- Deployment note: migration order, cache/assets, verification and rollback constraints.

## Execution

- [x] Add failing API tests: normal/boundary/invalid Manual prices, immediate charges and totals, incomplete drafts, update/remove, atomic rollback, completed history and unbilled legacy rows. Run focused tests before implementation.
- [x] Add migration and source/price validation. Verify backfill on legacy inventory and external rows and repeat-safe up/down behavior on supported database engines.
- [x] Extract `ClinicTreatmentChargeService::sync(VaccinationRecord, ClinicAppointment)` and reuse authoritative medicine review. Call within create/update/finalize transactions; remove linked charges before draft deletion and recalculate totals.
- [x] Verify inventory immediate charge, no early stock movement, stale price/weight blocks, finalization idempotence, void audit and all-or-nothing rollback.
- [x] Add failing frontend behavior tests for Manual payload/validation/history and one Billing event on successful Add/Update/Remove. Implement using existing form and money input behavior; keep legacy history presentation.
- [x] Update genuinely obsolete fixtures/expectations, run focused then warranted Clinic PHP and Node suites, production build and MySQL schema check. Document actual results and deployment steps.

## Review focus

- An incomplete edit of a charged treatment must remove its charge, and finalization must block.
- Switching source must clear stale inventory/Manual pricing facts without converting historical rows implicitly.
- An existing linked charge must never bypass finalization price/weight review or prevent stock consumption.
- Failed or late UI requests must not refresh the wrong case or issue duplicate Billing loads.
- Migration must not turn legacy rows into Manual charges, including records with missing inventory relationships.
