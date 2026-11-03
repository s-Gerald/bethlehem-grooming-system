# Role-Based Services Access and Default Views

**Goal:** Reuse `/admin/services` with role-correct defaults and existing catalog permissions: Admin/Grooming Receptionist default Grooming, Clinic Receptionist/Veterinarian default Clinic; veterinarians see only the read-only Clinic catalog without a switcher.

**Approach:** Reuse existing catalog components, identity events, cache, and Laravel permission maps. Update frontend access/presentation and the authenticated shared Grooming catalog read check. Preserve clinical/case-charge permissions, service records, existing endpoints, and all management restrictions.

- [x] Add focused behavioral regressions to `tests/frontend-clinic-services-regression.cjs` and `tests/admin-sidebar-cache-regression.cjs` for defaults, authorized hashes, hidden switcher state, sidebar/guard access, cached identity changes, and pending-load races. Retain Clinic searching/filtering/pagination/details and admin/receptionist workflow coverage.
- [x] Extend existing Grooming/Clinic PHPUnit authorization tests. Reject veterinarians and other staff without `grooming_services_view` at the shared authenticated Grooming read endpoint; preserve customer reads and authorized staff billing reads. Observed required failures before implementation.
- [x] Modify `scripts/components/admin-services.js`, `scripts/components/admin-sidebar.js`, `scripts/api.js`, `pages/admin/services.html`, and `app/Http/Controllers/GroomingPricingController.php`. `User.php`, catalog components/partials, and existing route middleware required no changes. Invalidate stale mounted panels and async mounts on identity changes, without adding requests for unchanged identities.
- [x] Version modified script references in their actual admin HTML consumers (including matching preloads). Run focused Node/PHPUnit regressions, syntax/module checks and diff review. Inspect only the existing veterinarian dashboard tab; leave manual navigation and data-entry workflows to the user.

**Review focus:** no Grooming mount/data request for veterinarians, including `#grooming`; no stale panel activation after a role change; same-identity updates preserve catalog state; only admin catalog management remains possible; inaccessible tab navigation normalizes to Clinic for Clinic-only users; panel headings/ARIA remain meaningful when tabs are omitted.

## Result

| Role | Default catalog | Catalog access |
| --- | --- | --- |
| Administrator | Grooming | Both catalogs, existing management controls |
| Clinic Receptionist | Clinic | Both catalogs, read-only |
| Grooming Receptionist | Grooming | Both catalogs, read-only |
| Veterinarian | Clinic | Clinic only, read-only, no type switcher |

Veterinarians now receive the Services sidebar item and pass the shared Services page guard. The Grooming workspace remains blocked. Clinic-only viewers receive the `Clinic Services` heading, an entirely hidden tab bar, and a content region labelled by the heading rather than a hidden tab. The existing margin below the heading remains; no empty tab bar or its spacing is rendered.

The shell waits for its shared identity initialization before choosing and mounting a catalog. Role or catalog-permission changes close dialogs, destroy old panels, and discard pending mounts. Same-identity profile updates preserve current filters and panels. Hash navigation and restored browser documents reread the current identity and normalize an unauthorized Grooming fragment to Clinic. No new polling or independent identity endpoint was added.

The shared authenticated `GET /api/grooming/services` previously allowed any staff account, including veterinarians. It now checks staff accounts against the existing `grooming_services_view` permission before querying the catalog. Customer access remains available; admin and authorized receptionist billing reads are preserved. Existing protected management routes and Clinic permission middleware are unchanged. Public marketing packages are unchanged. No service prices, categories, activation states, or records were modified.

## Affected files

- Runtime: `scripts/components/admin-services.js`, `scripts/components/admin-sidebar.js`, `scripts/api.js`, `app/Http/Controllers/GroomingPricingController.php`.
- Services presentation: `pages/admin/services.html`.
- Existing regressions: `tests/frontend-clinic-services-regression.cjs`, `tests/admin-sidebar-cache-regression.cjs`, `tests/Feature/GroomingPricingCatalogueTest.php`, `tests/Feature/GroomingManagedCatalogTest.php`. Existing generic staff read fixtures were changed to authorized receptionists while retaining their ordering, pricing, and mutation assertions.
- Asset-only changes: the other 17 existing `pages/admin/` HTML consumers and `resources/views/admin/{activity-log,availability}.html`. Along with Services, these 20 admin HTML documents reference the changed scripts/preloads with `services-access=20261103-v1`. No customer HTML was changed.
- This implementation/verification record. No new runtime components, test files, dependencies, migrations, pages, or API routes were created.

## Verification

- **105 PHPUnit tests / 1,696 assertions passed** with PHP 8.4.26: `ClinicServiceCatalogTest`, `GroomingPricingCatalogueTest`, `GroomingManagedCatalogTest`, `ClinicCaseServiceBillingTest`. Used the documented PHP runner outside the sandbox for its existing bootstrap ACL limitation, with the existing SQLite `:memory:` test configuration. Coverage includes all supported roles, forbidden staff mutations, denied veterinarian Grooming reads, authorized receptionist/customer reads, admin management, unchanged pricing/order, and the separate authorized veterinarian clinical charge workflow.
- **Five frontend regression scripts passed:** `frontend-clinic-services-regression.cjs`, `admin-sidebar-cache-regression.cjs`, `clinic-subrole-workflow-regression.cjs`, `grooming-managed-catalog-regression.mjs`, `admin-grooming-sidebar-indicator-regression.cjs`. Covered all four defaults with both cached and uncached identities, explicit/invalid fragments, direct tab attempts, panel/script/request isolation for veterinarians, pending-load role changes, permission invalidation, same-access profile updates, restored documents, sidebar selection, searching/filtering/pagination/details, admin editing, and receptionist read-only behavior. The Node VM experimental-loader notice is informational.
- Syntax passed for all five changed JavaScript files and all three changed PHP files. Five Services/identity/catalog entrypoints bundled successfully in memory with the installed esbuild. Admin script/preload version checks, original UTF-8 BOM preservation, and `git diff --check` passed. No generated build output was written.
- The single existing ChatGPT browser tab was signed in as a veterinarian. Inspected its dashboard, then refreshed the same URL and verified that Services appears in the existing sidebar with the standard icon, color, typography, spacing, and link to `/admin/services`. No extra role tab was opened and no account switching, page navigation, data input, OTP, password-reset, or catalog-mutation interaction was performed.

## Remaining manual verification and deployment

Manual role switching, navigation into Services, responsive catalog rendering, and full interactive workflows remain with the user as requested. Services role/navigation behavior was tested automatically and its heading/panel relationships reviewed in source; the catalog screens were not manually navigated in the localhost browser. No full npm production build or multi-role browser test was needed for the focused changes; module bundling and syntax checks passed. No Hostinger deployment was performed. Deploy the modified PHP and static assets together using the normal process; no database changes or new dependencies are required.
