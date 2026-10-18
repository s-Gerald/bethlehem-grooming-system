# Clinic Reports Implementation Plan

**Goal:** Extend the existing Reports dashboard with accurate Clinic reporting, preserving Grooming behavior.

**Architecture:** Share report period/series utilities and the actual Alpine/HTML chart and filter implementation. Keep Clinic eligibility and aggregation in a separate Laravel service because its case/payment structure differs from Grooming.

**Tech Stack:** Laravel 12, MariaDB with ONLY_FULL_GROUP_BY, Alpine, existing Reports CSS/SVG charts.

**Spec:** User request in this chat; AGENTS.md is authoritative. All execution stays on gerald-frontend locally; no commits, branches, pushes, deployments, or production data changes.

## Data decisions

- Eligible Clinic cases require completed status, paid flag, consultation_finished_at, and a recorded successful payment date. Cash requires no gateway; online requires its linked paid live PayMongo Clinic gateway with matching payable type/id, currency, and no reconciliation failure.
- Visits/services use consultation_finished_at; collections/average use payment date and distinct paid cases. Aggregate cases before service joins. Count performed service records once, not billing quantity or medicine/vaccine charge lines.
- Prefer service_snapshot.name; fall back to the existing catalogue only when missing. Preserve archived completed cases whose status remains completed.
- Only actual completed, cancelled, and no_show outcomes count. No inference from expired/archived/active statuses. Cancellation uses updated_at (no cancellation timestamp exists); no-show uses appointment_date.

## Tasks and verification

1. Add Clinic endpoint feature tests first: eligibility exclusions, cash/live online, duplicates/context isolation, different payment/completion dates, Manila boundaries, snapshots/performed status, outcomes, presets/grouping, authorization/validation/errors. Observe missing endpoint failures; add ClinicPerformanceReport and shared ReportPeriod utility. Verify Clinic and existing Grooming tests in SQLite and isolated strict local MariaDB.
2. Extend report interaction tests first: lazy section load, section-specific filters/cache, request races, Clinic three KPIs, error/retry, empty charts/services. Reuse one report panel and chart renderer, parameterize labels/insights, add Clinic API helper, replace report-only Booking copy, and pin the existing heading beneath mobile navigation. Verify Node report regressions.
3. Read actual localhost MariaDB aggregates without mutations. Check localhost UI at desktop and 320/375/414/768 widths, scrolling, tabs, custom ranges, chart modes, loading/empty/error controls. Run relevant Clinic workflow tests and frontend regressions; inspect final diff and branch. Record any environment limits explicitly.

## Review focus

- Test-mode, failed, cancelled, duplicate and cross-context gateway records never inflate collections.
- Multiple performed services never multiply visits or payments; quantity is a billing dimension.
- Completion on a different day/month from payment keeps the two chart series independent.
- Tab/filter changes cannot show stale asynchronous responses from another report.
- Strict SQL grouping uses derived named columns; mobile sticky offsets follow the existing navigation height.

## Verification completed

- `php tests/grooming-reports-mysql.php`: 18 tests, 183 assertions passed on MariaDB 10.4.32. The runner now removes forced SQLite settings from a disposable PHPUnit config and explicitly asserts the MySQL connection and ONLY_FULL_GROUP_BY; its temporary database/config are removed afterward.
- Focused PHP tests covering both reports, Clinic service billing/confinement, and payment validation: 84 tests, 860 assertions passed.
- `npm test`: all 67 frontend regression scripts passed. Existing Node experimental VM-module warnings remain.
- `php tests/reports-local-smoke.php`: all five periods for both reports passed against actual local records inside a read-only transaction. This month's Clinic totals: PHP 26,620, 12 completed paid visits, PHP 2,218.33 average. Grooming remained PHP 20,000, 29 completed pet visits, PHP 800 average.
- Signed-in localhost browser: verified both chart modes, custom date application, independent tab state, keyboard tabs, chart targets/tooltips, top-six/Show All services, loading skeletons, Today empty state, and no browser console errors. API errors/retry, invalid custom dates, and request races are covered by the endpoint/component tests without disrupting the running application.
- Responsive checks: no horizontal overflow at 320/375/414/768/1024/1440 pixels; Clinic desktop cards have equal widths. Reports pins at desktop top 0 and mobile top 80px, directly beneath the existing navigation.
- `git diff --check` passed; branch remains gerald-frontend. No commits, branch creation, pushes, migrations, deployments, or production-data changes.

### Existing data limitation

Cancelled cases have no dedicated cancellation timestamp. Their current `updated_at` is used for period attribution; later edits to a cancelled case can move that attribution. No-show uses the recorded scheduled date. Neither infers outcomes from expired/active cases.
