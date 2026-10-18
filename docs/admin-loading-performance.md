# Admin/staff loading review — 2026-10-18

## Current universal loading UI

Implemented locally on `gerald-frontend`. No push, deployment, production access or migration.

### Findings and changes

- All ten sidebar destinations, plus the Notifications page using the same sidebar, now share `scripts/components/admin-loading.js` and `css/admin-loading.css`. The lightweight native element renders My Pets' local Phosphor `spinner-gap` and `Loading...`, with accessible status semantics and reduced-motion support. No framework, artificial delay or minimum display time was added.
- Removed sidebar placeholder bars and content skeletons. Static branding, surfaces, titles and subtitles render independently; actual navigation and account information wait for authenticated identity. Navigation readiness no longer waits for the clock or page datasets. The checking and locked session gates still conceal protected information.
- Fresh navigation previously verified `/session` once at script startup and again at the initial `pageshow`, briefly concealing an already authorized sidebar. It now verifies once on startup; persisted back/forward restores, focus, visibility changes, cross-tab state and inactivity retain their verification behavior.
- Dashboard headings are immediately `Admin Dashboard` or `Staff Dashboard`; staff identity stays in the account area. Inventory section headings moved from lazy fragments into the existing shell, preserving controls and their live panel handlers.
- Initial data, confirmed empty results and background refreshes have separate readiness states. Customers, History, Transactions, Clinic, Inventory, Services, Reports, Settings and Notifications show loaders only for pending regions. Matching loaded results remain visible during refreshes/errors, with Retry actions. New queries do not present old results as current. Available pet profile information displays independently of its histories.
- Existing Grooming tab reuse, compact endpoints, API cache/deduplication/invalidation, polling safeguards, scanner deferral and backend action validation remain. Customers/History use bounded 15-second presentation reuse for loaded filters, guarded by the existing API invalidation revision. Confined Clinic patients continue to refresh on entry while retaining available content.

### Local verification

- `npm test`: **69/69 regression scripts passed**; subsequent focused History, Clinic Services and universal-loading regressions passed after the final readiness/retry changes.
- Focused Laravel interface/security suite: **271 passed, 1 existing MySQL-only test skipped under SQLite**. Workflow suite: **286 passed**. Coverage includes session locking/unlocking/expiry/logout, Dashboard endpoints, staff account permissions, Clinic/Inventory interfaces, Services, Reports, Grooming payments and cancellations. Test runs use isolated local test databases and fake external services.
- Headless Edge against localhost with temporary existing admin, Grooming staff and Clinic staff sessions: all accessible sidebar destinations, plus Notifications; delayed initial data; correct headings; content-only local sprite loaders; reduced motion; desktop/mobile sidebar; cached Dashboard navigation; failed-refresh retention/Retry; lazy Security and reuse; all six Inventory sections and Add Product handler; both Services/Reports panels; Clinic filters; Settings Availability and Blocked Dates.
- Request assertions retain **zero full-booking reads on Dashboard**, **one Grooming board read**, **zero extra reads across the five Grooming tabs**, and reused Security/Services/Reports panels. Fresh navigation no longer makes the duplicate initial session check.
- With server session verification deliberately held pending, navigation and account details remain concealed while the static Settings title is visible. Actual server inactivity locks concealed the sidebar, rejected incorrect passwords and allowed logout for all three accounts. Existing automated tests also cover successful unlock and absolute expiry.
- JavaScript syntax checks and `git diff --check` pass. Temporary review tokens and private helper/fixture files are removed after testing. No generated CSS build or migration is needed.

The optional browser regression accepts `PLAYWRIGHT_PATH`, `LOCAL_REVIEW_SESSIONS`, optional `LOCAL_REVIEW_PAGES` (comma-separated names), `LOCAL_REVIEW_VERIFY_GATE`, `LOCAL_REVIEW_LOCK_HELPER` and `LOCAL_REVIEW_SCREENSHOTS`. Session fixtures are private local files containing `{admin: {token, role}, staff: {token, role}, clinic: {token, role}}`; never use production sessions. Loader delays exist only in this test harness.

### Limitations and review

Separate HTML documents still perform fresh server authorization on navigation; permission-based menus are never shown from a storage hint alone. Existing Alpine/Lucide CDN dependencies remain. These checks establish loading/request behavior, not a new Core Web Vitals timing claim. External payment settlement and the MySQL-only concurrency case were not executed. Review normally with local accounts before approving a push; no production migration steps are needed.

## Original performance implementation (retained historical measurements)

The following records the earlier optimization baseline. Its skeleton presentation is superseded by the universal loader above; its endpoint, caching and polling optimizations remain.

Implemented locally on `gerald-frontend`. No push, deployment, live-server access, production database change or migration.

## Bottlenecks and changes

- The session stylesheet hid the entire document during every server session check, while the outer Alpine `x-cloak` also hid the shell. The three scoped pages now display only static branding, surfaces, header titles and placeholders during verification. Permission-based links, identities and data stay concealed. The actual locked state still conceals the entire shell.
- Dashboard initialized the full Grooming controller and fetched workflow details before notifications/status. It now initializes compact summary/queue-preview and recent-activity reads independently, alongside notifications. Grooming action initialization and payment restoration run only on Grooming.
- The sidebar fetched full booking/payment/workload data just to display a dot. Dashboard/Grooming now publish their existing response's count; other sidebar consumers use an authenticated, Grooming-authorized count endpoint.
- Grooming's board response omits Dashboard-only revenue and recent activity, retaining its visible Today/Week totals, capacity, estimates and every workflow list. Tab changes reuse those lists. The scanner library loads only when the payment scanner is opened.
- Selected admin presentation reads share in-flight requests and a bounded, per-tab, session-scoped 10–15 second cache. Mutations, account/logout changes and cross-tab mutation signals invalidate it. Responses from before invalidation cannot repopulate it. Backend action validation remains authoritative; online payment status is never cached here.
- Nonessential polling pauses while hidden/locked, resumes on visibility, prevents overlap and cleans up on component destruction. Verified unlock invalidates presentation caches and immediately refreshes previously loaded Dashboard/Grooming content and sidebar state. Date-selection and mutation races cannot overwrite newer Grooming data.
- Security loads Staff Accounts on first opening, retains them between Settings tabs and during refresh, and refreshes after existing staff mutations. Overlapping refreshes share a request; older results cannot replace newer results.
- Scoped skeletons replace initial statistics, counts and lists. Genuine loaded zeros/empty states, errors and retained-data refreshes remain distinct. Retry and reduced-motion behavior are covered.

## Local comparison

Original committed HTML/JS was served inside an isolated browser for comparison; the checkout was never reset. The after run used the working files. Both used the same local Laravel application and existing local accounts.

| Initial navigation | Before | After |
| --- | --- | --- |
| Dashboard | 2 full-booking reads; 2 clinic-status reads | summary + activity reads; 1 clinic-status read; no full-booking read |
| Grooming after Dashboard | 2 full-booking reads; 2 clinic-status reads | 1 board read; fresh status/notifications reused |
| Settings General | Staff Accounts + full-booking read | no Staff Accounts read; small activity count |
| Switching all 5 Grooming tabs | local list switching | preserved; 0 extra requests |

The SQLite regression fixture measured **33 queries / 3,882 bytes** for the full bookings response, **18 queries / 356 bytes** for the compact Dashboard summary, and **1 query / 22 bytes** for the activity indicator. Summary measurements exclude the independently loaded recent-activity response.

A single local `networkidle` sample was Dashboard 4.74s → 5.23s, Grooming 6.17s → 4.21s, Settings 3.76s → 3.45s. These include CDN/dependency loading and browser/server variability, and do **not** establish Core Web Vitals or a Dashboard wall-clock speedup. The repeatable improvements are fewer heavy requests, smaller payloads, less query work and a visible shell during verification. Full document navigation remains; there is no SPA router.

## Verification

- `npm test`: **68/68 regression scripts passed**.
- Focused PHP coverage: **409 tests total; 1 existing MySQL-only concurrency test skipped under SQLite**. Coverage includes Dashboard summary/preview, Grooming authorization and actions, payments/PayMongo/cancellations, staff account management/permissions, privileged credentials, and session expiry/lock/unlock/logout.
- New focused Node regressions exercise read deduplication, navigation cache reuse, mutation invalidation/races, account isolation, lazy Security, retained staff refresh, A → B → A date races, post-mutation refresh during a pending poll, hidden polling and cleanup.
- Headless Edge against local Laravel with temporary sessions for existing admin/Grooming staff accounts: delayed Dashboard/Grooming/Staff Account skeletons; no initial zero; exact KPI number-slot geometry; reduced motion; failed-refresh retention and Retry; one board request; five-tab switching without requests; mobile sidebar; lazy Security and reused results; actual server inactivity lock, incorrect-password rejection and logout. No unexpected browser/API errors.
- The existing session regressions additionally verify successful password unlock and absolute session expiration. Temporary browser-review tokens were revoked and credential/helper files removed.
- JavaScript syntax checks and `git diff --check` pass. No Tailwind/build changes or migrations are required for the new directly linked stylesheet.

The optional `tests/admin-loading-browser.cjs` uses an installed Playwright package (`PLAYWRIGHT_PATH`) and a private local admin/staff session fixture (`LOCAL_REVIEW_SESSIONS`); it is intentionally outside the default regression runner. Screenshot output is optional through `LOCAL_REVIEW_SCREENSHOTS`. The automated API/workflow tests use isolated SQLite and fake notification/payment setup. Real external payment settlement was not performed.

## Remaining local review

Review Dashboard, all Grooming workflows and Settings → Security normally before approving a push. The MySQL-only concurrency case remains a separate local MySQL check. Cold navigation still depends on the existing Alpine/Lucide CDNs; those dependencies and unrelated sidebar sections were not migrated or redesigned. No production migration instructions are necessary.
