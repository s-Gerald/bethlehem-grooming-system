# Remove Grooming Estimation Implementation Plan

**Goal:** Remove all grooming duration forecasting while retaining service preferences, actual sessions, intake controls, queue integrity, pricing, and the existing UI.

**Architecture:** Extract preferences into `config/grooming.php`, served by the existing catalogue and admin responses. Keep admission inside the existing queue transactions using `OperationalCapacity`; keep actual session transitions and groomer locks. Retain `arrival_end_time` as an accepted arrival-window snapshot for historical and future registrations already communicated to customers; new bookings/reschedules store the full configured end time.

**Tech Stack:** Laravel 12 / PHP 8.2+, existing JavaScript modules and Alpine, MySQL/MariaDB production, disposable SQLite tests.

**Spec:** User attachment `Pasted text.txt` (2026-10-26), sections 1–18.

## Constraints

- No production deployment or live database changes; no new dependencies.
- Preserve the three-circle customer tracker and Bethlehem styling.
- Preserve prices, consent, preferences, physical capacity, groomer concurrency, queue locks, permissions, notifications, clinic timing, and cache conventions.
- No replacement forecasting, timers, redundant stored durations, or helper panels.

## Review Focus

- Legacy factor payloads have no effect, including stale walk-in/start/check-in requests.
- Near-closing registrations and physical admissions succeed when actual rules permit them.
- Multi-pet status aggregation and independent actual intervals remain accurate.
- Existing communicated arrival windows and reminder/expiry semantics remain coherent.
- Fresh migration and deployed-schema upgrade both retain actual timestamps and preferences.

## Tasks

- [x] Update existing admission/service-selection tests for near-closing acceptance, factors ignored, preference validation and real capacity enforcement; run and observe expected failures.
- [x] Decouple service selection/catalogue/preferences from estimation; remove backend forecast routes, middleware and services, all response/persistence/recalculation consumers. Verify booking, walk-in, check-in, start/finish/undo, payment and capacity tests.
- [x] Remove estimate UI/state/requests from registration, walk-in, admin and customer dashboards; retain preference rendering and actual duration in visit details. Adapt existing runtime regressions and preserve tracker assertions.
- [x] Remove historical estimator backfills and add a forward-only column retirement migration. Test upgrade preservation and clean-install migration using disposable databases.
- [x] Remove obsolete tests/assets/styles, update cache-busting, run relevant regressions plus production build, audit remaining references and document controlled deployment steps.

Execution is in this session under the user's implementation request; no worktree, commits, deployment, or separate approval stage.

Verification and release guidance: [grooming-estimation-removal.md](../../grooming-estimation-removal.md).
