# Clinic mobile navigation implementation plan

**Goal:** Repair Clinic's mobile drawer and match the existing Dashboard header while preserving Clinic content, scrolling, session checks and cached navigation.

**Architecture:** Keep the existing `adminSidebar()` scope, backdrop and sidebar markup. Use its `sidebarOpen` state from Clinic's nested shell. Copy Dashboard's header utilities and local icon; give only this menu button pointer-safe and keyboard-visible focus styling. Match the sticky banner offset to the two-line header's height (5rem plus its 1px border).

**Scope:** Local `gerald-frontend` only. No new dependencies, controllers, API requests, branches, commits, pushes, deployment or database changes.

1. Extend `tests/clinic-first-render-browser.cjs` using its real Clinic workspace and isolated local API fixtures. Reproduce the broken menu by clicking it, then assert drawer visibility. Run the new test before implementation and confirm the expected failure.
2. Update `pages/admin/clinic.html`: Dashboard-style header, role context, Clinic Cases title, right menu, existing state, accessible focus utilities, and matching sticky offset. Rebuild `css/output.css` for new utilities; retain all unrelated content and shared controller behavior.
3. Verify Admin and clinic Staff navigation, backdrop, active state, pointer/keyboard focus, mobile/tablet/desktop, scrolling, hard refresh and request counts. Run existing sidebar cache/first-paint, Clinic first-render and relevant loading/navigation regressions. Update obsolete ambiguous heading selectors to distinguish the new header from the existing banner.

Review the actual local app in the supplied browser before and after the change. Keep test fixtures isolated from real accounts. Report executed checks and any remaining limitations.

## Implementation notes

- Reproduced the issue in the running local browser: clicking the old menu left `sidebarOpen` false and the drawer at -280px. The new browser regression failed waiting for the drawer to open before the production fix.
- Clinic now uses the existing shared state and Dashboard header utilities. Its role label uses the existing Admin/Staff title selectors and reserves its line height before identity hydration. The 5rem + 1px banner offset follows the header's two text lines, padding and border, including text scaling.
- The header uses z-30 beneath the existing z-40 backdrop and z-50 drawer. Desktop sidebar markup, permissions, logout, caching and loading scripts are unchanged.
- Only the menu button uses `focus:outline-none` with an explicit blue 2px `focus-visible` outline and 2px offset. Pointer activation keeps the original rounded border and shadow without a visible added outline.
- Tailwind CSS compilation succeeded. Unrelated removals/additions produced by regenerating an older CSS bundle were excluded; the final bundle change contains only the generated sticky-offset utility. Clinic's CSS URL has a new cache version parameter.

## Verification record

The following focused Node scripts passed:

```text
node tests/admin-sidebar-cache-regression.cjs
node tests/admin-loading-regression.cjs
node tests/admin-grooming-sidebar-indicator-regression.cjs
node tests/admin-universal-loading-regression.cjs
node tests/admin-navigation-performance-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/session-inactivity-regression.cjs
node tests/admin-clinic-patient-profile-regression.cjs
node tests/clinic-case-workspace-regression.cjs
node tests/clinic-subrole-workflow-regression.cjs
```

`php artisan test --filter='AlpineModalCloakInterfaceTest|StaffSettingsInterfaceTest' --compact` passed: 8 tests, 138 assertions. PHPUnit could not read its bootstrap inside the sandbox; the unchanged command passed outside the sandbox using the existing testing configuration.

Browser tests use the already-installed Playwright package through `PLAYWRIGHT_PATH`, headless Microsoft Edge, and isolated local API fixtures. Initial concurrent Clinic reruns encountered document-load timeouts. The Clinic harness now matches the existing sidebar suite's 30-second navigation allowance while retaining 15-second UI assertions and all request-count expectations.

All new Clinic navigation cases passed for Admin, veterinarian and clinic receptionist: real hamburger activation through the nested shell, backdrop closing, permitted Dashboard/Customers/Grooming navigation and return, active highlights, unchanged border/shadow on pointer activation, blue keyboard focus and Enter activation, 320/375/414/768/960/1024/1440px geometry, scaling, and cached hard refresh with no extra identity request.

The original first-render cases passed in a focused `CLINIC_RENDER_ONLY=1` run: stable mobile header before/after Alpine, no premature error UI, cold startup, navigation, cache-bypassed reload, lazy records, secondary data deferral and denied/identity/cases failure states. A stale startup expectation omitted the already-existing `/api/admin/notifications?mode=count` request. Running with `CLINIC_BASELINE=1` reproduced the identical five-request startup and assertion failure using unchanged `HEAD` Clinic sources. The expectation now includes exactly one notification count request; all other strict counts remain. Cached hard refresh makes one session, one grooming activity and at most one Clinic cases request, with zero `/me` calls.

The existing sidebar suite passed cold paint, all three role page sweeps, normal/hard refresh, cache ownership/expiry/malformed data, revision invalidation, logout, lock/unlock, expiration and pre-Alpine cached identity/permissions. Its Inventory case reached its hard-reload loop and hit a 10-second event timeout. `tests/admin-sidebar-first-paint-browser.cjs` now gives both hard-reload event waits the same 30-second navigation allowance it already configured; UI and business assertions are unchanged. The focused `LOCAL_REVIEW_CASES=inventory` rerun passed all direct/query/action/invalid routes, reloads, return from Clinic and mobile checks. Browser cases passed across these focused runs; the initial combined runs did not complete successfully before the documented harness/assertion corrections.

`npm run build:css`, JavaScript syntax checks for both browser scripts and `git diff --check` passed. The CSS build emits the existing outdated Browserslist database notice; no dependencies were updated.

Browser zoom is checked by emulating the corresponding CSS viewport and device scale at 80%, 125%, 150% and 200%, plus a separate 125% root-font scaling check. Physical devices and other browser engines are outside this local verification.

Completed locally on `gerald-frontend`. No application regressions were found in the executed checks. No commits, pushes, deployments, production access, database changes or migrations were performed. The mobile preview in the chat's visualization directory uses synthetic local fixture cases.
