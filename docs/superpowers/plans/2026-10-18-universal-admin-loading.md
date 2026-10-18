# Universal Admin Loading Implementation Plan

**Goal:** Match My Pets' content-only Phosphor loading throughout the existing admin/staff HTML pages without reverting request optimizations or authorization.

**Architecture:** One local `admin-loader` element and stylesheet supply presentation. Existing Alpine data owners supply initial/loading/loaded/error states. Static headers stay outside data-loading regions; navigation appears after the shared authenticated identity read, independently of page data. Separate HTML navigation and current backend routes remain.

**Constraints:** Local `gerald-frontend` only; no push, deployment, production access or migration. Preserve design, workflows, permissions, lock/unlock/logout, caches, lazy resources and existing endpoints.

## Work

- [x] Trace My Pets, all ten sidebar destinations, lazy panels and relevant existing tests.
- [x] Add focused failing behavior tests for retained refresh data, cached tab reuse, stale results and authorization-dependent sidebar readiness.
- [x] Introduce the shared local Phosphor element and replace skeleton/legacy loading presentation in pages and internal panels.
- [x] Normalize initial and background loading state where existing components discard or conceal loaded data; preserve request contracts and mutation refreshes.
- [x] Keep correct headers outside initial data waits, including Inventory fragment headers, and remove sidebar placeholder bars.
- [x] Verify local admin/staff desktop/mobile transitions with delayed responses, cached data, tab changes, errors, session lock and logout; run relevant regressions and update the review notes.

## File responsibilities

- `scripts/components/admin-loading.js`, `css/admin-loading.css`: shared markup, local sprite, motion/accessibility and scoped shell behavior.
- `scripts/components/admin-sidebar.js`: authenticated navigation readiness without clock/page-data blocking.
- `pages/admin/*.html`, Inventory/Services/Clinic fragments: static headers and affected-region loaders.
- Relevant `scripts/components/admin-*.js`: data readiness and retained refreshes; lazy panel/header handling where necessary.
- Focused Node/browser regressions and `docs/admin-loading-performance.md`: behavior, preserved request counts and verification evidence.

## Review focus

- Verification/lock must conceal permission-based links and account identity.
- New filters must not label old records as current; failed background refreshes may retain only matching results.
- Empty loaded lists are data, and must not restart initial loading.
- Lazy panels must preserve controls, refs, revision invalidation and action handling when headers move.
- Fast/cache hits must settle before paint without a timer or minimum spinner duration.
