# Notification Navigation Implementation Plan

**Goal:** Make existing Admin/Staff notifications accessible across management headers, open exact records, and preserve validated return context.

**Architecture:** Extend the existing sidebar navigation helpers, notification custom elements, shared service/API, and NotificationController. Keep one bell controller per document, moving it between header slots using Alpine's DOM mutation mechanism. Supply an optional selected record alongside the existing paginated response.

**Constraints:** Preserve role visibility, filters, cache ownership, count-only startup, lazy previews, invalidation, and business rules. No dependencies, migrations, commits, pushes, or deployments. Browser inspection uses the existing Admin tab; manual functional testing belongs to the user.

**Files:** Modify `scripts/components/admin-sidebar.js`, `scripts/components/admin-notifications.js`, `scripts/services/admin-notification-service.js`, `scripts/api.js`, `app/Http/Controllers/NotificationController.php`, `css/admin-notifications.css`, the twelve management page templates and Notifications template. Update existing notification Node, PHP, and browser regressions. Regenerate assets using the existing build workflow.

## Review focus

- Reject external, unknown, and malformed return destinations; keep internal URL queries and Inventory hashes.
- Selected old records use bounded lookup and existing role scope, without affecting paginator totals.
- Read mutations happen on the destination and never hide the initial target or duplicate it during Load More.
- Responsive relocation preserves one controller, one preview, and the existing shared count timer.
- Origin styling never implies semantic current-page state or loads the origin workspace.

## Tasks

- [x] Extend existing regression files for validated origins, selected records, read failures, request efficiency, and permission scope; run to confirm failures.
- [x] Centralize known management destinations in sidebar helpers and extend the existing history request with optional `selected_id`; verify focused Node/PHP regressions.
- [x] Add header slots, responsive shared bell placement, targeted navigation, Back link, row focus, and temporary blue highlight; update obsolete interface/browser assertions.
- [x] Run relevant sidebar, notification, cache, and inventory regressions; run the production build and inspect the resulting diff.

The user's execution instructions authorize implementation in this session; the planning skill is advisory under AGENTS.md.
