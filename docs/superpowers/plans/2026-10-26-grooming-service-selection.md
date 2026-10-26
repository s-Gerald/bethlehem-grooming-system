# Grooming Service Selection Implementation Plan

**Goal:** Give online and walk-in grooming the same concise per-pet service hierarchy, secure haircut requests, and catalogue-driven inclusions.

**Architecture:** Keep each flow's navigation, authorization, fetching and draft ownership. Share pet-card rendering and conditional haircut/instruction behavior. Validate both submission shapes through one backend service using the loaded authoritative catalogue; retain the operational estimate engine and admission checks.

**Tech Stack:** Existing JavaScript modules, Tailwind/CSS, Laravel, PHPUnit and Node regression scripts.

**Spec:** User attachment `C:/Users/Gerald/.codex/attachments/7a105e5a-ad22-47bc-ac83-2ab382498ecc/Pasted text.txt`.

## Constraints

- Preserve Bethlehem styling, price semantics, groomer defaults and network request count.
- No scheduling, queue or system-wide estimation changes; no production mutation or deployment.
- Store `haircut_requested` on booking pets, default false; never infer from notes.
- Migrate targeted inclusion IDs additively, preserving prices, statuses, snapshots and history.

## Tasks

- [x] Extend existing catalogue and submission tests for required instructions, valid cat flags, duplicate charges and additive migration; observe failures.
- [x] Update catalogue defaults and add forward migrations for Facial Trimming inclusions and visit haircut boolean. Verify idempotence and preservation.
- [x] Share backend selection validation between BookingController and WalkinController; ignore legacy walk-in factor input while retaining internal duration snapshots and capacity checks. Verify both submission shapes.
- [x] Share service-card rendering and haircut controls; keep duration review rendering separate. Remove redundant panels, add accessible per-pet instructions/focus, target card updates. Verify interaction and state restoration.
- [x] Carry haircut flags through drafts, review, consent and staff visit data. Update affected tests and cache versions.
- [x] Run relevant Node/PHP regressions, capacity checks, asset build and desktop/mobile browser verification where available. Record deployment requirements and limitations.

## Verification outcome

81/81 frontend regression scripts passed. The affected PHP run completed 429 tests and 5,557 assertions, with one MySQL-only concurrency test skipped in SQLite. The production build and desktop/mobile interaction checks passed. No production changes were applied.
