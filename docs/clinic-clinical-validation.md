# Consultation vitals and administered dose validation

## Behavior

The existing Patient Record modal, five-column Vitals grid, treatment source selection, physical inventory quantities, weight pricing, charges, and payment workflow are preserved.

- Weight uses the existing `ValidPetWeight` / `PetWeightSize` and browser helper: greater than zero, at most 155.6 kg, at most two decimals. Size classifications are unchanged.
- Ordinary vital entries: temperature 25–45°C with one decimal; heart rate 1–400 bpm and respiratory rate 1–200 breaths/min, both integers. Values are validated before conversion or database casts; submitted text is never rounded or clamped.
- Species warnings use existing case data. Dog reference intervals are 37.5–39.2°C, 60–160 bpm, 10–30 breaths/min; Cat intervals are 38.1–39.2°C, 120–240 bpm, 24–42 breaths/min. Unknown species has no invented reference range. Abnormal but structurally valid readings remain saveable.
- A veterinarian can verify a storage-compatible exceptional temperature/rate, including a zero pulse or breathing rate, with an explicit 5–500 character clinical reason. Verification is bound to the entered value; changing it requires new verification. Administrators and receptionists cannot create a verification. An unchanged verified Medical Record measurement retains its original veterinarian attribution during later edits.
- A nullable `clinic_vitals.verification_history` JSON column retains an append-only list of original values, field, reason, veterinarian ID/name, and time. The existing activity observer records the clinical change without copying private medical content into the general activity feed. The Medical Record has a compact, collapsed verification-history disclosure.
- Blur/save introduces field errors; typing resolves existing errors immediately without interrupting incomplete numeric entry. Correcting a dose unit reevaluates the amount/unit pair and custom text. Inline text, borders, accessibility invalid state, and summaries share the same error state.
- Visit-finalization errors identify both treatment ID and field. The UI opens the affected draft for correction.

## Confinement monitoring (2026-11-10)

Record Monitoring requires all three measurements for every new observation. It shares the generated `CLINIC_CLINICAL_RULES` catalog and pure vital validation/message/warning helpers in `admin-clinic.js` with Medical Record; backend validation uses `ClinicClinicalRules`, including shared exception validation and server evidence construction. Existing case species supplies warning intervals without another request. Required errors appear on save, populated invalid fields validate on blur, and correcting an error clears it during input.

Every exceptional monitoring measurement needs fresh veterinarian evidence for that exact value. Medical Record saved verification is never consulted. The observation response/history includes its own `verification_history`; normal/legacy observations have null history. Request-key retries return the original observation/evidence without appending another entry, including after discharge. The client retains value-bound reasons with its existing session-storage draft/pending request and revalidates both the current form and pending payload before retrying.

Deploy `2026_11_10_120002_add_verification_history_to_confinement_observations.php` with `php artisan migrate --force` before serving the updated API. It adds one nullable JSON column using Laravel schema operations compatible with MySQL and SQLite. No historical migration, observations or clinical ranges are rewritten. Preserve recorded evidence before any rollback. Deploy the updated static clinic page and both component scripts (cache key `monitoring-validation=20261110-v1`), PHP files and normal build artifacts through the existing release process.

Verification: 329 PHP tests passed (3,769 assertions) across confinement, clinic authorization/Medical Record and service billing; all 21 clinic/confinement JavaScript regression scripts passed; the isolated Edge Monitoring browser test passed at 320/375/414/768px with no Alpine runtime errors; `npm run build` passed (existing Browserslist data-age warning). Migration round-trip and legacy history/pagination were tested on SQLite. No live migration or deployment was performed for this change.

## Dose units and numeric rules

Vaccine and Medicine share one native selector: mL, mg, mcg, g, International units, Tablet(s), Capsule(s), Drop(s), Puff(s), Dose(s), and Other — specify. The stored standard values are `mL`, `mg`, `mcg`, `g`, `international units`, `tablet`, `capsule`, `drop`, `puff`, `dose`; Other stores the specified text (maximum 30 characters). Recognized values entered through Other normalize to their canonical spelling. The medication-safety abbreviation IU is not offered for new entries.

Amounts must be finite, positive decimal text with up to three decimals, from 0.001 through the existing decimal(8,3) storage ceiling of 99,999.999. Exponent/hex/nonfinite formats and excess precision are rejected. Capsules, drops, puffs, and doses require integer counts; tablets, liquids, and mass units allow supported fractions. Amount and unit must be supplied together. Drafts may omit both; Inventory treatments need both before publication/finalization. Historical external entries can retain missing documentation without fabricated doses.

Legacy units remain unchanged when editing or displaying existing records, including legacy `ml`, `IU`, arbitrary text, and the literal `other`. Selecting Other for a new entry requires Specify Dose Unit. Published historical records are not rewritten.

No reliable product-specific dose tables exist in the affected workflow. The numeric ceiling is a storage limit, not a clinically safe dose. Final confirmation asks the clinician to check administered doses against the prescription and product concentration; no drug dose is inferred or recommended. Physical stock usage continues to come exclusively from `inventory_quantity`.

## Files

Backend: `app/Http/Controllers/AdminClinicController.php`, `app/Http/Controllers/AdminVaccinationController.php`, `app/Support/ClinicClinicalRules.php`, `app/Models/ClinicVital.php`, `app/Models/ClinicAppointment.php`, `app/Services/ClinicTreatmentFinalizer.php`.

Frontend/catalog/build: `pages/admin/clinic.html`, `scripts/components/admin-clinic.js`, `scripts/components/admin-vaccinations.js`, `resources/data/clinic-clinical-rules.json`, `scripts/build-clinic-clinical-rules.mjs`, `css/output.css`, `package.json`.

Migration: `database/migrations/2026_10_27_000001_add_clinic_vital_verification_history.php`.

Existing tests updated: `tests/admin-clinic-medical-record-regression.cjs`, `tests/admin-clinic-vaccination-reconciliation-regression.cjs`, `tests/admin-clinic-record-workflow-regression.cjs`, `tests/clinic-inventory-pricing-regression.cjs`, `tests/Feature/AdminVaccinationApiTest.php`, `tests/Feature/AdminVaccinationInterfaceTest.php`, `tests/Feature/ClinicAdministrationAuthorizationTest.php`.

## Verification and deployment

- `npm test`: all 83 regression scripts passed. The later field-specific finalization correction test also passed in `node tests/admin-clinic-record-workflow-regression.cjs`.
- PHP 8.4 `artisan test --compact --filter='AdminVaccinationApiTest|ClinicAdministrationAuthorizationTest|ClinicInventoryPricingTest|AdminVaccinationInterfaceTest|PetWeightSizeTest|ClinicCaseServiceBillingTest|ActivityLogTest'`: 383 passed, 4,836 assertions.
- `npm run build`: passed. The existing Browserslist data-age warning remains.
- Localhost browser work is visual inspection only. Manual navigation/data entry, OTP, and password-reset testing remain with the user.

Before release, run `npm ci` when dependencies need installation, then `npm run build`. Build runs `build:clinical` to regenerate the catalog block in the existing clinic script, adding no browser requests or tab-open fetches. Deploy the source JSON as well as the generated static JS, HTML, compiled CSS, Laravel source, migration, and normal `public/build` artifacts. Vite does not bundle the static clinic page/scripts. Their affected URLs include `clinical-validation=20261027-v1`.

Apply the additive migration with `php artisan migrate --force` through the existing Hostinger deployment process, before serving the updated exception-writing code. Existing records remain null in the new column; there is no data rewrite or backfill. Use the release process's usual Laravel cache refresh (`php artisan optimize:clear` and production cache commands as applicable). Do not roll back the column after real verifications have been recorded without preserving that history.

The new migration was applied successfully to the confirmed local MySQL database using only its explicit migration path. No other pending migrations or Hostinger settings were changed.

## References and clinical limits

- [Merck temperature ranges](https://www.merckvetmanual.com/multimedia/table/normal-rectal-temperature-ranges).
- [University of Guelph vital parameters](https://books.lib.uoguelph.ca/vetm3430/chapter/vital-parameters/): heart/respiratory intervals and contextual variation.
- [Merck emergency triage](https://www.merckvetmanual.com/emergency-medicine-and-critical-care/evaluation-and-initial-treatment-of-small-animal-emergency-patients/initial-triage-and-resuscitation-of-small-animal-emergency-patients) and [AAHA hypothermia](https://www.aaha.org/resources/2020-aaha-anesthesia-and-monitoring-guidelines-for-dogs-and-cats/troubleshooting-anesthetic-complications/hypothermia/) support preserving abnormal/emergency observations. The requested wider entry limits are application guardrails, not published veterinary rejection boundaries or a clinical endorsement. Exception measurements still need to fit the existing database representation (0–999.9°C, 0–65,535 whole rates); 100,000 bpm remains invalid.
- [Baymard inline validation](https://baymard.com/research-articles/inline-form-validation), [NN/g form error guidelines](https://www.nngroup.com/articles/errors-forms-design-guidelines/), [W3C ARIA21](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA21), [GOV.UK validation](https://design-system.service.gov.uk/patterns/validation/).
