# Services, Inventory and History fetching cleanup

Verified locally on `gerald-frontend`, 19 October 2026. No push, deployment, production access, schema migration or business-data mutation was performed. PHPUnit used its forced in-memory SQLite configuration. Existing page layouts and business rules are preserved.

## Root causes and implementation

- Services bypassed application cache on ordinary list loads. The shell awaited Clinic permissions before default Admin Grooming, and HTML loaded both component scripts. Catalogue/options reads now use the existing API cache and `loadAdminData()`. The shell loads only the active component; Clinic's money-input helper loads with Clinic. Clinic permission verification still precedes mounting Clinic functionality. Service mutations invalidate their own catalogue group; Grooming's existing board/dashboard invalidation is preserved.
- Inventory Overview awaited summary, low-stock and expiry responses together. Summary already computed all expiry alerts; the expiry endpoint repeated that work. The new `/inventory/summary?page=1&view=overview` variant reuses that computation and returns counts, five recent movements and five-item previews. The low-stock preview selects only the displayed columns and preserves endpoint ordering. The variant skips reporting pagination/count queries and the unused 30-day aggregate. The original summary reporting contract and paginated alert endpoints remain available.
- Inventory list/Overview reads had no cross-document presentation reuse. They now share the central cache. A render callback releases shell loading when retained data is available; revalidation can continue while the content remains visible. Mounted sections keep their existing revision-based reuse. Their revision now includes the API inventory group, so cross-tab changes also refresh them on return. Resolving cache ownership before reading revisions avoids an extra revalidation on the first mounted revisit.
- Products, POS, Stock In and Stock Out included the scanner library in their entry dependency lists. They no longer do. `ProductForm` uses the existing Inventory script loader only when scanning starts. The shared script promise loads the library once; a scanner generation guards navigation during both dependency loading and camera startup. Existing camera-stop cleanup remains. Grooming's existing on-demand scanner caller remains compatible.
- History had `_historyCache` alongside the API cache, while ordinary loads defaulted to forced reads. That map is removed. `loadAdminData()` owns list reuse and stale rendering, with request/type/query checks preventing an older search response from replacing a newer query. The existing HTML debounce remains 400 ms. Only the active type loads; signed consent, pet-care requests and attachment downloads remain on demand.
- Sidebar identity readiness is set before its secondary indicator request finishes. Primary components do not await the indicator. The sidebar was inspected and left unchanged.
- POS invoice helpers remain section dependencies: the existing receipt template consumes them synchronously. No extra action-loading framework was introduced.

## Cache contract

All policies, storage, request sharing, ownership checks, retention and cross-tab invalidation remain in `scripts/api.js`; there is no second cache manager.

| Data | Fresh | Retained | Storage |
| --- | --- | --- | --- |
| Default Grooming/Clinic catalogue and Clinic options | 60 seconds | 5 minutes | Existing bounded session presentation cache |
| Default first-page Inventory items, Overview, movements and alert lists | 5 seconds | 30 seconds | Existing bounded session presentation cache |
| Filter/search/non-default page variants | At most 5 seconds | At most 15 seconds | Memory only |
| History lists | 5 seconds | 15 seconds | Memory only |

History is deliberately **not persisted across documents**. Its existing responses include payment fields and Clinic medical detail fields. Persisting them to improve request counts would violate the requested restriction. This also avoids putting the roughly 1 MB archive response in session storage. A separate slim History-list/detail contract is a possible later server task, not part of this fetching cleanup.

Item detail, barcode/POS search, cart validation, signed consent and case detail remain outside presentation retention. Successful inventory/product/stock/POS mutations invalidate Inventory presentation; failed mutations do not. Relevant service and History mutations invalidate their groups. Existing owner/session/role isolation and invalidation-race rejection remain enforced.

## Before/after browser measurements

Captured on the existing authenticated localhost Admin tab using CDP Network and resource timing, with HTTP cache disabled during measurement and restored afterward. Counts below include **page data**, excluding per-document `/session`, session activity and the independently cached/polled sidebar indicator. These secondary requests are not removed or counted as improvements.

| Scenario | Before | After | Reuse/dependencies |
| --- | --- | --- | --- |
| A. Cold Services / hard refresh | 1 active catalogue GET | 1 cold; 0 when its presentation is fresh | Inactive catalogue never fetched. Only active component script loads; Clinic money-input helper waits for Clinic. |
| B. Services → Inventory Overview | 3 Inventory GETs | 1 Overview GET | One expiry computation; no separate low-stock/expiry GETs. Fresh navigation reuse can avoid this GET; stale retained content revalidates once. |
| C. Overview → Products → Stock Movements → Overview | 2 additional GETs | 2 additional GETs | Products and movements each load their own list. Returning to unchanged mounted Overview makes no GET. Scanner library no longer loads on Products entry. |
| D. Inventory → History | 1 active History GET | 1 active History GET | Inactive type, consent and care/detail endpoints are not prefetched. History intentionally remains memory-only. |
| E. History → Services → Inventory again | 1 Services + 3 Inventory GETs | 0 Services + 1 Inventory GET in the quick repeat | Services was fresh. Inventory had passed 5 seconds and revalidated; VM regression tests also prove zero GETs when Inventory is still fresh across documents. |

The cold Overview payload fell from **18,427 bytes** (5,816 summary + 8,379 low-stock + 4,232 expiry) to **3,679 bytes**, about **80% less**. The single critical payload is also smaller than the old summary alone. Cold Overview data completion was **894 ms** for the slowest original response versus **303–338 ms** for the new single response in representative captures. Another return sample was 622 ms versus the original return's 1,281 ms slowest response. These are local samples, not a Core Web Vitals measurement or a statistical benchmark.

Other endpoint payloads did not grow. Products measured 531 ms before and 548/511 ms afterward; History 836 ms before and 843/847 ms afterward. Movements measured 304 ms originally and 295 ms afterward in the section-navigation run. A separate cold deep-link sample was 1,166 ms; three subsequent forced current reads measured 262, 274 and 253 ms. Its server query path was unchanged. These samples show server timing variance, not evidence of an added fetching dependency; no latency improvement is claimed for these unchanged endpoints.

Browser verification covered both Services tabs, Clinic options readiness, all five requested navigation scenarios, direct Stock Movements, Products/Stock In/Stock Out navigation, a refreshed Stock Out deep link, no scanner download on entry, and no new browser exceptions after the final correction. Camera hardware/permission interaction and real payment settlement were not performed; cancellation, teardown, payment and stock behavior are covered by isolated regressions.

## Tests and exact commands

Final results:

- `npm test` — **73/73 regression scripts passed**. The first development run was 72/73 because the existing Grooming Services test stub lacked the new retained-read interface; its stub was updated and the complete suite rerun successfully.
- `php artisan test --compact --filter=InventorySecurityRegressionTest` — **42 passed, 593 assertions**.
- `php artisan test --compact --filter=test_overview_summary_contains_bounded_previews_without_report_queries` — **1 passed, 13 assertions** (also included in the suite above).
- `node tests/admin-data-cache-regression.cjs` — passed: default cross-document reuse, stale-first presentation, deduplication, memory-only filters/History, operational exclusions, ownership, mutation precision/failures, cross-tab invalidation, mutation/read races and online settlement.
- `node --experimental-vm-modules tests/admin-navigation-performance-regression.cjs` — passed: Services active loading/permissions, Staff gating, retained content callbacks, Inventory cross-document reuse, active History and search races, plus existing navigation checks.
- `node tests/frontend-inventory-navigation-regression.cjs` — passed: one-GET Overview, hidden/deep-link isolation, mounted reuse, mutation refresh/failure, rapid navigation, cross-tab revisions, scanner download-on-use/once and interrupted startup teardown.
- `node tests/frontend-inventory-dashboard-regression.cjs` — passed.
- `node tests/frontend-inventory-items-regression.cjs` — passed.
- `node tests/frontend-clinic-services-regression.cjs` — passed.
- `node tests/admin-universal-loading-regression.cjs` — passed; History now exercises the real central cache rather than a component cache.
- `node tests/admin-loading-regression.cjs` — passed; existing assertions remain applicable.
- `node tests/admin-grooming-history-regression.cjs` — passed.
- `node tests/admin-clinic-history-regression.cjs` — passed.
- `node tests/admin-grooming-settings-regression.cjs` — passed.
- `node --check tests/admin-loading-browser.cjs` — passed. Its request contracts/readiness assertions were updated, including waiting out Inventory freshness before the deliberately failed refresh. This optional Playwright runner was **not executed** because its private session fixture was not supplied; the existing authenticated browser was exercised through CDP instead.
- `git diff --check` — passed.

The initial direct PHP invocation could not read the bootstrap inside the restricted sandbox. The same local test commands succeeded outside that restriction, still with PHPUnit's forced testing/in-memory database configuration. The navigation test must use `--experimental-vm-modules`, as the repository's full runner already does.

## Files changed

Production:

- `app/Http/Controllers/InventoryController.php`
- `scripts/api.js`
- `scripts/services/inventory-service.js`
- `scripts/components/admin-services.js`
- `scripts/components/admin-grooming-services.js`
- `scripts/components/admin-clinic-services.js`
- `scripts/components/admin-inventory.js`
- `scripts/components/admin-inventory-dashboard.js`
- `scripts/components/admin-inventory-items.js`
- `scripts/components/admin-inventory-transactions.js`
- `scripts/components/admin-archive.js`
- `scripts/components/product-form.js`
- `pages/admin/services.html`
- `pages/admin/inventory/inventory.html`
- `pages/admin/archive.html`

Regression coverage:

- `tests/Feature/InventorySecurityRegressionTest.php`
- `tests/admin-data-cache-regression.cjs`
- `tests/admin-navigation-performance-regression.cjs`
- `tests/frontend-inventory-navigation-regression.cjs`
- `tests/frontend-inventory-dashboard-regression.cjs`
- `tests/frontend-inventory-items-regression.cjs`
- `tests/frontend-clinic-services-regression.cjs`
- `tests/admin-universal-loading-regression.cjs`
- `tests/admin-grooming-history-regression.cjs`
- `tests/admin-grooming-settings-regression.cjs`
- `tests/admin-loading-browser.cjs`

Documentation: this report and `docs/superpowers/plans/2026-10-19-scoped-admin-fetching.md`.

## Remaining server costs

`/session` commonly took 1.4–1.9 seconds on fresh local documents; authorization verification remains required. Grooming History still returns an unpaginated roughly 1 MB response (about 840 ms in these samples). Inventory expiry counts still inspect active stocked items and reconstruct batch balances; this cleanup removes duplicate computation per Overview visit but does not redesign that server calculation. Secondary indicator timings also vary, but it does not gate the primary content. These are candidates for a separately scoped server investigation.
