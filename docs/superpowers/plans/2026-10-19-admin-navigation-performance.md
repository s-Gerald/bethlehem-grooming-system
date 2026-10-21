# Admin navigation performance implementation plan

**Goal:** Make Dashboard, Customers, Grooming and Clinic reuse safe session presentation data while keeping operational refreshes authoritative.

**Architecture:** Evolve the existing `scripts/api.js` Admin cache with fresh/retain policies, group revisions, bounded persistence and a synchronous retained-render helper. Page components own display/request ordering, not another cache. Keep Laravel authorization and session locking intact.

**Constraints:** Work only on local `gerald-frontend`; no commits, branches, pushes, deployment, Hostinger, production or migrations. Preserve business rules and vanilla JavaScript/Alpine/Laravel. No UI redesign or new dependencies.

1. Record browser Network evidence for the exact eight-page flow and profile the relevant controllers using the existing local database in a rolled-back transaction. Verify request counts, ordering, response sizes and SQL counts.
2. Add behavioral cache regressions first, then implement session ownership, fresh/retain rendering, deduplication, bounded search/filter caching, revision guards and group-specific cross-tab invalidation in `scripts/api.js`. Verify failures, authorization and mutation races.
3. Remove Customers' component cache and eager pet editor initialization. Use the shared helper in Dashboard/Grooming/Clinic; load Dashboard directly and stop forcing sidebar activity. Use Manila dates for Grooming. Verify component loading and late-response behavior.
4. Replace CustomerController per-customer counts (including tier-filtered pet search) with aggregate subqueries. Verify exact booking-count/tier semantics and constant query growth with multiple SQLite fixture customers.
5. Separate Clinic queue/list fields from uncached workspace detail; defer Patient Records until its section approaches the viewport, retain only its first page, and leave Confined on demand. Verify detail/payment consumers and query/payload reductions with fixtures.
6. Run focused frontend/backend regression coverage, then repeat the exact local browser flow. Check retained rendering during delayed/failing refreshes, Add/Edit Pet on demand, mutation invalidation, Confined and errors. Document measured results and limitations.

**Review focus:** session replacement/lock; stale reads racing mutations; rapid A/B/A filters; empty queues versus populated detail workspaces; records reached before deferred work begins. Tests should exercise outcomes, not source strings.

The project's AGENTS.md makes this plan advisory; execute in this session without a worktree or commit.
