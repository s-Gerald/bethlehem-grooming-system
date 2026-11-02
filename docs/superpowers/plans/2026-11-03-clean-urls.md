# Clean URLs Implementation Plan

**Goal:** Serve every active Bethlehem browser page at a canonical Laravel URL while preserving workflows, assets, permissions and old bookmarks.

**Architecture:** Use a single tracked PHP page map to register named page routes and legacy redirects. Continue serving existing HTML files without sessions; reuse the existing API cookie, token guards, account/session checks and controllers for protected administrative documents. Keep dynamically loaded fragments at their existing resource URLs.

**Tech Stack:** Laravel 12, existing HTML/JavaScript, Apache mod_rewrite, PHPUnit and Node regression scripts.

**Spec:** User request “Implement Clean, Application-Defined URLs System-Wide”, 2026-11-03.

## Constraints

- Preserve existing UI, API endpoints, authentication rules, cache policies and lazy loading.
- No SPA/Blade migration, redesign, new dependencies, hosting-directory migration or unrelated refactor.
- Root-relative browser resources; retain script-relative ES module imports.
- Permanent redirects preserve queries; fragment inheritance remains browser-managed.
- Apply repository AGENTS.md; execute in the current branch without commits or worktrees.

## Review Focus

- Physical HTML must reach Laravel before Apache's existing-file shortcut in both supported layouts.
- Protected pages must use the same Sanctum and role/permission checks, including old cookie scopes after deployment.
- Notification return destinations must remain same-origin and allowlisted, preserving inventory hashes and selected records.
- Shared customer/staff confirmation code must retain context-specific destinations.
- Cache-busted scripts and preloads must agree; dynamic fragments and modules must retain their loading behavior.

## Stage 1: Server routing and compatibility

Files: config/frontend.php, routes/web.php, app/Http/Controllers/FrontendPageController.php, bootstrap/app.php, both .htaccess files; existing cookie middleware, protected controllers and relevant feature tests.

- [x] Extend route tests with literal canonical/legacy pairs, query-preserving redirects, unknown-route 404s, cache/session checks and fetched-fragment access. Run before implementation to confirm missing canonical routes.
- [x] Register explicit mapped routes and aliases; route protected documents through their existing controllers and exact guard stack.
- [x] Widen only the account-session cookie scope for protected page navigation; retain remembered-device scope. Expire the old cookie path when upgrading and on logout. Provide an authorized API-page bridge for still-valid legacy cookies without changing token lifetimes.
- [x] Update guest redirects and protected-document asset bases. Retain sensitive-file deny rules; send browser HTML and the index through Laravel before file shortcuts.
- [x] Extend existing Apache fixtures and real-cookie authorization tests. Verify both Apache layouts, legacy cookie migration, denied staff/customer access and API compatibility.

## Stage 2: Navigation and resources

Files: index.html, active pages and protected HTML documents, affected scripts/api.js, auth/components/services scripts, generated email/payment/notification URL producers, existing navigation/auth tests.

- [x] Exercise clean customer/staff paths in existing auth and sidebar regression harnesses before edits.
- [x] Rewrite active browser links to canonical destinations and shared resource references to root-relative URLs; preserve query/hash state and relative ES module imports.
- [x] Update API route classification, staff restrictions and sidebar allowlists using canonical routes; retain notification origin validation and inventory selection.
- [x] Correct dynamic fragment/script loaders and shared confirmation destinations. Update outgoing verification, recovery, notification and payment links.
- [x] Bump affected first-party JavaScript references transitively without removing existing cache parameters.
- [x] Verify navigation, login/logout/session restoration, registration and lazy loading with existing regression scripts.

## Stage 3: Verification and deployment documentation

Files: affected existing tests, docs/clean-urls.md.

- [x] Inspect remaining old URL references and distinguish deliberate legacy tests, backing file paths and resource fragments from obsolete navigation.
- [x] Verify canonical pages, local document assets and fragments through Laravel; verify redirects and sensitive-file protections through Apache.
- [x] Run the relevant PHPUnit tests and frontend regression suite; investigate failures at their source, retaining meaningful coverage.
- [x] Record complete old-to-new URL mapping, validation results, supported layout details, cookie migration and exact deployment commands. Review final diff for scope and whitespace.

Final verification: 2,215 PHP tests passed (9 MySQL-only skips), 92 frontend scripts passed; real Edge, both Apache layouts, cached routes/config, syntax, unchanged visible markup and whitespace checks passed. Complete mapping and deployment notes: `docs/clean-urls.md`.
