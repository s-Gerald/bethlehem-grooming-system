# Cash-only cleanup implementation plan

**Goal:** Remove active gateway payments from Grooming, Clinic and POS without changing cash behavior or historical financial data.

**Architecture:** Keep existing cash controllers, settlement services, inventory locks and cache invalidation. Retain gateway relationships, reporting exclusions and read-only unresolved-payment/stock holds; remove all provider network operations and reservation writes. No schema migration or private environment changes.

**Scope:** User's Phase 1 cleanup request, Laravel/PHPUnit and existing JavaScript regression architecture. No new payment provider, proof upload, QR Ph or OCR workflow.

1. Extend existing cash, inventory, authorization and history tests; migrate reusable assertions out of dedicated gateway tests. Verify cash rejection and removed routes fail against the old implementation.
2. Delete PayMongoService, OnlinePaymentService, PayMongoWebhookController, OnlinePaymentController and online-payment.js. Remove PaymentController checkout methods, routes, configuration, provider settlement branches and frozen-cart helpers. Verify cash and historical stock protections.
3. Simplify appointments, clinic and sell-product payment forms to a Cash label; update their controllers, API exports, inventory loader, styles and asset versions. Preserve invoice/history renderers and existing cash validation. Verify existing frontend regressions.
4. Remove obsolete tests/documentation and update affected cache/authorization tests. Run full PHP and JavaScript suites, build, route registration and static checks.
5. Read-only audit local gateway attempts/reservations if the configured database is accessible. Report unresolved attempts and holds without releasing, expiring or settling them. Document deployment reconciliation and manual environment cleanup.

**Review focus:** Unsupported submitted methods; ambiguous pending provider outcomes; held stock across cash consumers; historical test payments excluded from collections; duplicate settlements and stock rollback. Existing applied migrations remain unchanged.
