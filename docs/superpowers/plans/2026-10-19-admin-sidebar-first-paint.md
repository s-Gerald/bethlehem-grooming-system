# Admin/Staff sidebar first-paint implementation plan

**Goal:** Keep the existing navigation shell visible through cold loads and hard refreshes without weakening permission or session checks.

**Architecture:** Static HTML supplies common navigation, active route styling, icons and Logout. The shared sidebar script hydrates only verified identity/permissions; account content has a fixed-size neutral placeholder. Existing API cache ownership, revision checks and session gate remain authoritative.

**Scope:** Current `gerald-frontend` branch, local implementation and tests only. No database changes, branch changes, commits, pushes or deployment.

1. Add failing browser coverage for cold pre-script paint and cached pending-session refresh, and extend identity invalidation coverage. Verify expected failures against current files.
2. Update the 11 shared-sidebar HTML consumers, `scripts/components/admin-sidebar.js`, and `css/admin-loading.css`. Keep permission-dependent links hidden until verified/cached identity is available; reset permission fields on invalidation. Preserve dimensions, drawer, Inventory, routes and session locking.
3. Verify cold/malformed/expired cache, Admin/Staff roles, revision changes, logout/expiration, locked/unlocked sessions, active states across pages, desktop/mobile, and valid-cache `/me` avoidance. Update obsolete whole-sidebar concealment expectations in existing tests. Run focused Node/PHP regressions, syntax and diff checks.

Completed locally. Results and the complete changed-file list are recorded in `docs/admin-sidebar-first-paint-local-review.md`.
