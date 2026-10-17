# Shared PayMongo Test Mode payments

Grooming, Clinic and Inventory/POS share `PayMongoService`, `GatewayPayment`, checkout/status/expiry handling, and the existing public webhook. Only a verified `checkout_session.payment.paid` webhook can settle an online payment. Browser redirects and provider status retrieval cannot mark a transaction paid.

## Hostinger deployment

1. Deploy the backend and frontend together, including `scripts/components/online-payment.js` and updated Inventory sections. Run `npm run build` and upload the normal build output/static assets.
2. Run `php artisan migrate --force`. Migration `2026_10_18_000001_extend_shared_gateway_payments.php` adds unique gateway links to Clinic/POS records, a POS payment method defaulting to Cash, a unique request key, and `gateway_stock_reservations`. It preserves existing records.
3. Keep existing **backend-only Test Mode** settings:

   ```dotenv
   PAYMONGO_LIVE_MODE=false
   PAYMONGO_SECRET_KEY=<existing sk_test_ key>
   PAYMONGO_WEBHOOK_SECRET=<existing Test Mode webhook signing secret>
   PAYMONGO_PAYMENT_METHODS=qrph,gcash,paymaya,card
   PAYMONGO_RETURN_URL=https://YOUR-FRONTEND/pages/admin/appointments.html
   ```

   Only configure methods enabled for the test account. This build rejects live-mode configuration, live keys and live webhook signatures.
4. Keep the existing public HTTPS `POST /api/paymongo/webhook` subscription for `checkout_session.payment.paid` enabled in **Test Mode**, using that endpoint's signing secret. No new webhook or provider account is required. Use PayMongo redelivery for failed webhook deliveries; browser verification cannot replace them.
5. Run `php artisan optimize:clear` and, if normally used by the deployment, `php artisan config:cache`. Upload the changed scripts and section HTML with their updated payment cache keys.

The configured return URL is the Grooming frontend anchor. Clinic and POS paths are derived from its origin and optional installation prefix. Returns identify the exact attempt and originating transaction; POS returns to `inventory.html#sell-product`. No additional environment variables, queue workers, cron jobs or frontend credentials are required.

Creation uses Checkout v2; retrieval/expiration use existing v1 endpoints. Fees are not added to the Bethlehem amount. Provider references: [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [expiry rules](https://docs.paymongo.com/docs/payment-channels-key-concepts).

## Behavior

- Grooming keeps its existing confirmed prices, early/final rules, add-ons, cash workflow and settlement. Checkout and return now use the same tab.
- Clinic checkout uses finalized `clinic_charges`, including fractional quantities through finalized line amounts. Pending checkout blocks cash settlement, billing corrections, cancellation and reopening for edits. Verified settlement completes the same case, stores the actual provider method and uses the existing receipt. The `/pay` endpoint now accepts Cash only.
- POS QR Ph offers only QR Ph. Online Payment offers the other configured methods. The backend checks database prices, reviewed price/stock snapshots and quantities under item locks. One pending checkout per cashier is recoverable when Sell Product reopens. The gateway snapshot is the pending sale; no completed POS transaction or stock-out exists before confirmation.
- Stock reservations leave physical stock unchanged and reduce saleable availability. Cash sales, Grooming add-ons and manual stock-outs respect holds. Verified POS settlement atomically consumes the reservation and creates the existing sale/items/movements using frozen names, quantities and prices.
- Cancel returns request provider expiry. Holds remain until the provider confirms `expired` with no paid, pending or processing payment. A failed payment attempt alone does not close a checkout. Status polling also recognizes confirmed expiry on reopening. Closing a tab never releases stock.
- Gateway-linked records retain `livemode=false`; receipts/history identify tests. Collection/date totals exclude test amounts and expose `test_collection_total` separately. Reports and dashboard Grooming revenue exclude test payments. Cash stays a normal financial record.

## Endpoints and recovery

The existing staff permissions remain in force. The webhook stays public with raw-body signature verification and a five-minute timestamp tolerance.

- Grooming: `POST/GET/DELETE /api/admin/bookings/{id}/online-payment`.
- Clinic: `POST/GET/DELETE /api/admin/clinic-appointments/{id}/online-payment` under `clinic:payment`.
- POS: `POST /api/pos/online-payment` accepts a UUID `request_key`, `payment_method=qrph|online`, cart `items`, and optional notes. `GET/DELETE /api/pos/online-payment/{gatewayId}` are scoped to the initiating cashier. `GET /api/pos/online-payment` recovers their pending attempt; `?request_key=...` can also recover a response interrupted after settlement.
- Grooming/Clinic status accepts `?gateway_payment={id}` for exact attempt recovery.

Duplicate creates reuse the frozen attempt; POS request keys persist after completion. Repeated redirects and provider events cannot create additional payments, sales, notifications or stock deductions.

## Reconciliation exceptions

`gateway_payments.reconciliation_reason` retains uncertain outcomes safely:

- `checkout_creation_unconfirmed`: creation timed out or returned an ambiguous response. Keep the payment and reservation locked. An administrator must locate the session in PayMongo using the saved Bethlehem reference/metadata, verify its mode/amount/context and recover its ID through backend maintenance. Then use signed webhook redelivery or confirmed provider expiry. **Never delete the attempt, release its hold, mark it failed or collect again while the provider outcome is unknown.** Recovery requires staff reconciliation when the provider returned no identifiable session.
- `product_stock_conflict`: a verified charge could not fulfill its stock reservation, for example because a batch expired during checkout or stock changed outside supported workflows. Sale/item/movement writes roll back together; the paid gateway record and reservation remain for fulfillment/reconciliation. POS shows no successful receipt. Do not charge again.
- `booking_already_paid` / `clinic_already_paid`: a verified charge arrived for an already-paid visit. Retain the provider charge for investigation without a second settlement.

## Manual Test Mode checks

1. Grooming: final and early checkout with add-ons; same-tab return to the correct visit/receipt; correct early-payment stage; unchanged Cash flow.
2. Clinic: finalized breakdown matches PayMongo; Online hides cash/change and shows **Create Online Payment**; signed payment completes the case, records the actual method and opens the existing receipt. Check Cash separately.
3. POS: multiple products/quantities through QR Ph and enabled GCash/Maya/Card methods. Check the offered methods. Before payment, physical stock and sales stay unchanged while saleable stock decreases; afterward, exactly one normal sale and stock deduction exist.
4. Fail, cancel, refresh, go back and close/reopen during checkout. Recover the original payment without a replacement charge. Processing payments retain holds; confirmed cancellation/expiry releases them.
5. Double-click create, repeat the return URL and redeliver the same paid test webhook. Confirm one settlement/deduction. Another cashier, Cash sale or manual stock-out cannot consume held stock.
6. Temporarily delay webhook delivery: a success redirect must show **Verifying payment…** with no receipt until signed webhook redelivery. Restore normal webhook delivery afterward.
7. Check Test labels on receipts/history and zero contribution to collection totals, Reports and dashboard revenue. Cash reporting remains normal.

Automated checks use mocked provider responses and signed fixture webhooks, never real checkout or live keys:

```text
php artisan test --compact tests/Feature/SharedPayMongoTest.php tests/Feature/GroomingPayMongoTest.php tests/Feature/InventorySecurityRegressionTest.php tests/Feature/ClinicCaseServiceBillingTest.php tests/Feature/ClinicAdministrationAuthorizationTest.php
node tests/online-payment-navigation-regression.cjs
node tests/online-payment-flows-regression.cjs
npm test
npm run build
```

## Verification notes (October 18, 2026)

The focused mocked payment, Clinic, inventory and migration checks pass; frontend regressions pass (66 scripts), and `npm run build` passes. No actual PayMongo checkout was performed.

The broad Laravel run recorded 1,247 passing tests and one skip, with two pre-existing source-assertion failures in unchanged files:

- `AdminSecuritySettingsInterfaceTest::test_security_tab_exposes_admin_controls_and_staff_account_details` expects an old `admin-settings.js` cache URL (line 85).
- `DynamicLucideIconInterfaceTest::test_every_admin_page_with_conditional_lucide_icons_loads_the_shared_guard` expects an obsolete Clinic-services fetch in `admin-clinic-shell.js` (line 63); that shell already redirects to Services.

Those pages, scripts and assertions were left outside this payment change. Additional focused tests cover migration round trips, dynamic whole/fractional Clinic charge quantities, interrupted/expired returns and stale polling responses.
