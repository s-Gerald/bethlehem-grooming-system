# Inventory receipts and workflow

Goal: implement the user's Inventory request on gerald-frontend without changing stock allocation, reservations, POS/Clinic integration or cache policy.

Architecture: retain the mounted Alpine sections and shared API cache. Persist new receipt references in the ledger, retaining the accessor's historical SI fallback. Extend the existing shared success-toast component with semantic errors rather than add another toast system.

## Constraints and decisions

- No manual browser functional tests; visually inspect the dashboard and Stock In layout. The user handles functional navigation and data entry.
- Medicines/vaccines require a manufacturer lot or an explicit saved reason for its unavailability. Never substitute internal references for manufacturer lots.
- Receipt prefix comes from the locked product name; 10 random characters exclude I, O, 0 and 1. The database unique index is authoritative.
- Five insert attempts maximum; PostgreSQL savepoints isolate unique collisions within the caller's stock transaction. MySQL/SQLite retry the insert without extra savepoint round trips. No reference-existence query.
- Existing SI references and ledger IDs are not rewritten. Generated references cannot be submitted or changed through the API.
- Preserve existing Stock Out physical quantity validation and FEFO projection behavior.

## 1. Receipt persistence and validation

Files: app/Models/InventoryTransaction.php, app/Services/InventoryStockMovementService.php, app/Services/InventoryBatchBalanceService.php, app/Http/Controllers/InventoryController.php; new database/migrations/2026_10_23_000001_add_inventory_receipt_references.php.

Tests: update tests/Feature/InventorySecurityRegressionTest.php and the receipt schema in tests/Feature/ClinicInventoryPricingTest.php. Cover random prefix/format, multiple expiry receipts, unique constraint, injected collision and retry exhaustion with rollback, historical fallback after rename, immutable reference, prohibited overrides, required lot/documented absence and unchanged query counts. Add simultaneous receipt coverage using isolated SQLite worker processes; document database-driver limits.

Run focused PHPUnit regressions before and after implementation. Apply the actual migration only after inspecting its generated SQL; it adds nullable columns and a unique index without backfilling historical identifiers.

## 2. Shared notifications and workflow navigation

Files: scripts/components/success-toast.js, admin-inventory.js, admin-inventory-items.js, admin-stock-in.js, admin-stock-out.js; pages/admin/inventory/inventory.html and sections/products.html, overview.html, stock-in.html, stock-out.html, history.html; css/components/admin-inventory.css.

Extend the shared toast with success/error variants and accessible live announcements, preserving showSuccessToast callers. Load toast.css in the shell, remove Products' duplicate toast markup/state and Stock In's persistent receipts panel/state.

Use hash parameters for product ID and validated return section/filter. Fetch preselection once from getItem, reject inactive/deleted/unauthorized products, preserve pending entries, guard stale navigation responses, and retain mounted search/paging state. Return links point to the originating Products filter or Overview. Revalidate selected stock following inventory mutations without duplicate reads.

Tests: extend existing frontend stock-in/out, items, navigation and inventory UI regressions; exercise shared toast DOM behavior in the existing inventory UI test. First establish failing regressions for missing lot validation, semantic error feedback and preselected navigation. Keep existing scanner, pending entries, expiry and quantity coverage.

## 3. Layout and verification

Use existing Bethlehem colors/type and controls. Remove max-width restriction from stock workflow, use two columns for entry/pending at desktop, stack below desktop, retain accessible sticky submit controls. Add minimal lot-unavailable controls and show saved reason in existing receipt records.

Run affected frontend scripts and PHPUnit suites, then related POS/Clinic regressions and npm build because Tailwind classes and shared toast consumers are affected. Review diff, remove obsolete expectations/selectors, report migrations, test counts and any environment limitations.

## Review focus

- Collision exhaustion after earlier items must roll back every stock increment and receipt.
- Fast product changes must never select a stale response or load hidden data.
- Returning from a workflow must retain a Products alert filter and current page.
- Missing lots must remain null and have a separate explanation, including through history/details.
- Concurrent requests must produce distinct references and exact summed stock, using a disposable database only.

## Verification completed

- 186 affected Laravel tests passed with 2,882 assertions using the project's portable PHP 8.4 runtime and isolated SQLite databases. Includes Inventory, Clinic pricing/vaccinations, Grooming product sales, shared payment flows and the settings toast consumer.
- All 76 frontend regression scripts passed. Coverage includes toast replacement/error announcements, lot validation, current product selection, stale responses, permission failures, retained filters/page/scroll, request counts and existing Stock In/Out behavior.
- Two overlapping authenticated stock-in requests passed against an owned temporary SQLite database. MySQL concurrency was not separately exercised.
- Production build, changed PHP/JavaScript syntax checks and git diff whitespace checks passed. The build reports an existing outdated Browserslist-data warning.
- Applied only 2026_10_23_000001_add_inventory_receipt_references to local MySQL; verified migration status as Ran. Historical rows were not backfilled.
- Visually inspected the dashboard and the desktop two-column Stock In page. No browser data entry, OTP or password-reset tests were performed.
