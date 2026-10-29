# Grooming invoice pricing and product ordering

Completed on `gerald-frontend` without commits, pushes, branches, deployment, migrations, dependency changes, or application database edits.

## Resume audit

Before resuming, the working tree contained the implementation plan, new failing regression cases, and two repaired non-UTF-8 dash characters in `payment-invoice.js`. The earlier implementation patch had failed atomically; the pricing, presentation, response, and ordering changes were still pending. No staged or unrelated local changes were present. Existing work was preserved and completed in place.

## Result

- Grooming services show description and recorded charge only. Their invoice tables now have two columns; pet grouping and subtotals remain. Clinic and retail invoice layouts retain their existing columns.
- Product add-ons consistently show name, recorded line total, and a muted `unit price × quantity` line in completed grooming payments, transaction details and grooming history, including quantity one. Printed products retain Description / Qty / Unit price / Amount.
- A single small `recorded-product-pricing.js` formatter serves these views. It prefers `priceAtSale` / `price_at_sale`; a legacy fallback is permitted only when integer cents divide exactly by a valid quantity. Unknown or invalid unit prices display `—`. Existing recorded subtotals are never recalculated or overwritten.
- Early/final cash and paid PayMongo responses expose the saved unit price. Early cash also receives the authoritative service summary, using already-loaded, locked records and confirmed prices/sizes. Validation still excludes canceled pets from charges while retaining their historical details. One redundant service-ID query was removed; no per-pet queries or inventory lookups were added for display.
- Legacy missing unit prices remain null in history/transaction responses. Older paid online snapshots without `priceCents` no longer throw an error; their recorded subtotals remain intact.
- POS and grooming carts insert new selections at the front. Successful duplicates increment and move the same row to the front. Failed additions and manual quantity changes retain order. Dropdown, barcode, stock, removal, clearing and payment controls remain.
- POS online recovery compares rows by stable product ID rather than array position, preserving unchanged visible rows and their order. Stored transaction arrays are not sorted or rewritten.

## Modified files

| Files | Change |
| --- | --- |
| `app/Http/Controllers/PaymentController.php`, `AdminBookingController.php` | Response snapshots, early summary and missing-price serialization. |
| `scripts/components/recorded-product-pricing.js` (new), `payment-invoice.js` | Shared recorded-price presentation and grooming-only two-column service invoice. |
| `scripts/components/admin-dashboard.js`, `admin-pos.js` | Newest-first selection, duplicate promotion and order-independent POS recovery. |
| `scripts/components/admin-archive.js` | Remove the obsolete conditional unit-price helper after replacing all consumers. |
| `scripts/components/admin-inventory.js` | Register the shared formatter dependency and version lazy-loaded assets. |
| `pages/admin/appointments.html`, `transactions.html`, `archive.html` | Compact product detail markup and versioned script dependencies. An existing non-UTF-8 ellipsis in Transactions was repaired to allow safe source editing. |
| `pages/admin/dashboard.html`, `clinic.html`, `inventory/inventory.html` | Versions/dependencies for the shared assets; no unrelated UI redesign. |
| `css/components/print-information.css` | Scoped widths for grooming's two-column service table. |
| Existing `GroomingPayMongoTest`, `GroomingProductSaleTest`, `GroomingAdministrationAuthorizationTest` | Cash/online snapshots, changed live prices, legacy missing values, canceled siblings and existing financial safeguards. |
| Existing `admin-grooming-invoice-print`, `admin-grooming-history`, `admin-transactions-details`, `frontend-pos`, `frontend-product-search`, `online-payment-flows` regression scripts | Recorded-price behavior, replacement of obsolete assertions, cart order/recovery, and print/responsive checks. |
| `docs/superpowers/plans/2026-10-29-grooming-invoice-product-pricing.md` | Existing plan completed and checked off. |

`admin-transactions.js`, `GroomingProductSaleService`, the product snapshot model, settlement totals and retail invoice implementation already supported the required recorded data or business rules and were retained.

## Verification

- Relevant PHP suites: **392 tests, 5,114 assertions; one pre-existing MySQL-only intake case skipped**. Cash/early/final, PayMongo settlement and concurrency fixtures, stock, cancellation, authorization and pricing tests passed. No new skip was introduced.
- `npm test`: **85/85 regression scripts passed**. Old append-order assertions were updated in the existing product-search suite while preserving its stock and invalid-quantity checks.
- `npm run build`: passed. It reports the existing outdated Browserslist-data notice; package versions were left unchanged.
- Changed PHP/JavaScript syntax checks and `git diff --check`: passed.
- Existing invoice regression's optional `--layout` check: A4 and Letter, short and 45-product invoices; product summary widths 320/375/414/768px. These use a separate headless browser, synthetic records and the existing Alpine version, without localhost navigation or payment submission. PDF text/bounds were checked and a rendered A4 page and mobile summary were visually inspected. Long invoices retained all 45 product rows with their prices/totals.
- The supplied admin dashboard was inspected in the ChatGPT browser before the interruption. Manual application navigation, actual inputs, OTP/password resets and real transactions were left to the user.

Local QA previews: `storage/app/invoice-pricing-qa/a4-short.png` and `products-375.png`; four test PDFs remain in the same ignored directory. These are synthetic test artifacts, not release assets.

## Hostinger release

No migration or environment change is required. Use the existing release process to deploy the changed controllers, **all changed raw HTML/JavaScript**, the new formatter, scoped print CSS, and normal build output. Uploading only `public/build/` will omit these raw admin assets. The asset references and inventory lazy-loader now include `invoice-products=20261029`; purge any HTML/CDN cache through the normal deployment process. Include the existing generated OCR/QR/PDF assets when packaging a full release.

No implementation blocker remains. Manual acceptance and production deployment remain with the user.
