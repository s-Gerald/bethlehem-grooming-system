# Service and Payment References Implementation Plan

**Goal:** Preserve historical service references while displaying the verified PayMongo payment identifier consistently.

**Architecture:** Keep the existing webhook and settlement paths. Add a nullable unique POS reference and durable daily counter, reserved under a row lock in the sale transaction. Reuse loaded gateway relationships and the shared invoice components.

**Constraints:** Asia/Manila dates; no historical renumbering; no financial rule changes; no live payment configuration changes; no commits, pushes, or branches; browser inspection stays in the single admin tab with manual navigation and inputs left to the user.

1. Reference persistence: change `BookingController`'s new online prefix to OR; add a forward migration and daily POS sequence in `PosSaleService`; expose persisted references in `PosTransaction`, activity logs, and eagerly loaded inventory history. Verify date rollover, deletion history, rollback, uniqueness, legacy fallback, and immutable updates in existing backend tests.
2. Provider ID: add a confirmed-ID accessor on `GatewayPayment`; extend `OnlinePaymentService`, payment status controllers, POS snapshots, and transaction history/search. Verify real signed webhook capture across all three contexts, pending/reconciliation restrictions, missing historical IDs, and search pagination.
3. Presentation: carry identifiers into existing Grooming/Clinic/POS completion snapshots and history invoice snapshots; add compact copy controls in existing detail sections and wrapping print rows in shared invoices. Modify `admin-dashboard.js`, `admin-clinic.js`, `admin-transactions.js`, `appointments.html`, `clinic.html`, Inventory's POS partial, `transactions.html`, shared invoice JS, and relevant CSS only. Verify existing frontend regression suites and build.

**Review focus:** simultaneous Cash/PayMongo sales; retained counters after deletion; status without confirmed settlement; missing historical provider IDs; deep links using numeric POS IDs after dated references.

**Verification:** test-first focused PHPUnit and Node regressions; relevant payment/inventory/search suites; production asset build; migration compatibility on isolated test databases. Real Hostinger webhook delivery and user-driven localhost flows remain manual verification.

## Delivery notes

- Reference generation: `BookingController.php`, `PosSaleService.php`, `PosTransaction.php`, and `2026_10_31_000001_add_pos_service_references.php`. Inventory source labels and activity logs consume the persisted POS reference; historical null references retain their numeric fallback.
- Provider data: `GatewayPayment.php`, `OnlinePaymentService.php`, `OnlinePaymentController.php`, and `PaymentController.php` expose the existing verified provider ID and support indexed exact-ID history search.
- Presentation: the existing Grooming, Clinic, POS, and Transactions components/pages carry the ID into shared `payment-invoice.js` and `product-sale-invoice.js`; shared CSS handles wrapping, the Phosphor sprite supplies Copy, and active asset URLs invalidate old caches.
- Verification: 169 focused PHPUnit tests / 2,408 assertions passed, including real signed webhook fixtures for all payment contexts, migration preservation/uniqueness, date rollover beyond 999, rollback, deletion history, and contending Cash/webhook processes against disposable SQLite. The separate online/walk-in reference creation regression passed. All 88 frontend regression scripts, the production build, final CSS rebuild, and whitespace checks passed.
- Full PHP suite: 2,095 tests, 27,765 assertions, eight skips, one existing failure in `EmailVerificationTest::test_warning_mail_is_encrypted_durable_and_contains_no_authentication_material`. Laravel's in-process queue worker returns its memory-limit exit code 12 after suite memory growth; the same failure appears in `storage/pet-full-php-tests.log`. The test passes independently (13 assertions). No unrelated email code/tests were changed.
- The forward migration was applied successfully to the current local MySQL database. The concurrency regression uses SQLite; Hostinger/MySQL contention and actual external PayMongo delivery remain deployment verification. The existing admin browser tab was inspected without page navigation or data entry and reported no console errors; payment-state and print-page visual checks still need the user's manual review.

## Hostinger rollout

1. Back up the database using the established deployment process. Apply the additive migration with the hosting PHP CLI: `php artisan migrate --force --path=database/migrations/2026_10_31_000001_add_pos_service_references.php`. Keep the sequence table and issued references during subsequent deployments; the migration intentionally rejects destructive rollback.
2. Deploy the changed application, pages, component scripts, stylesheets, and icon sprite through the existing release process. Build with `npm run build` and ship the generated assets as usual; cache versions are updated in active payment consumers.
3. Verify the existing test-mode webhook still reaches the configured endpoint and compare the same real Payment ID in immediate completion, Transactions, and the printed invoice. No new environment variables or provider setup are needed. Hostinger and PayMongo configuration were not changed or remotely verified.
