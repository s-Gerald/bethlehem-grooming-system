# Inventory Batch Consistency Implementation Plan

**Goal:** Persist receipt picks, enforce immediate quantity limits, and use consistent inventory availability throughout the existing module.

**Architecture:** Extend the existing transaction ledger with nullable JSON receipt allocations. Null preserves historical FEFO inference; new removals store exact receipt IDs and quantities, with an empty array representing untracked physical removals. Product row locks serialize receipts, removals, and reservations. Reuse the existing batch service for all quantity definitions and low-stock decisions.

**Spec:** User-provided comprehensive inventory brief, 2026-11-03.

## Constraints

- Preserve existing UI layout, blue palette, payment workflows, historical records, and role permissions.
- Stock In: 1–1,000 whole units per product per submission. Newly configured minimum stock: 2–1,000. Preserve unchanged legacy thresholds.
- Keep invalid typed values visible; use inline errors and existing disabled buttons. No quantity shortcuts or additional checkout steps.
- No commits, pushes, branches, or unrelated refactors. Manual browser functional testing belongs to the user.

## Review focus

- Mixed expired/valid receipts and repeated manufacturer lots must remain independent.
- Multiple pending picks must reserve receipt and product capacity consistently.
- Manual receipt overrides must survive POS online-payment snapshots and settlement.
- Legacy untracked physical stock must never be presented as a verified receipt.
- Concurrent operations, late allocation failure, and reservations must preserve atomic balances.

## Task 1: Receipt allocation and authoritative quantities

Files: `InventoryBatchBalanceService.php`, `InventoryStockMovementService.php`, `InventoryTransaction.php`, one additive migration, existing inventory/clinic/grooming/payment test fixtures.

- [x] Add failing behavioral tests for FEFO splits, explicit picks, expiry restrictions, FIFO, untracked compatibility, rollback, reservations, and repeated consumption.
- [x] Add nullable `stock_out_allocations` JSON to existing transactions without backfilling history.
- [x] Replay persisted picks exactly; keep historical inference only for null allocations.
- [x] Allocate under ordered product locks, persist picks atomically, and reuse one mutable batch snapshot per operation.
- [x] Verify focused PHPUnit coverage and shared consumers.

## Task 2: Policies, APIs, reporting, and POS

Files: inventory/POS controllers and sale services, online payment creation/settlement, shared inventory policy, existing regression tests.

- [x] Add failing tests for cumulative receiving cap, strict quantities, legacy minimum-stock edits, usable low-stock counts, and POS receipt overrides.
- [x] Centralize operational limits, expose them through existing inventory responses, and enforce them server-side.
- [x] Return physical/usable/saleable/expired quantities and current receipt balances from the shared service.
- [x] Reuse usable low-stock decisions in products, alerts, badges, and overview, with bulk ledger queries.
- [x] Preserve POS review and payment reconciliation; carry optional receipt picks through frozen online snapshots.
- [x] Verify existing inventory, POS, grooming, clinic, and payment regressions.

## Task 3: Immediate validation and compact receipt selectors

Files: existing stock-in, stock-out, POS, product-form scripts and HTML sections; existing inventory CSS only if needed.

- [x] Add failing VM-based frontend tests for invalid-action disabling, unchanged typed values, pending totals, receipt limits, and legacy minimum-stock values.
- [x] Reuse shared quantity validation and server-provided policies; no per-keystroke requests.
- [x] Add relevant receipt selectors within existing forms/cart; default ordinary picks to automatic FEFO/FIFO.
- [x] Show exact allocation references in Stock Movements without duplicating sale rows.
- [x] Run focused frontend regressions, relevant backend integration tests, and production build.
- [x] Inspect the one authenticated browser tab for visual consistency; leave functional navigation/data entry to the user.

## Deployment

Run the additive migration before serving updated application code; deploy updated scripts/styles together. Historical transactions remain untouched. Document any verification limits explicitly.
