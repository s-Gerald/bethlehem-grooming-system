# Dashboard reliability implementation plan

**Goal:** Reproduce and correct the summary failure locally, preserving real metrics and improving bounded refresh recovery.

**Architecture:** Keep the existing controller and shared API cache/request mechanism. Change backend behavior only after reproducing a specific defect. Keep expiry on reads unless scheduler independence can be established without delaying status transitions or notifications.

**Constraints:** Current `gerald-frontend` branch only; no push, deployment, live access, production database/configuration changes, or production migrations. Preserve authorization, metric contracts, expiry rules, notifications, and UI design.

1. Trace the summary's expiry, queue, collections, capacity, and activity counts. Inspect local logs and migrations. Run baseline PHP/JS checks and reproduce the endpoint on an isolated local MySQL-compatible instance with current migrations.
   - Verify the precise exception and failing SQL; distinguish reproduced defects from unverified production conditions.
2. Add backend regression coverage for empty/populated data, mixed queue ordering, collections and legacy payment keys. Implement the smallest proven correction.
   - Verify MySQL execution, response contract, query counts, repeated reads, and expiry/no-show tests. Do not move expiry exclusively to a scheduler whose execution is unverified.
3. Exercise actual component/API behavior for initial load, HTML navigation, hard refresh, failed revalidation, Retry, recovery, cache ownership, request sharing, visibility, and poll lifecycle. Add failing tests before implementation.
   - Reuse shared cache; expose refresh failure while retaining valid data; bound automatic retry frequency and reset on success. Keep manual Retry immediate.
4. Run focused regression checks, compare requests/query counts/timing, review the diff, and document deployment steps and remaining production evidence.

**Review focus:** MySQL select aliases inside expressions; test/live gateway collections; expired registrations without a running scheduler; retained data hiding refresh errors; duplicate listeners and pending requests during navigation or session changes.

## Investigation outcome

- The supplied Hostinger log contains 77 matching Dashboard exceptions: `Call to a member function format() on string` at controller line 229, from `2026-10-20 08:12:37` through `2026-10-21 12:57:58` as logged.
- The failure requires a non-null `dropped_off_at`. Initial fixtures with null check-in times passed on both engines. Adding a recorded check-in reproduced the same exception on SQLite and HTTP 500 on native MySQL 8.4 before the fix.
- Parse the timestamp with Carbon, matching the controller's existing booking formatter. Do not change model-wide serialization, SQL, migrations, or expiry behavior.
- Frontend regressions reproduced hidden stale refresh failures, unbounded polling frequency, duplicate poll setup, and overlap after invalidation. Fix these through the existing shared cache/component lifecycle.
- Keep expiry fallback on reads: the schedule declaration does not establish deployed scheduler execution, and removing it would delay status/notification updates when cron is absent.
