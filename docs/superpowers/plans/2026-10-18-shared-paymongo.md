# Shared PayMongo Implementation Plan

**Goal:** Extend the existing Test Mode Grooming gateway to Clinic and POS, retaining cash behavior and webhook-only settlement.

**Architecture:** Keep GatewayPayment as the pending transaction snapshot. Share checkout creation, safe expiry, status, and signature/amount verification; module settlement writes existing billing/sale records. POS attempts belong to the initiating cashier until settlement and reserve inventory under item locks.

**Tech stack:** Laravel 12, existing vanilla JavaScript/Alpine components, PHPUnit with mocked HTTP, Node behavioral regressions.

**Authority:** User payment specification and root AGENTS.md; execute in this session without worktrees or commits.

## Constraints and review focus

- PAYMONGO_LIVE_MODE=false; reject live configuration and live payloads.
- Only verified checkout_session.payment.paid webhooks settle transactions.
- Database charges/prices are authoritative; preserve permissions, cash rules, receipts, and histories.
- Ambiguous checkout creation stays pending and locked; never automatically retry a potentially successful create.
- Expiry with paid/pending/processing payments must retain locks/reservations. A browser return is never evidence of payment.
- Repeated requests, payment IDs, events, redirects, and concurrent stock consumers must remain idempotent.
- Failed payment attempts can remain chargeable; release only after provider-confirmed expiry.

## Files and sequence

1. **Shared backend and Clinic**
   - Add focused mocked API/webhook tests in tests/Feature/SharedPayMongoTest.php; observe missing endpoint/direct non-cash failures.
   - Extend PayMongoService/GatewayPayment and extract shared lifecycle into OnlinePaymentService. Keep PaymentController Grooming preparation and receipt format.
   - Add Clinic online endpoints under clinic:payment and shared ClinicPaymentSettlementService; reuse markPaid cash checks. Freeze charge mutation/cancellation/reopening while checkout is active.
   - Migration adds unique gateway links to clinic/pos records, POS method, request idempotency key, and stock reservations; retain legacy records.
   - Verify signed events, total/mode/metadata rejection, duplicates, safe cancellation, permissions, Cash.
2. **POS**
   - Add failing checkout/stock tests. Extract existing cart preparation and sale writes into PosSaleService used by Cash and webhooks.
   - Reserve validated stock under item locks. Enforce reservations in shared InventoryStockMovementService and product consumers.
   - Reuse a pending checkout per cashier, keep request keys for completed retries, and settle frozen prices exactly once.
   - Verify cart changes, restricted QR Ph methods, overselling, delayed events, safe release, frozen receipts and Cash.
3. **Navigation and interface**
   - Shared scripts/components/online-payment.js manages same-tab context, polling, safe cancellation and refresh recovery.
   - Wire admin-dashboard.js, admin-clinic.js, admin-pos.js and their existing HTML/API clients. Restore exact attempts using backend return IDs and authenticated status.
   - Reuse existing compact pending and receipt views; hide cash fields for online methods; no unrelated visual redesign.
   - Update product-sale-invoice, transactions and reports to use actual methods and explicitly separate test transactions.
   - Verify with Node behavioral tests for return recovery, pending verification, cancellation, repeated requests, and Cash.
4. **Delivery**
   - Run targeted PHPUnit tests first, then relevant wider regression coverage and frontend build.
   - Review diff for credentials, unnecessary changes, unsafe settlement paths and missing reservation consumers.
   - Document migration, Hostinger environment/webhook settings, reconciliation and manual Test Mode scenarios.

## Design baseline

Preserve existing system font, blue/slate palette, spacing, Alpine modals and receipt hierarchy. Hallmark supports restraint and accessibility; the supplied requirements and established UI supersede theme/gate workflows.
