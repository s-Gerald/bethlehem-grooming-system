# Staff Dashboard, Grooming, and Services access

**Goal:** Give all three staff roles Dashboard read access, extend Clinic Receptionist Grooming operations, and allow that role to read both service catalogues without management access.

**Architecture:** Extend User's existing role/permission methods and expose backend-derived staff permissions through the existing identity/cache. Reuse the Grooming middleware and catalogue normalization; add a staff permission middleware for shared read endpoints. Preserve the working-tree Clinic implementation.

**Constraints:** No migrations/dependencies, no production configuration/data changes, no commit/push/deploy. Keep blue UI, existing modals/tables, caching, deduplication, lazy panels, search debounce and polling intervals.

- [x] Add failing regressions to existing Dashboard, Grooming role/catalogue, Services, sidebar and cache tests. Verify admin vs all three staff roles, unauthorized writes, tab deep links, session transitions and query/request budgets.
- [x] Extend centralized permissions and identity serialization/cache revision. Move Dashboard reads outside the operational Grooming group; enforce shared read access. Permit Clinic Receptionist Grooming operations while denying veterinarian operations.
- [x] Remove aggregate revenue fields/queries from staff Dashboard responses; show the fourth card only from the authorized backend response, clear it on identity/session invalidation, preserve admin values and mobile/desktop titles. Verify the responsive grid uses three/four columns correctly.
- [x] Add an authorized catalogue read route reusing GroomingPricingController catalogue data; keep every write admin-only. Adapt Services tab resolution, lazy loading, readonly action visibility, and sidebar permission consumption without duplicate fetching. Preserve Clinic View Details.
- [x] Run affected frontend/PHPUnit regressions and production build, retain previous Clinic regressions, inspect final diff, update versioned assets and document exact deployment steps and any unrelated failures. Results and release commands are in docs/staff-dashboard-grooming-services-deployment.md.

## Review focus

- Veterinarian Dashboard reads must never imply Grooming writes.
- Clinic Receptionist must retain checked-in-only Clinic cancellation.
- An admin session/role transition must immediately hide and clear financial aggregates; stale responses cannot restore them.
- Staff catalogue data includes inactive services but cannot be used to mutate prices/status.
- Default Clinic Services and explicit Grooming/Clinic URLs must coexist; only the active catalogue loads.
