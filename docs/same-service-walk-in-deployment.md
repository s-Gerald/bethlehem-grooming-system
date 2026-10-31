# Same-service walk-in duplicate prevention

Root cause: PetServiceSessionPolicy checked only opposite-service sessions. Grooming walk-ins therefore admitted pets already pending or active in Grooming; staff card metadata omitted those conflicts. The UI also trusted stale saved selections during refresh, restoration and Clinic preselection.

Changed files:
- PetServiceSessionPolicy: staff-specific batched availability plus a same-service assertion; customer availability and opposite-service replacement remain unchanged.
- WalkinController: validates all resolved persisted pets inside DailyPetQueue before opposite-service replacement or new intake writes.
- CustomerController: reuses its existing batched response enrichment with staff eligibility.
- walk-in-pet-step.js and its HTML asset version: neutral disabled states, current status labels, guarded click/preselection/Next, stale draft cleanup, and fail-closed refresh errors. Existing Clinic backend validation and check-in endpoints are unchanged.
- Existing Grooming, pet-selection and MariaDB concurrency regressions: duplicate rejection, cancellation-state exemptions and concurrent persisted-pet resolution. Existing idempotency and opposite-service tests remain intact.

Verification: affected PHPUnit run reported 512 cases, 6,386 assertions and no failures (511 passed; the MySQL-only case skipped on SQLite). That case passed separately against disposable local MariaDB with 245 assertions, including real contention and simultaneous same-pet walk-ins. An additional focused rerun passed after strengthening staff availability assertions. Five focused frontend scripts passed: walk-in-pet-step, pet-session-selection, walk-in-review, clinic-subrole-workflow and customer-data-cache. Production build and diff whitespace checks passed; the existing Browserslist-data warning remains.

Inspected the existing admin dashboard in the sole ChatGPT browser role tab. No manual navigation or form input was performed; workflow testing remains with the user.

Deploy the changed PHP and raw JS/HTML together, retaining the new walk-in script query version. Include normal generated build/vendor artifacts through the established release process and refresh PHP OPcache if required. No new migration, dependency, route, environment variable or job is required. No commits, pushes or deployment were performed.
