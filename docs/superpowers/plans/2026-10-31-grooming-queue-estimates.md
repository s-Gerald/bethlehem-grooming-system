# Simplified Grooming Estimates Implementation Plan

**Goal:** Restore advisory grooming durations and physical queue forecasts without changing admission, pricing, or queue assignment.

**Architecture:** Historical duration tables live in one PHP configuration file. A duration calculator derives estimates from current catalogue inclusions and stored pet/service selections. A read-only queue service projects per-groomer work; authenticated endpoints and existing board/tracker refreshes consume it. Shared frontend code owns estimate requests and the closing warning dialog.

**Tech stack:** Laravel/PHP, Carbon, vanilla JavaScript, existing Tailwind and native dialogs.

**Spec:** User attachment `C:/Users/Gerald/.codex/attachments/0d6563d9-2b7d-485f-8ba6-56fe0e00e81f/Pasted text.txt`.

## Constraints

- No schema changes, branches, commits, admission forecast gates, or restored arrival windows.
- Historical package/size/cut ranges and A La Carte durations only; current catalogue inclusions remain authoritative.
- Online pets are hypothetical additions, never physical workload; tomorrow receives durations only.
- Asia/Manila dates and configured grooming hours; upper bounds determine closing warnings.
- Preserve actual timestamps/history, three tracker stages, sedation consent, pricing, capacity, and physical queue transactions.
- Browser inspection uses only the existing admin role tab. User handles navigation, data entry, OTP, and reset testing.

## Review focus

- Missing historical rules or stale/deleted services must produce unavailable estimates.
- Overdue active pets must invalidate dependent exact times while retaining provable closing risk.
- Named off-duty or unknown active assignments must not manufacture available capacity.
- Selection changes, back navigation, repeated clicks, and failed requests must not produce stale warnings or duplicate navigation.
- Multi-pet hypothetical intake must follow the existing dog/cat ordering and use the latest completion.

## Tasks

1. **Duration rules** — create `config/grooming_estimates.php`, `app/Services/GroomingDuration.php`, and `tests/Unit/GroomingDurationTest.php`. Interface: `estimate(array $selection, Collection $services): ?array` returns `min_minutes`/`max_minutes`. Test historical literal ranges, every cut/size, current inclusions, standalone services, cats, ignored subjective inputs, and unknown rules. Run the test red, implement, then green.
2. **Physical queue simulation** — create `app/Services/GroomingQueueForecast.php` and focused unit tests. Interface: `project(array $jobs, array $groomers, Carbon $now, Carbon $opens, Carbon $closes): array`; `snapshot(): array`; `forBookings(Collection $bookings): array`; `preview(array $pets, bool $walkIn): array`. Test A/B/C literal times, queue ordering, elapsed active work, overdue uncertainty, parallel lanes, named/any assignments, no-duty cases, and multi-pet completion. Add feature coverage for authenticated read-only endpoints, tomorrow, and ignored online workload.
3. **API and existing refresh integration** — create `app/Http/Controllers/GroomingForecastController.php`; extend `routes/api.php`, `scripts/api.js`, `BookingController` history/show and `AdminBookingController` board output. Expose only submitted-pet forecasts to customers; attach own-pet estimates to existing responses. Never call queue reservation from forecasting. Test access, query behavior, no writes, and recalculation after state changes with existing admission regressions.
4. **Selection UI and warning** — create shared `scripts/components/grooming-estimates.js`; extend `grooming-service-selection.js`, `booking-services-step.js`, and `walk-in-services-step.js`. Debounced server duration display; fresh forecast only after existing Next validation. Native compact amber dialog reuses sedation dialog styling, restores focus, supports Escape, and only continues to Review. Test actual request/navigation coordination, stale results, failures, dialog actions, and duplicate clicks with Node regressions.
5. **Board/tracker and delivery** — extend `admin-dashboard.js`, `pages/admin/appointments.html`, `client-dashboard.js`, and `grooming-visit-summary.js` to place compact advisory text within existing hierarchy. Update affected page/module cache versions and obsolete absence assertions. Run affected PHP/frontend regressions and production build; inspect the existing admin tab without manual workflow navigation. Document deployment and honest limitations.

The user supplied an implementation request and made skill workflow conventions advisory; execute in this session without a separate plan approval gate.

## Completion

- [x] Historical duration calculator and coverage.
- [x] Queue simulation and read-only authenticated endpoints.
- [x] Existing board/history/detail refresh integration.
- [x] Shared services-step duration display, warning dialog, and stale-request protection.
- [x] Cache versions, deployment notes, 90/90 frontend scripts, 418 PHP tests (one MySQL-only skip), and production build.

Manual navigation, data-entry workflows, and mobile visual verification remain with the user. No schema migration, commit, branch, or deployment was performed.
