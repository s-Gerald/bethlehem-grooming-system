# Activity Log Implementation Plan

**Goal:** One accountable event history, an admin-only log, and safe role-specific dashboard summaries.

**Architecture:** Record allowlisted model transitions inside existing business transactions, with explicit hooks for bulk grooming transitions, authentication, and recovery. Preserve existing payment, stock movement, and recovery records as domain records; do not create a second operational feed from them. Query the new event records for both presentations.

**Tech Stack:** Laravel 12, existing Eloquent models and Sanctum account sessions, Alpine, existing Bethlehem CSS and Phosphor sprite.

**Spec:** User attachment `Pasted text.txt`, supplied October 25, 2026.

## Global constraints

- Queue terminology only in new UI; preserve internal legacy names and workflows.
- Full log and details are admin-only. Veterinarians receive Clinic operational summaries only.
- No backfill, production deployment, browser role changes, data entry, OTP or password testing.
- Minimal allowlisted metadata; immutable actor snapshots; no confidential notes or credentials.
- Bounded indexed feed queries, server pagination, existing dashboard cache and invalidation.

## Review focus

- Repeated transitions and callbacks must not create duplicate records.
- Rollback must remove activity as well as business writes.
- Historical actor names and roles survive account changes.
- Filters cannot reveal private events to staff or Grooming activity to veterinarians.
- Static hosting must route the protected log URL through Laravel.

### Task 1: Recording and query contracts

Files: new activity migration/model, `ActivityRecorder`, `ActivityObserver`, `ActivityController`, provider registration, API/web routes, targeted workflow hooks; `tests/Feature/ActivityLogTest.php`.

- [x] Write and run failing tests for snapshots, privacy, role defaults, filters, pagination, rollback, repeated saves, and protected pages.
- [x] Implement indexed append-only records and allowlisted recording. Use explicit payment actors, never the staff member polling an automatic payment.
- [x] Replace reconstructed dashboard activity methods with the shared record query; update existing dashboard tests to create actual events.
- [x] Run focused PHPUnit tests and relevant workflow suites.

### Task 2: Native frontend

Files: `scripts/components/admin-sidebar.js`, `scripts/components/admin-dashboard.js`, `scripts/api.js`, `pages/admin/dashboard.html`, new activity log component/view/CSS; existing sidebar and dashboard regression tests.

- [x] Add one shared sidebar insertion before Settings; update version references on all consumers.
- [x] Build compact server filters/table/pagination and accessible right drawer with safe text rendering.
- [x] Add dashboard segmented filters, role defaults, request race protection, distinct cache keys, and dated Philippine times.
- [x] Run frontend regressions; visually inspect only the existing admin tab where permitted.

### Task 3: Deployment and review

Files: `docs/activity-log.md`, root Apache routing if needed.

- [x] Document migration-before-code traffic, no backfill, event coverage and meaningful limitations.
- [x] Review diff, run appropriate affected tests/build and report actual results.
