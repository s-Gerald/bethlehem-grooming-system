# Admin/Staff sidebar first-paint review — 2026-10-19

Implemented locally on the existing `gerald-frontend` branch. No branch changes, commits, pushes, deployment, live-server access, or database changes were made.

## Root cause and behavior

Navigation and Logout were inside `sidebarReady`/`x-cloak` regions, with a second CSS gate tied to `data-admin-ready`. A navigation spinner occupied the cold sidebar instead of its persistent shell. Account presentation and Logout shared the same visibility dependency. Invalidating identity also left the previous permission fields populated.

All 11 shared sidebar consumers now supply common links, headings, local sprite references, current-route styling and Logout directly in HTML. Permission-sensitive links start hidden individually. The shared script synchronously applies valid cached identity, permission visibility and route state before Alpine; cold loads retain the common shell with a small account-only placeholder. Every account card now uses the same name/initials/role bindings, including nested Inventory.

The existing `data-admin-navigation` attribute remains an interaction boundary for the central session gate, not a readiness visibility gate. Navigation and footer start inert. They stay visible and inert during `/session` verification; the existing locked screen still conceals the entire portal. Once the session is verified, Logout works independently of a pending identity request.

Identity invalidation clears permission fields, account details and dynamic indicator state immediately. Request revisions prevent queued identity work from restoring a signed-out account. Existing API cache ownership, age, account/token isolation, server permission revision checks, request deduplication and authorization remain unchanged. `scripts/api.js` and `css/session-lock.css` were inspected and require no changes.

## Changed files

- `scripts/components/admin-sidebar.js`
- `css/admin-loading.css`
- Shared HTML: `pages/admin/dashboard.html`, `clients.html`, `appointments.html`, `clinic.html`, `services.html`, `archive.html`, `transactions.html`, `reports.html`, `settings.html`, `notifications.html`, and `pages/admin/inventory/inventory.html`.
- `tests/admin-sidebar-first-paint-browser.cjs` (new)
- `tests/admin-sidebar-cache-regression.cjs`
- `tests/admin-loading-regression.cjs`
- `tests/admin-loading-browser.cjs`
- `tests/Feature/AlpineModalCloakInterfaceTest.php`
- `tests/Feature/StaffSettingsInterfaceTest.php`
- `docs/superpowers/plans/2026-10-19-admin-sidebar-first-paint.md`
- This review.

The loading regression's Settings VM fixture lacked `document` before this change. Adding its missing document object lets the existing behavior assertions run; no Settings production behavior was changed. Obsolete tests requiring a cloaked whole sidebar, spinner, or JavaScript-only Settings reveal were updated to the persistent-shell contract, with behavior covered in the browser regression.

## Verification

- Six focused Node scripts pass: sidebar cache, Admin loading, universal loading, frontend auth/session, session inactivity, and grooming sidebar indicator.
- Focused PHP checks pass: `SessionLockTest`, `AlpineModalCloakInterfaceTest`, `StaffSettingsInterfaceTest` — 18 tests, 262 assertions. The existing PHPUnit configuration uses in-memory SQLite.
- JavaScript syntax checks and `git diff --check` pass. No production build is needed for these plain scripts, existing Tailwind classes and hand-maintained CSS.
- Browser regression covers actual shared page HTML/CSS and the real API/sidebar scripts with local API fixtures. It initializes the sidebar's Alpine scope independently of unrelated page workspaces. It exercises script-delayed cold paint; Admin, veterinarian and grooming Staff; per-page active states; normal and CDP cache-bypassed hard reload; desktop and 320/375/414/768px drawers; stale/malformed/foreign-account caches; permission revisions; Logout; full lock/unlock; expiration; pre-Alpine cached identity; and stable Logout positioning during account hydration.
- Browser checks passed in focused runs: all 11 Admin pages, 9 veterinarian Staff pages, and 8 grooming Staff pages; normal and cache-bypassed hard reloads; four mobile widths for each role; cold paint; invalid caches; pre-Alpine hydration; revision changes; Logout; lock/unlock; and expiration. The cold run reproduced the old disappearance before implementation. Each role made exactly one initial `/me` request and zero additional `/me` requests during the cached page sweep and both refresh modes. An unchanged `/session` revision also avoids identity refetch; a changed revision fetches it once.
- Signed-in local browser smoke checks included Dashboard, Clinic, Inventory and Settings. Inventory overview data loaded, and Products submenu navigation worked. The browser was returned to Dashboard. No user account was signed out or modified; authentication edge cases use isolated fixture contexts and PHP tests.

Browser screenshots are stored outside the repository in the chat's visualization directory: `sidebar-cold-paint.png` and `sidebar-cached-checking.png`.

## Reproduce the browser regression

Use an installed Playwright package via `PLAYWRIGHT_PATH` if needed, then run `node tests/admin-sidebar-first-paint-browser.cjs` while the local application serves `http://127.0.0.1:8000`. The harness refuses non-local API origins and mocks API responses; it does not require real tokens or database writes. `LOCAL_REVIEW_CASES` can select `cold,cache,security,invalid,pre-alpine`; `LOCAL_REVIEW_PAGES` can restrict the cached page sweep, and `LOCAL_REVIEW_SCREENSHOTS` optionally selects an evidence directory.
