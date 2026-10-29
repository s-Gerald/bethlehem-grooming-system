# Grooming invoice product pricing implementation plan

**Goal:** Show recorded product unit prices consistently and put successful product additions first in Grooming/POS carts.

**Approach:** Extend existing components and regression suites. Share a small recorded-product formatter across invoice, summary, transaction and history views; preserve recorded subtotals. Add backward-compatible unit-price fields and the missing early-payment summary. Preserve settlement and stock calculations.

- [x] Extend invoice/summary/history/transaction tests for snapshots, quantity one, exact-cent legacy fallback, unavailable pricing, and two-column grooming service invoices.
- [x] Extend POS/Grooming cart regressions for C/B/A ordering, duplicate promotion, failed additions, quantity edits/removal, and order-independent online recovery without rebuilding unchanged rows.
- [x] Extend existing backend payment tests for final/early cash and PayMongo snapshots after inventory prices change; confirm failures before implementing.
- [x] Add the shared formatter; update the existing markup and scoped print widths; add response fields and early summary without changing payment totals, eligibility, deductions or stored history.
- [x] Update versioned assets and inventory lazy-loader versions; run affected PHP/frontend suites, syntax checks and production build. Inspect generated invoice layouts independently of the localhost browser.

**Constraints:** Only the supplied admin dashboard is inspected in the ChatGPT browser. No manual functional testing, new dependencies, migrations, production data edits, commits, pushes or Hostinger deployment.
