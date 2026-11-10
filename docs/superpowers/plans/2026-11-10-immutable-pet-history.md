# Immutable Pet History Implementation Plan

**Goal:** Freeze grooming and clinic pet metadata at registration/intake while current profiles remain editable.

**Architecture:** Store versioned JSON on booking_pets and clinic_appointments. A shared App\Support\PetProfileSnapshot captures, resolves, and searches historical metadata without a live-profile fallback. Dedicated size fields and visit clinical facts retain authority.

**Tech Stack:** Laravel 12, MySQL/MariaDB production, SQLite feature tests, existing JavaScript history renderers.

**Spec:** User-provided immutable history prompt and clarification dated 2026-11-10.

## Constraints

- Preserve approved Add/Edit Pet UI at 4f18a50, including hierarchy, widths, body condition selection/verification and dirty-state behavior.
- No profile-update or BCS-sync snapshot cascades, activity payloads, or snapshot notifications.
- Keep active screens live, existing prices/services and medical facts visit-specific.
- Do not restore removed grooming referrals (the service named in the original prompt no longer exists).

## Review focus

- Reverted grooming intake must not leak intake values or confirmed size into pre-arrival cancellation history.
- Missing/deleted pets and missing new snapshots must never cause live-profile fallback.
- Legacy verification cannot be inferred from today's profile.
- Search must resolve the same stage as the formatter, including explicit nulls.
- History payment labels and secondary detail endpoints must use historical identities too.

## 1. Shared snapshots and migration

Files: app/Support/PetProfileSnapshot.php; BookingPet/ClinicAppointment casts; database/migrations/2026_11_10_000002_add_pet_profile_snapshots.php; focused snapshot tests.

- [x] Write failing behavior tests for intake/registration/legacy resolution, sanitized verification, missing snapshots, and chunked/idempotent backfill.
- [x] Add nullable JSON storage and automatic deterministic backfill using bounded joined reads and conditional writes; keep provenance and stronger session size, clear unsupported verification.
- [x] Implement capture, registration/intake lifecycle helpers, historical profile/size resolution, and JSON search with identical stage precedence.
- [x] Run focused PHPUnit tests; inspect migration rollback and query counts.

## 2. Lifecycle and history APIs

Files: BookingController, WalkinController, ClinicWalkinController, AdminBookingController, AdminClinicController, PetMedicalRecordController, GroomingPaymentReadinessService; focused existing creation/check-in fixtures and new history feature tests.

- [x] Add failing tests proving post-service Pet edits do not change customer/admin history, payment labels, historical name/species/breed search, or visit weight/BCS.
- [x] Capture registration after saved profile resolution in both grooming and all three clinic paths; walk-ins also capture intake.
- [x] Capture physical intake after existing grooming confirmation updates, and during clinic check-in; clear reverted grooming intake and confirmed size.
- [x] Use explicit historical formatting for archive/terminal records, leaving operational formatting live. Prefer stored service labels/kinds where available.
- [x] Verify all actual entry paths, terminal before-intake cases, re-check-in, profile mutability, archive resilience, and activity/notification isolation.

## 3. History presentation and verification

Files: pages/client/grooming-history.html; scripts/components/admin-archive.js; existing customer/admin history regression tests.

- [x] Add focused renderer tests for compact Body Condition near Size, verified/unverified/missing values, and neutral styling.
- [x] Extend existing history rows/grid only, preserving all approved forms.
- [x] Run relevant PHP and JavaScript regressions and scope-appropriate broader checks. Review diff against approved UI commit and document deployment/backfill limitations.

## Verification result

- Complete PHPUnit run: 2,359 tests, 33,232 assertions, zero failures/errors, 10 existing MySQL-only skips.
- Focused snapshot suite: 8 tests, 54 assertions, all passed.
- Four customer/admin grooming/clinic history regression scripts passed.
- Production build passed; generated stylesheet drift was removed to preserve approved styles.
- Full JavaScript run: 95/96 passed. The unchanged admin-sidebar-cache-regression.cjs fails its token-switch isolation assertion in isolation too; its scripts/api.js and admin-sidebar.js inputs are also unchanged.
- No commit, push, local application migration, or production deployment performed.
