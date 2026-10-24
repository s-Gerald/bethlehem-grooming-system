# Staff Dashboard, Grooming, and Services release

This release builds on the uncommitted Clinic role workflow changes. Those Clinic workflows, checked-in-only receptionist cancellation, walk-in queue handling, clinical restrictions, and payment attribution remain intact.

## Resulting access

| Role | Dashboard | Grooming operations | Grooming Services page | Clinic Services | Service management | Revenue card |
| --- | --- | --- | --- | --- | --- | --- |
| Admin | Yes | Yes | Yes | Yes | Yes | Yes |
| Grooming Receptionist | Yes | Yes | Existing access unchanged | Existing access unchanged | No | No |
| Clinic Receptionist | Yes | Yes | Read only | Read only | No | No |
| Veterinarian | Yes | No | Existing access unchanged | Read only | No | No |

Dashboard summary/activity use a shared staff read permission rather than the Grooming operational middleware. Staff summary responses omit aggregate revenue fields and skip that aggregate query. Operational payment amounts and activity records retain their established visibility. Admin retains its revenue values and four-column desktop grid; staff have three cards/columns. The Dashboard's Grooming walk-in shortcut is hidden when Grooming operations are unavailable.

User methods remain the permission source. Backend-derived `staff_permissions` travel through login, login verification, `/me`, profile updates and the existing identity cache. Sidebar revisions include the new permissions; old identity cache entries without the permission map are revalidated. Revenue is authorized by the server response and cleared on session/identity invalidation; late admin responses cannot restore it after revocation.

Clinic Receptionists default to Clinic Services and can use either valid tab link. The new protected GET `/api/admin/grooming/services/catalogue` reuses the existing catalogue normalization, prices and active/inactive statuses. Existing admin management endpoints and guards remain admin-only. Grooming actions/Actions column are hidden for readers; Clinic View Details stays available. Lazy panels, shared requests, catalogue cache groups/invalidation, polling intervals and debounce remain in place.

## Updated source

- Backend: `app/Models/User.php`; `AdminBookingController.php`, `AuthController.php`, `LoginEmailChallengeController.php`, `AdminSecurityController.php`, and `GroomingPricingController.php` under `app/Http/Controllers/`; new `app/Http/Middleware/EnsureStaffPermission.php`; `bootstrap/app.php`; `routes/api.php`.
- Frontend: `scripts/api.js`; `admin-sidebar.js`, `admin-dashboard.js`, `admin-services.js`, and `admin-grooming-services.js` under `scripts/components/`; `pages/admin/dashboard.html`; `pages/admin/services.html`; `pages/admin/services/sections/grooming.html`.
- Existing admin entry pages also carry `staff-access=20261024` on affected shared script/preload references. The Clinic task's existing asset versions are preserved. The Services loader versions its changed Grooming script/partial.
- Existing tests updated: Dashboard summary/refresh; Grooming authorization/catalogue/settings; staff role transitions/profile updates; QR/payment review permissions; Services navigation/read-only actions; sidebar, data cache, intake/navigation performance. No separate competing test suite was added.

Removed behavior: Dashboard authorization through Grooming operations; unconditional staff revenue aggregates; local staff-subrole inference for Grooming navigation; forced Clinic-only routing for every non-admin; reader-visible Grooming management controls. Existing components/catalogue formatting were reused.

## Verification

- Focused backend run: **534 tests: 532 passed**, one previously confirmed unrelated Grooming archive query-budget failure and one skip. This includes the previous Clinic workflows.
- Full PHPUnit run: **1,606 tests**, initially five failures and eight skips. Four obsolete role/payment access expectations were updated; their targeted reruns passed. Login/profile identity regressions first reproduced the missing staff permission map and passed after correcting both serializers. The targeted follow-up run passed **9 tests / 262 assertions**.
- The only remaining full-suite failure is `GroomingAdministrationAuthorizationTest::test_archive_payload_has_constant_query_count_and_keeps_the_page_contract`: **14 queries vs a limit of 13**, independently confirmed against unchanged baseline application classes in the previous Clinic task. Its code/assertion was not relaxed. The full suite is not claimed green; eight skips are not passes or MySQL concurrency verification.
- Frontend runner: **77/78 scripts passed** initially. The remaining sidebar fixture was corrected to carry the actual backend permission map and passed on targeted rerun. All 78 scripts have passing results across those runs. Later changes to Services role transitions/editor revocation, catalogue caching, Dashboard revenue revocation and shared navigation passed their affected regressions.
- Production build passed, including CSS, Vite and OCR/QR/PDF packaging. The existing outdated Browserslist data warning remains. Generated CSS did not change. PHP/JavaScript syntax and diff whitespace checks passed.
- Logs: `storage/staff-access-frontend.log`, `storage/staff-access-phpunit.log`, `storage/staff-access-phpunit-full.log`, `storage/staff-access-build.log`. Original logs retain their original failures; the targeted results above supersede resolved failures.
- No manual browser data input, OTP/password-reset, real provider transaction, or MySQL concurrency acceptance was performed.

## Required redeployment

1. Preserve the current Hostinger environment, database and storage. No environment variables, dependencies or migrations are introduced. Build from the current working tree, including the preceding Clinic changes.
2. On the build machine, run the existing build:

   ```sh
   npm run build
   ```

   Use `npm ci` first only on a clean machine without the lock-file dependencies installed.
3. Upload the changed application source, middleware, bootstrap/routes, **all changed raw `pages/` and `scripts/` files**, `css/output.css`, and complete `public/build/` (manifest and hashed assets) through the existing release process. Vite does not bundle the raw admin HTML/scripts; uploading only `public/build/` is insufficient. Include the previously pending Clinic source files listed in `docs/clinic-role-workflows-deployment.md`.
4. Include generated `scripts/vendor/ocr/`, `scripts/vendor/qr-image/`, and `scripts/vendor/pdf/` when packaging a full release, as the current build requires. They already exist in the current setup and are not new dependencies.
5. After replacing source files, remove any stale route cache so the moved Dashboard middleware and new catalogue route take effect:

   ```sh
   php artisan route:clear
   ```

   If the production deployment uses an authoritative Composer class map, regenerate it so the new middleware is included:

   ```sh
   composer dump-autoload --optimize --no-dev
   ```

   Ordinary PSR-4 loading already resolves the new middleware; no Composer install/update is otherwise needed. Check actual runtime compatibility with `composer check-platform-reqs --no-dev` when preparing the release.
6. No `migrate` command is required. Do not reset, seed or overwrite production records.

## Optional release steps

If the existing deployment caches routes, rebuild them after the required clear:

```sh
php artisan route:cache
```

Configuration values did not change, so configuration cache rebuilding is unnecessary. Restart long-lived queue workers with `php artisan queue:restart` only if the established release procedure uses such workers. Purge a configured HTML/CDN cache only if it still serves the old entry pages; versioned script URLs handle ordinary browser caching.

Manually accept all four dashboard roles, receptionist Grooming intake/payment/pickup/cancellation, both service tabs, admin service editing, forbidden staff writes, role/session transitions, and the prior Clinic workflows after release. No production commands, commits, pushes or deployment were performed during implementation.
