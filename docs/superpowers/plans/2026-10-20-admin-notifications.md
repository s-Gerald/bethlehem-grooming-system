# Admin/Staff Notifications Implementation Plan

**Goal:** Share an efficient notification center across Dashboard, Grooming and Clinic, with accurate filters and origin-aware navigation.

**Architecture:** Extend the existing notification endpoint with independent service/activity filters and a count-only mode. Keep legacy category behavior. Use the existing API cache for all data; a shared service owns count refresh and invalidation, and a shared Alpine component owns the bell markup and presentation. The history page consumes the same service.

**Tech stack:** Laravel, plain JavaScript, Alpine, existing CSS/Tailwind and Phosphor sprite.

**Spec:** User request in this chat, 2026-10-20.

## Constraints

- Existing `gerald-frontend` branch only; no commits, pushes, deployment, production access or schema changes.
- Preserve role middleware, shared staff read semantics, creation/delivery logic, pagination and existing category API consumers.
- Preserve Bethlehem styling. Use native Service and Activity selects; remove the unsupported custom menu.

## Tasks

1. **Backend filters/count:** Extend `app/Models/Notification.php` and `app/Http/Controllers/NotificationController.php`; add failing behavior tests in `tests/Feature/AdminNotificationCenterTest.php` first. Verify service + activity + unread intersections, payments/cancellations for both services, legacy category behavior, count-only query cost and validation. Activity options come from the backend type map. No speculative events.
2. **Shared data/controller:** Extend `scripts/api.js` parameters and cache invalidation events. Add `scripts/services/admin-notification-service.js`, rework `scripts/components/admin-notifications.js` for shared rendering/controller and history. Verify cache reuse, deduplication, invalidation races, stale-filter responses, read mutations, session/account transitions and cleanup in focused Node regressions.
3. **Entry points/sidebar:** Replace duplicate bell markup in `pages/admin/dashboard.html` and `appointments.html`; add it to `clinic.html`. Remove dashboard notification fetching/state/helpers. Update `notifications.html` toolbar/static sidebar and `scripts/components/admin-sidebar.js` validated origin handling. Apply highlight before Alpine; never mark origin links aria-current. Avoid origin-driven page fetching.
4. **Verification:** Update affected interface, date grouping, navigation, sidebar and dependency assertions. Add `tests/admin-notifications-browser.cjs` using local API fixtures and actual rendered CSS/Alpine. Check desktop/mobile geometry, keyboard/outside/Escape, pagination/filter races, all origins/hard refresh, roles, hidden tabs, lock/logout and network counts. Run relevant PHP/Node/browser checks and record outcomes in a local review document.

## Review focus

- A late response after a filter or mutation must not restore stale unread rows/counts.
- Notification origin and permission changes must not launch Dashboard/Grooming/Clinic workspace requests.
- Locked or changed sessions must not retain visible notification data or resume old work.
- Cross-tab invalidation must refresh count and active views without adding another cache/polling mechanism.
- Native selects and the shared preview must fit 320/375/414/768px and desktop viewports.

## Initial evidence

- Browser reproduction: custom menu opens as inline/overlapping text; `admin-custom-select-*` classes have no definitions in CSS.
- Sidebar explicitly maps Notifications to appointments and its static HTML marks Grooming current.
- Existing category map excludes payments/cancellations from service categories.
- Dashboard/Grooming startup and 30-second refresh load a default 30-record preview even when closed; Clinic has no bell.
- Baseline cache, navigation-performance and date-grouping Node regressions pass.
- Sandboxed PHP reports the bootstrap file unreadable; retry the local in-memory SQLite suite with appropriate execution access.
