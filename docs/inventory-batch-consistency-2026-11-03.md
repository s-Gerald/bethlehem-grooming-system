# Inventory receipt allocation and quantity consistency

Implemented on `gerald-frontend`, 3 November 2026. No branch, commit, or push was created.

## Result

The existing ledger now stores the receiving receipt IDs and quantities consumed by new stock-outs. Ordinary usage and sales allocate automatically by FEFO for expiration-tracked categories and FIFO for non-expiring categories. Staff can choose a particular eligible receipt in Stock Out or the POS cart when there is a meaningful choice. A selected receipt limits the quantity to that receipt's available balance.

Stock Out preserves the two panels and fixed action bar. Used/Sold exclude expired and unknown-expiry stock where expiration tracking requires a valid expiry. Expired requires the actual expired receipt. Damaged and Adjustment support physical receipt selection; existing eligible untracked physical stock is retained without inventing a receipt identity. Pending entries account for both product and receipt quantities, and retain their reviewed order.

Stock In accepts 1–1,000 whole units per product per submission, including cumulative pending entries. New or changed minimum-stock values must be 2–1,000 whole units. Unchanged legacy thresholds remain editable alongside unrelated product fields. The common policy lives in `resources/data/inventory-policy.json`; `npm run build:inventory` generates the frontend copy inside the existing shared product-form component. The normal production build runs this generator.

Invalid quantity text stays visible. Inline feedback disables the existing Add/Save/Checkout action until corrected. No quantity shortcuts, validation modals, or additional checkout steps were introduced.

## Quantity definitions

| Quantity | Meaning |
| --- | --- |
| Physical | Recorded stock on hand, including expired stock awaiting formal removal. |
| Usable | Eligible physical stock under the product category's existing expiration rules. |
| Saleable | Usable stock less unresolved payment reservations; never greater than usable. |
| Expired | Remaining expired receipt stock, still included in physical inventory. |
| Receipt remaining | Received quantity less exact new picks and compatible historical reconstruction. |

Products, Overview, low-stock filters/badges, and expiry alerts use the same backend quantities. Reorder status uses usable stock before reservations. Products distinguish Physical Stock from saleable availability and show No Usable Stock when physical inventory cannot be used. Overview's expiry count is labelled Expiry Alerts because it includes expired and soon-expiring receipts.

## POS, payments, and transaction integrity

Cash and online sales use the shared stock movement service. The completed sale records one sale line per product while a stock movement may contain several receipt allocations. Stock Movements shows those individual receipt references, lots, expiry dates, and quantities without duplicating sale totals.

Product rows are locked in item order. The allocator uses one mutable bulk receipt snapshot for each removal operation, rechecks reservations, and commits allocations and stock changes together. Failed or excessive picks roll back. The overlapping-writer regression covers both receiving and stock removal using independent processes and a disposable SQLite database.

An optional POS receipt selection is frozen in the online-payment snapshot. Its quantity is protected from other removals while payment is unresolved, and settlement consumes that selected receipt once. Existing provider confirmation, duplicate-webhook, cancellation, frozen-price, and reconciliation protections remain covered.

## Historical compatibility

`inventory_transactions.stock_out_allocations` is a nullable JSON column:

- `null`: legacy movement; existing historical FEFO inference remains in use and is labelled historical/inferred in movement history.
- An allocation array: new recorded picks, identified by receipt transaction ID rather than manufacturer lot.
- An empty array: a new removal from eligible untracked physical stock. Mixed tracked/untracked removals record only the genuinely identified receipts.

The migration adds the column without backfilling, rewriting transactions, normalizing reorder levels, or changing physical quantities. Separate receipts sharing a manufacturer lot stay separate. Unknown-expiry or untracked stock in expiration-required categories remains excluded from ordinary usage/sales under the existing safety rules; staff must resolve such legacy inventory through their established inventory workflow.

## Affected files

Backend:

- `app/Services/InventoryBatchBalanceService.php`, `InventoryStockMovementService.php`, `PosSaleService.php`, `GroomingProductSaleService.php`.
- `app/Http/Controllers/InventoryController.php`, `PosController.php`, `OnlinePaymentController.php`, `PayMongoWebhookController.php`.
- `app/Models/InventoryTransaction.php`, `InventoryItem.php`, `GatewayPayment.php`.
- New `app/Support/InventoryQuantityPolicy.php`, `resources/data/inventory-policy.json`, and `database/migrations/2026_11_03_000001_add_stock_out_receipt_allocations.php`.

Frontend:

- `scripts/components/admin-stock-in.js`, `admin-stock-out.js`, `admin-pos.js`, `product-form.js`, `admin-inventory-items.js`, `admin-inventory.js`.
- `pages/admin/inventory/inventory.html` and sections `stock-in.html`, `stock-out.html`, `sell-product.html`, `products.html`, `overview.html`, `history.html`.
- `css/components/admin-inventory.css`, `admin-pos.css`, `package.json`, and new `scripts/build-inventory-policy.mjs`.

Tests:

- Extended the existing InventorySecurityRegressionTest and SharedPayMongoTest behavioral coverage; updated the directly affected clinic, grooming, vaccination, and payment schema fixtures for the additional column.
- Updated the existing stock-in/out, POS, product form, pricing, product search, inventory UI, and online-payment frontend regressions. Obsolete new-product threshold fixtures now use valid values. Test harnesses load the real shared validator.
- Implementation plan: `docs/superpowers/plans/2026-11-03-inventory-batch-consistency.md`.

## Verification

- Full backend suite: 2,238 tests, 31,219 assertions, no failures, 9 skips.
- After final changes, the affected backend integration group passed: 269 tests, 4,064 assertions. It covers Inventory, Clinic pricing/usage, Grooming sales, vaccinations, shared online payments, and payment schema compatibility.
- Full frontend run: 92/93 scripts passed. The remaining failure is `servicesPrimaryDoesNotWaitForClinicPermissions` in `tests/admin-navigation-performance-regression.cjs:184`. It also fails when the test reads the repository's original HEAD scripts; the Services production code was not changed by this work.
- Final focused Stock In, Stock Out, POS, Products, Overview, navigation, and inventory UI regressions passed, including receipt overrides, quantity preservation, pending totals, and legacy thresholds.
- `npm run build` and `git diff --check` passed.
- Single authenticated ChatGPT browser tab inspected for the existing blue palette, two-panel Stock Out layout, Products and product-details hierarchy, empty Add Product form, and POS. Browser checks involved navigation and viewing only; no data entry or transaction submission was performed. Populated-input and payment behavior was checked through automated regressions and remains available for the user's manual testing.

## Local database and Hostinger deployment

The additive migration was previewed and applied successfully to the running app's local MySQL database at `127.0.0.1`. No inventory transactions were created through the browser.

For Hostinger, deploy the updated PHP, HTML, scripts, styles, policy JSON, and generated assets together. Apply this migration before exposing the updated receiving/removal code:

```sh
php artisan migrate --path=database/migrations/2026_11_03_000001_add_stock_out_receipt_allocations.php --force
```

Use the hosting plan's verified PHP interpreter and normal deployment procedure. Build assets with `npm run build`. No new runtime packages or localhost-only dependencies were added. The 1,000-unit operational limits remain provisional Bethlehem policy values and can be revised centrally; no additional business decision blocks this implementation.
