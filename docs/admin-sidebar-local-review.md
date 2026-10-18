# Admin/Staff sidebar local review — 2026-10-18–19

Implemented on the existing `gerald-frontend` branch. No branch switch, commit, push, deployment, production access, production environment change, or database migration was performed.

## Rendering and caching

The Customer Portal reference uses static navigation, local SVG/use icons, and cached account presentation. Admin/Staff now uses the same immediate-rendering principles while preserving its existing 280px sidebar, colors, typography, spacing, responsive drawer, routes, Inventory submenu, profile, indicators, and clinic controls.

`scripts/api.js` stores an allowlisted navigation identity in `sessionStorage` under `bethlehem.admin.navigation.v1`. It includes schema version, API origin, authenticated Sanctum token ID/role scope, timestamp, verified user ID, presentation names, staff type/subrole, clinic permissions, and server revision. It stores no bearer secret, email, phone, clinical records, or page data. The retention ceiling is 12 hours; server session expiry/lock remains authoritative. A new browser tab may perform a cold identity read because sessionStorage is tab scoped.

The shared sidebar hydrates this cache synchronously before Alpine and page content scripts. All sidebar consumers share one versioned API/sidebar asset URL and preload the scripts. Page-specific dashboard, grooming, clinic, and inventory requests remain independent. Ordinary page-data mutations and unlock data refreshes do not discard navigation identity. Inventory/Services fragment loaders and the shared pet-editor loader use versioned URLs so older cached Lucide markup cannot survive the migration.

The existing `/session` check still runs on page load, focus/resume, and periodic validation. Its response now includes a revision calculated from already-loaded user attributes/permissions, without additional database queries. Cached navigation stays visible and inert while checking; the locked state still conceals it entirely. A changed revision discards navigation and requests `/me` once. Changed account role ends the local session and requires sign-in. Logout, account switching, invalid/revoked sessions, malformed/expired cache, and stale in-flight responses are handled centrally. Server authorization and session middleware are unchanged.

Cache misses show only a small noninteractive spinner until identity verification succeeds. Dynamic clinic state and grooming counts update independently. Clinic controls remain disabled until their operational state has loaded.

## Icon migration

All Admin/Staff static icons, dynamic notification/report/settings icons, modal/empty-state icons, and walk-in icons use the existing local Phosphor SVG sprite. Added 45 official Phosphor 2.1.1 regular symbols, retaining its existing MIT license. All 352 static Admin SVG references resolve; dynamic icon targets are also present. Removed Lucide CDN imports, data attributes, createIcons calls, the global mutation observer, and orphan icon-refresh callbacks. No runtime icon package, external icon dependency, or package manifest change was added.

## Verification

- 33 focused Node regression scripts passed across navigation/cache, authentication/session inactivity, Customer cache, dashboard capacity and loading, grooming workflows and printing, Clinic workflows/history/payment/vaccinations, notifications, reports, security settings, Inventory, and walk-in behavior.
- New navigation regression cases cover concurrent reads; reload reuse in fresh JavaScript contexts sharing sessionStorage; unrelated mutations; account changes and late responses; all role presentations; malformed/expired/unavailable storage; slow clinic state; server revision changes; first login; logout; and session expiration. They also verify that navigation is inert during server validation.
- 19 focused PHP tests passed (267 assertions), including the full SessionLockTest class and Admin dashboard/sidebar/notification/Staff Settings interfaces. SQLite in memory was forced by the existing PHPUnit configuration. Obsolete source assertions tied to the removed Lucide implementation were updated/removed; behavior coverage is preserved in the new regressions.
- Modified JavaScript syntax and `git diff --check` passed. No production build was needed for these plain scripts, existing Tailwind utility classes, hand-maintained CSS, and SVG assets.
- The actual local browser rendered Dashboard, Customers, Grooming, Clinic, Services, Inventory, History, Transactions, Reports, Settings, and Notifications. Every completed visit had a ready sidebar and no recorded JavaScript error. Dashboard metrics, activity, clinic views, Inventory, reports, and settings data still loaded.
- Browser resource timing recorded exactly one `/api/me` request on a cold tab load, then zero on Dashboard reload and Inventory navigation. A subsequent sweep of Dashboard, Customers, Grooming, Clinic, Services, History (including a fresh document navigation), Transactions, Reports, Settings, and Notifications recorded zero `/api/me` requests. Required `/api/session` calls remained present.
- Inventory expansion and Products navigation worked; Products was the sole active submenu. The Services icon on nested Inventory pages resolved to the correct local sprite URL.
- Mobile opening was verified at 375px. Dashboard reloads at 320px, 414px, and 768px retained a ready sidebar, completed data loading, and had no horizontal overflow or additional /me request. The admin clinic control opened and cancelled its confirmation without submitting an operational change.

The embedded browser does not expose a native Network panel or a reliable browser-chrome hard-refresh shortcut. Actual requests were recorded from the browser's PerformanceResourceTiming entries using a temporary local review page, rather than claiming Network-panel inspection. Reload/cache behavior was also verified in entirely fresh JavaScript contexts. Staff, first-login, logout, expiration, and permission-change edge cases used automated fixtures and isolated backend tests; the interactive browser used the existing signed-in Admin account.

Browser evidence is saved outside the repository in this chat's visualization folder: `admin-sidebar-desktop.png` and `admin-sidebar-browser-requests.json`. Temporary review pages and downloaded icon-package tooling are removed before handoff.

## Changed files

- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/SessionController.php`
- `app/Models/User.php`
- `assets/icons/phosphor.svg`
- `css/admin-loading.css`
- `docs/admin-sidebar-local-review.md`
- `docs/superpowers/plans/2026-10-18-admin-sidebar.md`
- `pages/admin/appointments.html`
- `pages/admin/archive.html`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `pages/admin/clinic/sections/services.html`
- `pages/admin/dashboard.html`
- `pages/admin/inventory/inventory.html`
- `pages/admin/inventory/sections/sell-product.html`
- `pages/admin/notifications.html`
- `pages/admin/pet-edit-form.html`
- `pages/admin/reports.html`
- `pages/admin/services.html`
- `pages/admin/services/sections/grooming.html`
- `pages/admin/settings.html`
- `pages/admin/transactions.html`
- `pages/admin/walk-in-booking-confirmed.html`
- `pages/admin/walk-in-booking-review.html`
- `pages/admin/walk-in-booking.html`
- `pages/admin/walk-in-clinic-confirmed.html`
- `pages/admin/walk-in-consent.html`
- `pages/admin/walk-in-grooming-services.html`
- `pages/admin/walk-in-pet-details.html`
- `scripts/api.js`
- `scripts/components/admin-archive.js`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-customers.js`
- `scripts/components/admin-dashboard.js`
- `scripts/components/admin-inventory-items.js`
- `scripts/components/admin-inventory-transactions.js`
- `scripts/components/admin-inventory.js`
- `scripts/components/admin-notifications.js`
- `scripts/components/admin-reports.js`
- `scripts/components/admin-services.js`
- `scripts/components/admin-settings.js`
- `scripts/components/admin-sidebar.js`
- `scripts/components/admin-stock-in.js`
- `scripts/components/admin-stock-out.js`
- `scripts/components/admin-transactions.js`
- `scripts/components/admin-vaccinations.js`
- `scripts/components/walk-in-clinic-complaint-step.js`
- `scripts/components/walk-in-owner-step.js`
- `scripts/components/walk-in-pet-step.js`
- `scripts/components/walk-in-services-step.js`
- `tests/Feature/AdminDashboardScriptDependenciesTest.php`
- `tests/Feature/AdminNotificationInterfaceTest.php`
- `tests/Feature/SessionLockTest.php`
- `tests/Feature/StaffSettingsInterfaceTest.php`
- `tests/admin-sidebar-cache-regression.cjs`
