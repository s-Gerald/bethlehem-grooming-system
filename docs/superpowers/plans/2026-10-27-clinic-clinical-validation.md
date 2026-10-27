# Clinic clinical validation implementation plan

**Goal:** Preserve the consultation workflow while adding realistic vital guardrails, immediate error correction, and structured administered doses.

**Architecture:** A small JSON catalog supplies PHP validation and a generated block in the existing clinic script (no new browser request). Existing controllers and finalizer remain authoritative. A nullable JSON column on clinic vitals retains an append-only verification history; existing activity logging continues to record medical changes.

**Scope:** Medical Record vitals and the shared Vaccine/Medicine treatment form. Preserve local uncommitted changes, pet-size pricing, physical stock quantities, charges, permissions, and payment behavior. No commits or deployment.

## Tasks

1. Extend existing medical and treatment regressions and API tests, run them red against current code. Cover strict numeric precision, paired dose fields, whole counts, legacy units, correction during input, and vet-only exceptions.
2. Add `resources/data/clinic-clinical-rules.json`, `scripts/build-clinic-clinical-rules.mjs`, and `app/Support/ClinicClinicalRules.php`. Generate the browser catalog into `scripts/components/admin-clinic.js`; use existing pet-weight helpers unchanged.
3. Update `AdminClinicController`, `ClinicAppointment`, and `ClinicVital`; add a nullable `verification_history` JSON migration. Accept standard entries or an explicit veterinarian reason for storage-compatible exceptions. Bind verification to the exact original value, retain author/time/reason, and preserve previously verified unchanged readings. Revalidate on completion.
4. Update `AdminVaccinationController` and `ClinicTreatmentFinalizer` for strict decimal amounts, paired units, the standardized catalog and Other text (30 characters), integer capsules/drops/puffs/doses, and required administered dose before publishing inventory treatments. Preserve legacy draft units exactly and keep external historical documentation compatible.
5. Update the two existing Alpine components and `pages/admin/clinic.html`: five-field vital grid, species warnings, compact exception reason, native unit selector and conditional custom unit. Blur/save introduces errors; input only resolves existing errors and summaries. Preserve raw numeric strings through API submission.
6. Run affected Node/PHP regressions, inventory/billing/payment/authorization checks, production build, and inspect the updated UI without manual functional data entry. Document deployment and limitations.

## Verification focus

- Explicit zero pulse/breathing requires veterinarian verification; staff/admin cannot create verification.
- Editing a verified value invalidates its previous verification; unrelated edits retain original attribution.
- Excess decimal places and exponent/nonfinite formats fail before casts can round.
- Selecting a unit reevaluates the amount (and custom unit) without introducing errors during incomplete typing.
- Legacy units remain byte-for-byte unchanged unless explicitly replaced; physical inventory quantity never derives from dose.
- Historical missing doses remain readable; incomplete inventory drafts cannot publish until dose documentation is complete.

## References and decisions

- Merck: https://www.merckvetmanual.com/multimedia/table/normal-rectal-temperature-ranges (Dog 37.5–39.2°C, Cat 38.1–39.2°C).
- Guelph: https://books.lib.uoguelph.ca/vetm3430/chapter/vital-parameters/ (heart/respiratory intervals; context affects readings).
- Merck emergency triage and AAHA hypothermia guidance confirm that extreme/zero readings need an emergency documentation path. The requested 25–45°C, 1–400 bpm and 1–200 breaths/min bounds are entry sanity limits, not validated physiological or safety cutoffs.
- Baymard inline validation, NN/g form error guidelines, W3C ARIA21, and GOV.UK validation guidance: local error messages, preserve input, clear corrected errors immediately, no premature invalid state.
- There is no approved product-specific dose data; require structural correctness and clinician review without claiming a dose is safe.
