# Intake Availability Implementation Plan

**Goal:** Make Settings → Availability the sole Admin/Staff owner of today's Clinic and Grooming intake controls, without sidebar or Dashboard status requests.

**Architecture:** Keep existing Laravel endpoints and API cache invalidation. Move intake and the Settings-only Block Dates state into `admin-settings.js`; retain sidebar identity, navigation, session handling, and Grooming activity. Refresh intake on Availability entry, resume, before confirmation, and after mutations; deduplicate concurrent reads.

**Constraints:** Local `gerald-frontend` branch only. No commits, branch changes, push, deployment, production access, migrations, or backend behavior changes. Preserve existing Bethlehem UI conventions.

**Files:** Modify `scripts/components/admin-sidebar.js`, `admin-dashboard.js`, `admin-settings.js`; all Admin templates containing obsolete intake controls/modals; `pages/admin/settings.html`; `css/custom.css`; focused sidebar cache, navigation performance, universal loading regressions. Add `tests/admin-settings-intake-regression.cjs`. Inspect existing Laravel closure/authorization/customer restriction tests and run them locally with the existing in-memory SQLite configuration.

1. Add Settings behavioral tests for lazy reads, deduplication, admin confirmation/actions, staff denial, errors/retries and freshness. Update obsolete Dashboard/sidebar request expectations and Block Dates component ownership. Run to observe the expected failures.
2. Move intake and Block Dates ownership into Settings. Remove obsolete state, handlers, initialization, listeners, polling and markup from sidebar/Dashboard/templates. Verify the focused regressions and source references.
3. Add a compact, responsive Today's Intake card before the existing Availability content and a Settings-owned confirmation dialog. Reuse existing card/button/icon styles. Add native thin navigation scrollbar styling without changing the existing flex scrolling structure.
4. Run relevant JS regressions and Laravel tests. Inspect the running local UI, confirmation/cancellation, responsive layout, navigation scrolling and errors. Review the final diff for scope and report verification limits.

**Review focus:** Stale status at confirmation; duplicate submissions; failed reads/mutations retaining retry paths; staff direct method calls; Block Dates resolving in the correct Alpine scope; status reads after leaving Availability; session lock and resume; no loss of Dashboard data or Grooming activity.
