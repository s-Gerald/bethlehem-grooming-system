# Grooming Performance Dashboard Implementation Plan

**Goal:** Replace the three Reports views with an admin-only, real-data Grooming dashboard and a Clinic Coming Soon tab.

**Architecture:** One authenticated `/api/admin/reports/grooming` request supplies the entire reporting period. A reporting service aggregates payments, completed booking pets, historical services, and customer visit history in SQL, independently of transaction pagination. Existing Alpine and Bethlehem styling render accessible SVG charts without a new dependency.

**Files:** Modify `app/Http/Controllers/ReportController.php`, `routes/api.php`, `scripts/api.js`, `pages/admin/reports.html`, `scripts/components/admin-reports.js`. Add `app/Services/GroomingPerformanceReport.php`, `css/admin-reports.css`, backend and JavaScript regression tests. Add service snapshot columns through a migration and capture them in the two existing booking creation workflows and `BookingService` fillable fields.

**Constraints:** Work on `gerald-frontend`; preserve sidebar, authentication, and unrelated workflows. No production-data changes or new tables. Concise UI text. Philippine local calendar boundaries (the application stores local datetimes). No fake dashboard data or swallowed API errors.

The retired services/customer report endpoints and API wrappers have no remaining consumers; remove their duplicate calculations together with the retired tabs. Customer records and the Transactions page stay intact. The snapshot migration also backfills a scalar grooming-only amount in existing gateway JSON snapshots and new checkouts capture it directly, including early-payment stock conflicts.

**Definitions:** Collections use the first successful real payment per grooming booking, dated by paid_at, less attached product sales; online snapshots preserve grooming-only amounts even if product stock reconciliation failed. Paid-booking count is distinct bookings. Visits use finished, non-cancelled pets and their completion timestamps, with a booking timestamp fallback only for explicitly finished legacy pets. Customer history counts distinct completed bookings, never pets; customers with a prior separate visit are returning, including repeats inside the selected period. New and returning are disjoint. Outcomes count bookings: completed by last actual pet completion, cancellations by cancellation timestamp (scheduled date fallback for legacy), no-shows by scheduled date.

## Review focus

- Multi-pet and partial cancellation: visits and services must exclude cancelled pets without multiplying joins.
- Historical paid rows, duplicate payments and product lines: all-period aggregates must remain independent of pagination and service totals.
- Local date edges and every preset: inclusive start, exclusive next-day end; group daily up to 45 days, weekly up to 180 days, monthly thereafter.
- Walk-in identity: resolve linked registered or unregistered owners before falling back to a legacy phone/walk-in key.
- Loading, errors and request races: hide figures until a successful current response; retry errors; validate custom dates before requests.

## Tasks

1. Write endpoint tests using an isolated SQLite database for real aggregates, payment exclusions, customer history, date boundaries, empty periods, validation and authorization. Run red, implement SQL aggregates and route, run green.
2. Add nullable service-name/type snapshots, backfill from existing catalogue records without altering business data, and populate from already-loaded services in both booking workflows. Test migration and historical rename/deactivation behavior.
3. Replace the main Reports content and script, preserve navigation and auth, add scoped styles. Test default/shared filters, toggles, empty/error states and stale-response handling through the real Alpine component methods.
4. Run focused PHP/JS checks and relevant workflow regressions. Preview desktop and mobile, inspect overflow, keyboard controls and charts; review the final diff.

Execution stays in this session as authorized by the implementation request and AGENTS.md; no worktree or commit is required.

## Verification and rollout

- Related PHP regression run: 332 tests, 4,902 assertions, one existing MySQL-only capacity concurrency test skipped under SQLite. Final focused route/snapshot/payment recheck: 32 tests passed.
- All 67 JavaScript regression scripts passed; report and authentication checks also rerun after retiring the old API wrappers.
- Browser preview used the actual endpoint with isolated SQLite test fixtures. Verified 1280px desktop, 768px tablet, and 320/375/414px mobile; chart axes/bars, keyboard tabs, custom dates, empty periods and an injected HTTP error. No horizontal overflow or remaining browser errors.
- Deploy the included snapshot migration before using the new endpoint. No configured application database or production records were changed during implementation. Old names already lost before snapshots cannot be reconstructed.
