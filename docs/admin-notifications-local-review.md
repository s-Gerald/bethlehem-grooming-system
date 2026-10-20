# Admin/Staff notifications — local review, 2026-10-20

Implemented on the existing `gerald-frontend` branch. No branch changes, commits, pushes, deployment, production access, new dependencies, database writes to the running application, or schema changes.

## Root causes

- The filter used `admin-custom-select-*` classes with no CSS definitions. Alpine correctly expanded its options, but they flowed as unstyled inline content. Two styled native selects replace that obsolete custom menu.
- Notifications mapped to `appointments` in the sidebar helper, and its initial HTML explicitly selected Grooming. Both are removed. A second Alpine binding also assigned `aria-current` from visual section context; it now checks the actual route.
- Legacy categories mixed services with activities: Clinic/Grooming omitted payment and cancellation types. The new service/activity dimensions intersect on the server; legacy category behavior remains available.
- Dashboard/Grooming owned notification state and fetched a default 30 records on startup and every content refresh, even with the bell closed. Clinic had no bell. Grooming payment mutations also failed to invalidate notification data.

## Implementation and main files

- `app/Models/Notification.php`: independent service/type maps and backend-owned activity labels, using existing supported types, including historical expired pre-registrations and status events.
- `app/Http/Controllers/NotificationController.php`: validated `service` and `activity` parameters, existing pagination, additive serialized dimensions/options, and `mode=count` on the existing route. Count mode executes one count query and does not hydrate records or relations. Read mutations return the authoritative shared unread count. Clinic-facing text uses “visit”.
- `scripts/services/admin-notification-service.js`: one coordinator for counts, filtered/history/preview reads, read mutations, lifecycle, shared subscriptions and invalidation. All retained data and request deduplication remain in the existing API cache. Read mutations run in order and duplicate individual actions share a promise.
- `scripts/components/admin-notifications.js`: one Alpine controller for history and previews, shared custom-element bell/row markup, existing Manila date grouping, visible loading/errors, stale-response guards, focus management, Escape and outside-click dismissal. The preview is teleported to the body to avoid sticky-header clipping and is bounded to the viewport.
- `scripts/api.js`: new filter parameters, count cache freshness, count reuse from list responses, invalidation events, payment invalidation and lock notification. Request sequencing prevents late responses from restoring older count data.
- `scripts/components/admin-sidebar.js`: validates `from=dashboard|grooming|clinic`, paints origin context before Alpine, and omits `aria-current` for originating sections. Direct/invalid origins select nothing. Notifications never loads an origin workspace or grooming indicator merely because of its origin.
- `pages/admin/dashboard.html`, `appointments.html`, `clinic.html`, `notifications.html`: shared entry points/dependencies, Clinic header bell, history toolbar and corrected initial sidebar. Changed shared assets have updated query versions.
- `css/admin-notifications.css`: shared Bethlehem controls, compact scrollable preview, native selects, keyboard focus and responsive sizing. Obsolete admin dropdown CSS was removed from `css/custom.css`; customer notification code is unchanged.
- `scripts/components/admin-dashboard.js`: notification fetching, polling calls, state, bridge fields, mutation handlers, grouping and time helpers removed. Its business content polling remains separate.

## Filtering and preserved behavior

All/Unread, Service (All Services/Grooming/Clinic), and Activity are independent. Activities are Pre-registrations, Walk-ins, Schedule changes, Queue / status updates, Payments, Cancellations and Expired pre-registrations. These map to existing backend types; no events or records are created by this refactor.

Service filters include that service's payments and cancellations. Clinic + Payments + Unread returns only unread clinic payment notifications. Service/activity changes reset to page 1; Load More alone requests subsequent pages. Older responses cannot overwrite newer filters or badges. Empty filters show a concise empty state.

The existing admin/staff middleware remains authoritative, and read state is still shared among authorized staff. Mark all as read retains its global semantics, even while a filter is selected. Record creation/delivery, emphasis, icons, timestamps, grouping and read indicators remain intact. Legacy `category` requests keep their original behavior.

## Fetching and request evidence

| Interaction | Before | After |
| --- | --- | --- |
| Dashboard/Grooming startup | Default notification list, up to 30 records | Count-only response, or fresh cached count |
| Clinic startup | No notification entry point | Same shared count-only behavior |
| Closed-bell refresh | Up to 30 records per content refresh | One shared 30-second count refresh; hidden/locked tabs do not poll |
| Open bell | Already eagerly loaded list | At most 10 records on demand; fresh previews reused |
| Full history startup | First page | First page, with no separate startup count request |
| Origin navigation | Grooming selection regardless of origin | Validated context; no origin workspace reads |

The browser fixture request trace recorded one initial `?mode=count` on Dashboard, then zero notification startup requests on Grooming and Clinic while that count remained fresh. Every preview requested `per_page=10`; reopening reused its fresh response. An open preview refreshes when polling detects a changed count. Full history is not repeatedly polled. Cross-tab mutations invalidate the existing cache and refresh an active view before checking the count, allowing its response to supply the count.

These are local fixture/request-budget comparisons, not measured field Core Web Vitals or a production load benchmark. The pre-change 30-row behavior was also observed in the signed-in local dropdown and traced in its controller.

## Verification

- Focused PHP suite: **413 passed, 5,700 assertions, 1 skipped**. Includes notification center/interface/migration coverage, script dependencies, Grooming/Clinic authorization, session locks and shared shell interfaces. PHPUnit forces in-memory SQLite. The existing skipped Grooming capacity concurrency test requires its isolated MySQL harness; it is unrelated to this change and was not run.
- **13 focused Node regression scripts passed**: notification center, date grouping, admin data cache, navigation performance, sidebar cache, loading, universal loading, auth/session, inactivity, grooming sidebar indicator, Grooming Cash/PayMongo, Dashboard capacity and Clinic first render.
- Notification browser suite passed: all three entry points, actual rendered dropdown/select styles, viewport containment at 320/375/414/768/1440px, focus/Tab/Escape/outside clicks, filters and combinations, pagination reset, read mutations, all origins, direct/invalid origins, hard refresh, pre-Alpine origin styling, Staff access, lock/unlock/logout, cross-tab synchronization and hidden-tab request pause.
- Existing sidebar first-paint browser checks passed for cold HTML and the affected page sweeps for Admin, veterinarian Staff and Grooming Staff, including normal/hard reload and mobile drawers. Each role reused one initial identity request across these checks.
- JavaScript syntax checks and `git diff --check` passed. No unrelated application test failures remain in the checks run.

Tests use synthetic API responses for browser mutations; the signed-in running app was inspected without marking its real notifications read or changing its account/session.

## Local reproduction

Run `php artisan test --compact --filter='AdminNotification|AdminBookingCancellationNotificationInterface|AdminDashboardScriptDependencies|GroomingAdministrationAuthorization|ClinicAdministrationAuthorization|SessionLock|AlpineModalCloakInterface|StaffSettingsInterface'`.

Run `node tests/admin-notification-center-regression.cjs`, `node tests/admin-data-cache-regression.cjs`, `node tests/admin-navigation-performance-regression.cjs`, and the other named focused regressions as needed.

For browser tests, keep the local server on `http://127.0.0.1:8000`, set `PLAYWRIGHT_PATH` to an existing Playwright installation and `PLAYWRIGHT_CHANNEL=msedge` for the installed Edge browser, then run `node tests/admin-notifications-browser.cjs`. `LOCAL_REVIEW_CASES` accepts its function names for focused runs; `LOCAL_REVIEW_SCREENSHOTS` optionally specifies a local evidence directory. All API routes are intercepted and constrained to localhost.

## Manual steps and limits

No migrations, installation, build or deployment steps are required. Refresh the local pages and review the uncommitted changes before your manual commit/push workflow. Native select menus follow the browser/platform; automated rendered checks used Chromium-based Edge. Notifications retain the existing polling model rather than real-time delivery, and the historical `no_show` type is presented as expired pre-registration. No production performance claims are made.
