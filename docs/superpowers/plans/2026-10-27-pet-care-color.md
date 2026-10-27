# Pet care notes and Color implementation plan

**Goal:** Consistent care-note wording and an editable Color autocomplete across every existing pet form, preserving records and eligibility rules.

**Architecture:** Share one static, locally filtered Color combobox between My Pets, online registration, admin Customers, and both walk-in flows. Share a PHP validation rule that normalizes new descriptions and preserves unchanged legacy colors against already-loaded pets, without extra queries.

**Constraints:** Keep `color` and `medical_conditions`; 50 and 1,000 character limits; existing authorization, eligibility, caching, and clinical records. No migration, dependency, commit, branch, push, or deployment.

## Files and verification

1. Add behavioral Color component regression tests; extend ClientPetProfileTest and existing grooming/walk-in persistence tests. Verify rejection, normalization, optional values, legacy preservation, and no unauthorized edits before implementation.
2. Create `scripts/components/color-combobox.js` and `app/Rules/ValidPetColor.php`. Update `css/custom.css`, `PetController`, `BookingController`, `StoreWalkinRequest`, `StoreClinicWalkinRequest`, `WalkinController`, and `ClinicWalkinController`. Verify keyboard/mouse/touch behavior, local filtering, four 40px rows, contained scrolling, unclipped modal dropdowns, and backend validation.
3. Update `my-pets.js`, `booking-pet-step.js`, `admin-customers.js`, `walk-in-pet-step.js`, both walk-in consent serializers, and their existing HTML forms. Add Color to walk-in draft read/write/object mapping and review. Verify existing saved values, draft restoration, reset/reopen, custom entry, inline errors, and unchanged Breed/Fur Type behavior.
4. Update shared-profile wording in customer pet details, admin Customers, clinic patient profile, booking cards, and clinic summaries. Remove misleading warning styling only for the shared profile notes; preserve diagnosis/history sections.
5. Update directly affected existing assertions and version affected frontend dependency chains. Run frontend regressions, affected PHP feature suites, production build, and browser layout checks when available. Inspect final diff and document deployment considerations.

## Review focus

- Saved invalid legacy colors: exact unchanged value remains saveable; new invalid values fail.
- Input, paste, autofill, and programmatic submissions: reject digits without deleting remaining valid text; backend rejects bypasses.
- Repeated opens and admin form reuse: no duplicate initialization or listeners; selecting an option updates Alpine state.
- Modal and mobile positioning: dropdown stays aligned and scrollable without moving the page.
- Omitted profile fields and historical clinical information: preserve existing data, keep existing eligibility logic.
