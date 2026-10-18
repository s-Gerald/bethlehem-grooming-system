# Admin Loading Performance Implementation Plan

**Goal:** Improve local admin/staff Dashboard, Grooming and Security loading while preserving permissions and operational validation.

**Architecture:** Keep multi-document HTML/Alpine navigation. Render only a static, non-sensitive shell during server session verification. Use small Dashboard and sidebar endpoints, a bounded session-scoped presentation cache and shared in-flight reads. Grooming actions continue through existing backend validation and invalidate presentation data.

**Constraints:** Work on `gerald-frontend` locally; no push, deployment, live access or production database changes. Existing Bethlehem visual conventions take precedence over advisory skill workflows. No migrations anticipated.

## Files and responsibilities

- `scripts/api.js`: scoped read deduplication/cache, invalidation and session-safe publication.
- `scripts/components/admin-sidebar.js`: lightweight indicator, coordinated visible polling and cleanup.
- `scripts/components/admin-dashboard.js`: Dashboard-only initialization, Grooming request sequencing, lazy scanner and loading states.
- `scripts/components/admin-settings.js`: lazy Security initialization and retained staff data during refresh.
- `pages/admin/{dashboard,appointments,settings}.html`, `css/session-lock.css`, `css/admin-loading.css`: static shell, section skeletons, error/retry and reduced motion.
- `app/Http/Controllers/AdminBookingController.php`, `routes/api.php`: protected compact Dashboard/indicator responses; preserve full board and action contracts.
- Focused Node/PHP regressions: requests, races, cache isolation, lazy Security, metrics/indicator and security.

## Execution

- [x] Establish baseline requests and existing regression results; inspect local runtime.
- [x] Write failing behavior tests for compact data, deduplication/invalidation, request races and lazy Security.
- [x] Implement compact endpoints and shared reads; verify tests and compare query/response counts.
- [x] Separate initializers, defer scanner and coordinate visibility-aware polling; verify operational regression tests.
- [x] Add stable shell and content skeletons; verify concealed protected content during checking/lock, errors, zero and refresh behavior.
- [x] Test local admin/staff desktop/mobile flows where available; report measured results and any runtime limitations.

## Review focus

- Old reads after mutations, date changes or account switches must never replace newer data.
- Cached presentation must never authorize or settle a payment/check-in decision.
- Locked/unverified sessions must conceal names, statistics, staff accounts and permission-based navigation.
- Background refresh failures must retain existing safe content and expose Retry.
- Hidden documents and destroyed components must not continue nonessential background work.

Verification results and limitations: `docs/admin-loading-performance.md`.
