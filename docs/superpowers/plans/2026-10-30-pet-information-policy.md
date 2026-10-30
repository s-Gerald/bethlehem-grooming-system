# Pet Information Policy Implementation Plan

**Goal:** Permit customer completion of each blank pet field once, preserve clinic authority, and notify owners of persisted staff corrections.

**Architecture:** Reuse the Pet model's atomic save boundary for a shared field policy and staff change notifications. Keep registration references read-only, and extend the existing catalogue and comboboxes. One nullable JSON column retains lifetime field locks after staff clears a value; no new delivery channels.

**Tech Stack:** Laravel, existing vanilla JavaScript controls, PHPUnit and Node regressions.

**Spec:** User task in this chat, sections 1–13.

## Constraints

- Preserve identifiers, histories, verification, pricing thresholds, intake notes, caches and authorization.
- Existing Bethlehem styling; concise copy and Confirm labels; no new confirmation dialog.
- No push, deployment, production data writes, dependencies. A nullable JSON migration is necessary for lifetime per-field locks; no backfill or record reset.

## Tasks

- [x] Update ClientPetProfileTest and BreedCoatCatalogTest to reproduce optional coats, required species, blank completion, API bypass protection, paired verification and staff notifications; run red.
- [x] Add shared PetInformation policy and atomic model save integration. Remove customer verification overrides and duplicate notification writers. Verify stale model submissions, no-op updates and transaction rollback.
- [x] Preserve saved profiles in BookingController; validate ownership and new-pet species. Combine clinic walk-in profile/size saves into one operation. Verify grooming and clinic registration regressions.
- [x] Extend shared breed/coat catalogue and controls with optional fur, explicit choices and Unknown Fur Type last; preserve legacy values and a single cached catalogue fetch. Update existing Node tests and run.
- [x] Update my-pets.js and existing customer/admin HTML forms in place: per-field locking, accessible lock indicators, concise guidance, warning, Confirm and unchanged-submit prevention. Preserve modal focus and archive confirmations.
- [x] Extend existing notification formatter safely for pet and field emphasis. Keep retrieval/read/cache behavior.
- [x] Update obsolete interface assertions, version all affected asset entry points/imports, run relevant broader PHP/Node suites and production build. Review diff and document deployment requirements.

## Review focus

- Stale/concurrent updates must compare against a row locked inside the transaction.
- Unchanged legacy coat/color/weight/species values must not fail modern creation validation.
- Weight completion cannot overwrite an existing size or clinic verification.
- One persisted staff profile operation must produce one notification; creation and unrelated clinical records produce none.
- Selecting an existing profile must preserve its data and use its authoritative grooming size.
