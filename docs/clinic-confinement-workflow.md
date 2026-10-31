# Clinic confinement workflow

`ClinicAppointment` remains the parent of Medical Record, saved Vitals, Treatment, Services Performed, Confinement, Billing and Payment. A unique `clinic_confinements.clinic_appointment_id` allows one episode per visit. Readmission uses a new case. Patient and owner information stays on existing records.

Confinement is an episode, not another appointment status. `admitted_at` with no `discharged_at` means active confinement while the case remains `in_consultation`. The Clinic page's paginated Confined list and count use this relationship; Open uses the existing case workspace. Duration refreshes for display only.

## Admission and care

The intended catalogue policy is Professional Fee as the sole active Admission Charge and IV Insertion as an optional Additional Charge with the Per service unit. Prices and pricing methods remain catalogue settings; neither service name is special-cased by runtime billing or selection.

Clinic Services is the pricing source of truth. Confinement services have a structured `charge_behavior`: `base_confinement`, `admission_charge`, or `additional_charge`. Existing rows are not classified by editable names; an admin must configure their behavior. The catalogue enforces one active Base Confinement and automatically sets Tiered / Weight / Day. Small, Medium and Large each require a positive, increasing `max_weight_kg`; Extra Large has no upper limit. All four prices are required. Cutoffs are inclusive: each next tier starts strictly above the previous cutoff, without gaps or overlaps. No clinic cutoffs or prices are supplied by default.

Admission requires clinical permission, an ongoing unpaid/unarchived case and the saved Medical Record's Primary Attending Veterinarian. Staff enter only a reason and optional notes. The server selects the single active Base Confinement, validates its tiers, and resolves saved case Vitals weight through `ClinicServicePriceResolver` / `ConfinementWeightPricing`, independently of species and grooming size rules. Missing/ambiguous configuration or invalid weight blocks admission with an actionable message. The UI previews Medical Record edits and saves the admission prerequisites before admitting.

Admission creates one ordered base `ClinicCaseService`, with the retained UUID request key, and no base charge. Its `applied_price` and `service_snapshot` retain the admission daily rate, recorded weight and determined tier immediately. All active Admission Charges are added once automatically and displayed separately from Additional Confinement Charges. Fixed/tiered admission charges are finalized immediately; Range/Starting At charges remain pending for their final price at discharge. The episode preserves its base rate and service snapshot after catalogue edits/deactivation or later weight changes.

`clinic_confinement_observations` stores append-only chronological observations with server time, recording user and a unique request key. Temperature, heart rate, respiratory rate and notes are optional individually, but at least one value is required. Monitoring neither replaces saved `ClinicVital` values nor creates stock movements or charges.

Care continues to use the existing Treatment, Medical Record and Billing paths. The Additional Charges selector contains only active Confinement / Additional Charge services. None/service units are one-time charges with quantity 1; Day units start at server time and remain pending until Stop or discharge. A stopped daily charge can be started again as a new occurrence. An already running occurrence cannot be duplicated. Staff never supply duration or quantity.

`clinic_confinement_charges` links each automatic/ad hoc charge to its existing `ClinicCaseService`, retaining behavior and start/stop timestamps. Removal cancels the source, removes its generated bill line, and retains a tombstone so a retried creation cannot recreate it. Admission charges and confinement sources cannot be changed through ordinary Services Performed. Monitoring remains optional.

## Discharge

Discharge locks the case and episode, checks eligibility and existing completion requirements, calculates the server discharge timestamp and final quantity, automatically stops all running daily extras at that same timestamp, then performs all pending confinement services through `ClinicCaseServiceRecorder` and `ClinicServicePriceResolver`.

- Billing unit `day`: `max(1, ceil(elapsed_seconds / 86400))`. Six hours and exactly 24 hours each bill one day; 24 hours plus one minute bills two days. Calendar dates do not determine quantity.
- Additional Charge with unset/service unit and Admission Charge: quantity 1.
- Fixed: exact catalogue price, no manual input.
- Base confinement: admission rate and snapshot, with no manual tier/price or re-resolution at discharge. Other tiered charges retain their existing resolution behavior.
- Range/Starting At: final applied unit price required, using the existing catalogue bounds. Billable quantity remains automatic.

Each finalization creates one uniquely linked charge through the existing recorder/resolver, updates the case total, retains the performance snapshot, marks the episode discharged and calls existing `prepareForPayment()`. The same case moves to `for_payment`, with the existing payment-due behavior. A failure rolls back all these effects. Ordinary Complete/Finish actions fail while an episode remains active, including Treatment's Save and Finish path.

Discharged episodes and monitoring remain visible in the case; active Confined lists exclude them. Catalogue changes and subsequent payment do not reprice finalized charges. Pending extras use the associated catalogue service at finalization, including if it was deactivated after it was started; they never switch to a replacement service. The base always uses its admission snapshot. Price/method/tier/weight snapshots remain on the existing case-service record.

## Permissions and retries

Admins and veterinarians can admit, record monitoring, treat and discharge. Clinic receptionists can view confinement, monitoring and clinical information, review Billing and record Payment at the existing payment stage. They cannot perform clinical mutations. Veterinarians retain Billing permission and have no payment-recording permission.

Case locks serialize admission/discharge against clinical and payment writes. Unique episode, source-charge and treatment stock-movement links prevent duplicates. Admission and monitoring retries reuse their UUIDs; a discharged episode returns its historical result on repeated discharge. The UI retains exact unconfirmed requests in session storage scoped by user/case, disables competing actions during saving and offers a safe retry after an uncertain response. No daily charge job, second invoice or copied Billing records are created.

## API

- `GET /api/admin/clinic-confinements`: paginated active confined cases (Clinic access).
- `GET /api/admin/clinic-confinement-service-options`: active Additional Charge options only (clinical).
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement`: view / admit.
- `GET /api/admin/clinic-cases/{id}/confinement/admission-preview`: automatically selected base and resolved rate.
- `POST /api/admin/clinic-cases/{id}/confinement/charges`: add an optional charge using a retained UUID.
- `POST /api/admin/clinic-cases/{id}/confinement/charges/{chargeId}/stop`: finalize a running daily charge.
- `DELETE /api/admin/clinic-cases/{id}/confinement/charges/{chargeId}`: remove an extra while the case is editable.
- `GET` / `POST /api/admin/clinic-cases/{id}/confinement/observations`: paginated history / record.
- `GET /api/admin/clinic-cases/{id}/confinement/discharge-preview`: saved base rate, pending extra pricing and read-only derived quantity (clinical).
- `POST /api/admin/clinic-cases/{id}/confinement/discharge`: finalize and send to Billing (clinical).

Read endpoints require Clinic access; write endpoints require existing clinical permission. Billing and Payment retain their existing separate permissions.

Apply `2026_10_14_000002_configure_confinement_charges.php` before serving the updated workspace. It adds nullable catalogue behavior and confinement-charge links without classifying services by name or changing historical bills. Configure existing Confinement catalogue entries explicitly; no prices are embedded in the workflow.

Apply `2026_10_15_000001_snapshot_active_confinement_rates.php` before editing existing Base Confinement pricing. Legacy active episodes lack an admission snapshot; this migration freezes their currently available rate and weight once, using their existing legacy rules. It cannot recover an unstored original admission rate. Invalid legacy pricing or weight fails the migration for correction instead of guessing. Existing snapshots and finalized bills are retained, including on rollback. Configure clinic cutoffs before any new admissions.

## IV Insertion policy deployment (2026-10-31)

The local catalogue audit found Professional Fee and IV Insertion as the only active admission charges. Professional Fee was already configured correctly. This audit does not establish the production catalogue state.

1. Before resuming admissions, review the actual production Clinic Services / Confinement catalogue. Keep Professional Fee active with Admission Charge behavior and its existing pricing. Identify any other active Admission Charge entries besides Professional Fee and IV Insertion; resolve their intended behavior explicitly with the clinic administrator. This release does not silently reclassify unrelated services.
2. Through the established Hostinger release process, run the reviewed data migration with the hosting PHP CLI from the release directory:

   ```sh
   php artisan migrate --force --path=database/migrations/2026_10_31_000002_make_iv_insertion_an_additional_confinement_charge.php
   ```

   Earlier schema migrations must already be applied. The migration matches the normalized name IV Insertion only within Confinement, including inactive rows when checking uniqueness. It changes only the existing record's behavior to `additional_charge` and billing unit to `service`; it preserves prices, pricing method, activation, timestamps and all transaction tables. Repeated execution has the same result. Missing records are left absent and produce a Laravel log warning. Ambiguous matches, unexpected behavior or a unit other than None/Per service stop the migration for administrator review without changing any records. Correct or positively identify the actual existing record in Clinic Services before rerunning; do not guess IDs or create a duplicate. If the service was renamed or is absent, the administrator must configure the intended actual record separately. Deployment alone cannot fix such a missing match.
3. Verify the production catalogue: Professional Fee is the only active Admission Charge; IV Insertion is active, Additional Charge, Per service, with its previous pricing method and price. Refresh an already-open workspace's cached options by reopening Patient Record after the release. No frontend rebuild or new environment setting is needed for this change.
4. In the clinic's authorized verification workflow, admit a new patient: only Professional Fee appears under Admission Charges. Check IV Insertion in the existing Add Charge dropdown, add it once, and verify it appears under Additional Confinement Charges at the configured price. Verify removal and discharge totals (base daily amount + Professional Fee + retained manual charges). The automated suite covers request retries, removal permissions, totals and migration preservation; staff perform manual production verification.

Existing active episodes retain their originally recorded IV Insertion admission charges and snapshots. Retrying admission returns that episode without adding charges. Do not add IV Insertion again merely to move its historical entry to the new section. Completed/paid bills and payments are untouched. The migration's rollback intentionally retains the manual-charge catalogue policy; any future policy reversal requires an explicit catalogue decision and must preserve existing charges.
