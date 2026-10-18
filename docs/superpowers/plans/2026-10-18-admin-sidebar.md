# Admin/Staff Sidebar Implementation Plan

**Goal:** Keep verified navigation stable across local page loads, use local Phosphor icons throughout Admin/Staff, and preserve server authorization and session locks.

**Scope:** Existing `gerald-frontend` branch only; no push, deployment, production access, environment changes, or database migrations. User task in this chat is the specification and authorizes implementation and local testing.

**Architecture:** Separate session-scoped navigation presentation from short-lived dashboard/clinic caches. Keep required `/session` validation and add a revision derived from already-loaded user attributes to invalidate presentation after permission or profile changes. Render verified cached presentation synchronously before Alpine, retain noninteractive navigation while checking, and conceal everything while locked.

1. Add behavioral regression coverage for navigation reuse, invalidation, account/race isolation, role presentation, and independent dynamic requests. Verify failures before implementation.
2. Update `scripts/api.js`, `scripts/components/admin-sidebar.js`, `css/admin-loading.css`, and Admin sidebar markup. Keep cache data minimal, versioned, sessionStorage-only, and scoped by API origin and authenticated token ID/role. Verify cache misses and failed verification never expose menus.
3. Add shared user navigation revision to `app/Models/User.php`, `/me`, and `/session` responses. Verify revision changes and session lock/expiration using the isolated SQLite test environment.
4. Replace static/dynamic Admin icons with SVG/use references; extend `assets/icons/phosphor.svg` with official regular-weight symbols as needed. Remove Admin Lucide imports, scan/observer code, and orphan refresh callbacks. Preserve shared consumers and all flow logic.
5. Verify focused Node/PHP regressions, script syntax, all local SVG targets, desktop/mobile sidebar interactions, and local browser network requests on navigation/reload. Record actual results and limitations in a local review note.

Review focus: old in-flight identity after account change; permission changes while a page is open; malformed/unavailable sessionStorage; lock/unlock and BFCache; dynamic Alpine icons and nested inventory/walk-in asset paths.

Completed locally. Verification results and the full changed-file manifest are recorded in `docs/admin-sidebar-local-review.md`.
