# Inventory Lifecycle and Sorting Implementation Plan

**Goal:** Add trustworthy current deactivation dates and paginated Newest/Oldest Products sorting within the existing inventory UI.

**Architecture:** Persist nullable server-controlled `inventory_items.deactivated_at`; backfill from the latest successful `inventory_archived` activity for subject type `inventory`. Map allowlisted sort input to database ordering after existing filters and before pagination. Reuse existing formatted responses, toolbar controls, Status cell and Product Information section.

**Scope:** User's Inventory Products lifecycle/sorting specification. Preserve permissions, stock/pricing rules, archive/restore logging, expiry urgency ordering, six columns and existing responsive cards. No new dependencies, indexes without evidence, or unrelated refactoring.

1. Add behavioral regression cases in `tests/Feature/InventorySecurityRegressionTest.php` and `tests/frontend-inventory-items-regression.cjs`; run them to establish missing lifecycle/sort behavior. Extend the reduced inventory schema used by these tests.
2. Add `database/migrations/2026_11_10_000003_add_inventory_deactivation_timestamp.php`, with portable set-based backfill and safe rerun behavior. Add migration-specific tests for success/type/ID matching, latest archive dates, nulls, reruns and rollback.
3. Update `app/Models/InventoryItem.php` casting and `app/Http/Controllers/InventoryController.php` lifecycle assignment, formatted response and allowlisted database sorting. Keep the field outside fillable/CRUD validation. Verify lifecycle repeat/edit/reactivation behavior and existing events.
4. Update `scripts/services/inventory-service.js`, `scripts/components/admin-inventory-items.js`, `pages/admin/inventory/sections/products.html` and `css/components/admin-inventory.css`: default sort, request identity, page-1 reload, toolbar wrapping, small Status date and precise local en-PH details date. Expiry continues using its existing endpoint without sort.
5. Run focused Laravel inventory/activity/clinic/POS checks and frontend inventory regressions. Exercise the shipped markup/CSS at 320, 375, 414, 768 and wide desktop widths using browser tooling, including six-column alignment, cards, wrapping, inactive dates and expiry visibility.

**Review focus:** Null dates last in both directions; equal-date item ID tie breaking; combined search/category/inactive/low-stock filters across page boundaries; ordinary edits cannot change dates; migration reruns preserve current dates and never fabricate history.

**Verification:** Completed with PHP 8.4: 115 focused Laravel tests / 2,349 assertions; six inventory, clinic-pricing and POS frontend regression scripts; Products browser coverage at 320, 375, 414, 640, 768, 1024, 1199, 1200, 1280 and 1440 px. Browser coverage includes date displays, reactivation, hidden expiry sorting, toolbar wrapping and all six column boundaries. Reviewed mobile and desktop screenshots. An existing Alpine `x-show`/dynamic style collision on the active stock badge was reproduced and corrected using conditional rendering in the same Status cell.

**Deployment:** Apply the normal production migration before serving the updated endpoint. Backfill and rollback were exercised on isolated SQLite databases; Hostinger/MySQL deployment was not performed in this task.
