# Pet breed and fur forms implementation plan

**Goal:** Consistent field indicators, species-specific editable breed suggestions, and catalogue-derived fur requirements across the existing pet workflows.
**Architecture:** Keep the JSON catalogue as the source of truth. Extend the existing shared JavaScript controls and PHP validation rule; keep form-specific requirements and persistence intact.
**Scope:** User task in this chat; no commits, branches, remote changes, or deployment.

1. Update `tests/breed-fur-fields-regression.mjs` and `tests/Unit/BreedCoatCatalogTest.php` first. Exercise empty popular suggestions, filtering, active-descendant keyboard navigation, custom breeds without coats, required named coats, delayed loading and saved edits. Confirm new expectations fail.
2. Update `scripts/components/breed-coat-catalogue.js`, `breed-combobox.js`, and `breed-coat-combobox.js`. Remove generic fallback for custom breeds, filter all matches locally, contain a four-row suggestion viewport, and derive coat requirement without interaction-history flags. Preserve valid saved data and catalogue caching.
3. Update the existing My Pets, admin pet editor, booking, grooming registration and walk-in HTML forms plus `my-pets.js`, `admin-customers.js`, `booking-pet-step.js`, and `walk-in-pet-step.js` where necessary. Add concise indicators, associated errors, and focus handling. Preserve workflow-specific size/species requirements and verification badges.
4. Extend `app/Support/BreedCoatCatalog.php` and `app/Rules/ValidBreedCoat.php` so existing consumers enforce missing and invalid coats, including nested registration payloads. Exercise customer/admin create and edit through `ClientPetProfileTest.php` and read-only legacy access through `AdminPetProfileTest.php`. Confirm existing pet selection does not force historical profile completion.
5. Version changed modules and direct parents in existing page/import references. Build CSS/assets with existing commands. Run focused JS and PHP regressions, then broader checks justified by shared consumers. Verify real browser dropdown geometry, wheel containment, pointer/keyboard behavior and responsive forms if browser tooling is available.

**Review focus:** delayed catalogue arrival must not erase stored coats; repeated modal opening must not duplicate listeners; same-species breed changes preserve only valid coats; species changes clear coats; legacy record viewing/selection stays available; multi-pet rules validate each submitted profile separately.

No database migration or runtime dependency is planned. Existing Bethlehem styling and deployment conventions take priority over optional skill workflow conventions.

**Completed:** Implementation and focused checks are complete. See [verification report](C:/bethlehem-clinic-frontend/docs/pet-form-improvements-2026-10-26.md) for executed checks, the unrelated full-suite email worker memory failure, and release notes.
