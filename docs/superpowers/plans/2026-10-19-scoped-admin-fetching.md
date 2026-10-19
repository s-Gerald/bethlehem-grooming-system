# Scoped Admin Fetching Implementation Plan

**Goal:** Reduce Services, Inventory and History requests while preserving workflows on gerald-frontend.

**Architecture:** Extend the existing API presentation cache. Preserve demand-driven shells and mounted sections; use retained-data-first callbacks to release shell loading as soon as usable data arrives. Add an Overview variant to inventory summary that reuses expiry computation and returns bounded previews without report-only aggregates.

**Constraints:** Local only; no deployment, push, schema changes, new cache manager, prefetch-all or redesign. Inventory presentation freshness 5 seconds / retention 30 seconds; Services 60 seconds / 5 minutes. Search/filter variants memory-only. History 5 seconds / 15 seconds memory-only because existing responses contain payment and medical detail fields.

1. Extend behavioral cache regression tests for policies, ownership, deduplication, successful/failed invalidation, cross-tab signals and excluded operational endpoints. Run red; implement in scripts/api.js; run green.
2. Update Overview request-contract and backend preview tests. Run red; add view=overview to InventoryController::summary and InventoryAPI.getSummary; adopt loadAdminData in overview/products/movements. Keep same-document revision behavior and expose loaded retained content through shell callbacks. Verify deep links and navigation races.
3. Remove scanner from section entry dependencies. Load it through the existing script loader from ProductForm.openBarcodeScanner; preserve cancellation before/after library and camera startup. Keep invoice dependencies because the receipt template synchronously consumes them.
4. Defer inactive Services component and Clinic money input script. Start default Admin Grooming without awaiting Clinic access; await access before mounting Clinic. Replace forced list reads with retained-data-first loading and group-aware activation.
5. Replace History component cache with loadAdminData; preserve markup debounce and strengthen immediate query race rejection. Keep detail requests on demand. Update obsolete test stubs to exercise the central cache.
6. Compare A–E using localhost CDP Network/resource timings and repeatable VM scenarios. Run focused frontend/backend checks and full npm test; document request counts, payload/timing limits, changed files and remaining server bottlenecks.

Review focus: mutations racing reads; failed mutations; Staff/role ownership; scanner navigation during dependency/startup; retained shell content visible before revalidation; History details never persisted.
