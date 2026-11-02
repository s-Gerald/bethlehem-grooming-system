# Clean application URLs

The canonical route map is `config/frontend.php`. `routes/web.php` registers named `pages.*` routes from this map and uses `FrontendPageController` to serve the existing documents. HTML still has its no-store policy; ordinary documents, redirects, fragments and assets do not start Laravel sessions or perform database queries. `/api/...` endpoints retain their existing paths.

Activity Log and Availability use their existing private HTML documents and controllers, behind the same Sanctum, usable-account, active-session, role and staff-permission checks as their API page routes. Other page shells retain their existing JavaScript gates and protected data APIs.

## Changed areas

- `config/frontend.php`, `routes/web.php`, `FrontendPageController.php` and `bootstrap/app.php`: explicit named document routes, permanent aliases, strict fragment access and clean guest redirects.
- Root/public `.htaccess`: front-controller routing before physical browser files, correct directory indexes, preserved sensitive-file protections and direct static assets.
- `UseAccountSessionCookie.php` and the protected Availability document/controller: existing authentication transport adapted to clean page paths, with a legacy-cookie migration bridge.
- `index.html`, active `pages/` documents, protected `resources/views/admin/` HTML, `scripts/api.js`, auth/navigation/sidebar/registration/inventory scripts: canonical destinations, route classification, reliable resources and cache invalidation.
- Verification/recovery notifications and services, customer notification destinations, `OnlinePaymentService.php`, `config/services.php` and `.env.example`: canonical outgoing URLs with existing token fragments, query state and deployment anchors retained.
- Existing PHP, Node and browser regressions, including the expanded routing smoke test and Apache server-access harness: updated expectations and focused compatibility/security coverage.

## URL mapping

| Legacy browser URL | Canonical URL |
| --- | --- |
| `/index.html` | `/` |
| `/pages/client/sign-in.html` | `/sign-in` |
| `/pages/client/signup.html` | `/sign-up` |
| `/pages/client/forgot-password.html` | `/forgot-password` |
| `/pages/client/verify-email.html` | `/verify-email` |
| `/pages/client/set-up-password.html` | `/set-up-password` |
| `/pages/client/confirm-email-recovery.html` | `/confirm-email-recovery` |
| `/pages/legal/terms-and-conditions.html` | `/terms-and-conditions` |
| `/pages/legal/privacy-policy.html` | `/privacy-policy` |
| `/pages/client/dashboard.html` | `/account` |
| `/pages/client/my-pets.html` | `/account/pets` |
| `/pages/client/pet-details.html` | `/account/pets/details` |
| `/pages/client/grooming-history.html` | `/account/grooming-history` |
| `/pages/client/notifications.html` | `/account/notifications` |
| `/pages/client/settings.html` | `/account/settings` |
| `/pages/client/pre-register.html` | `/account/registration` |
| `/pages/client/grooming-pre-registration.html` | `/account/registration/grooming` |
| `/pages/client/booking-pet-details.html?flow=clinic` | `/account/registration/clinic/pets?flow=clinic` |
| `/pages/client/booking-pet-details.html` (default grooming flow) | `/account/registration/grooming` |
| `/pages/client/clinic-visit-reason.html` | `/account/registration/clinic/reason` |
| `/pages/client/clinic-visit-summary.html` | `/account/registration/clinic/review` |
| `/pages/client/clinic-visit-confirmed.html` | `/account/registration/clinic/confirmed` |
| `/pages/admin/dashboard.html` | `/admin/dashboard` |
| `/pages/admin/clients.html` | `/admin/customers` |
| `/pages/admin/appointments.html` | `/admin/grooming` |
| `/pages/admin/clinic.html` | `/admin/clinic` |
| `/pages/admin/inventory/inventory.html` | `/admin/inventory` |
| `/pages/admin/archive.html` | `/admin/history` |
| `/pages/admin/transactions.html` | `/admin/transactions` |
| `/pages/admin/reports.html` | `/admin/reports` |
| `/pages/admin/services.html` | `/admin/services` |
| `/pages/admin/settings.html` | `/admin/settings` |
| `/pages/admin/notifications.html` | `/admin/notifications` |
| `/pages/admin/activity-log.html` | `/admin/activity-log` |
| `/pages/admin/availability.html` | `/admin/availability` |
| `/pages/admin/walk-in-booking.html` | `/admin/walk-in/customer` |
| `/pages/admin/walk-in-pet-details.html` | `/admin/walk-in/pets` |
| `/pages/admin/walk-in-grooming-services.html` | `/admin/walk-in/grooming/services` |
| `/pages/admin/walk-in-booking-review.html` | `/admin/walk-in/grooming/review` |
| `/pages/admin/walk-in-consent.html` | `/admin/walk-in/grooming/consent` |
| `/pages/admin/walk-in-booking-confirmed.html` | `/admin/walk-in/grooming/confirmed` |
| `/pages/admin/walk-in-clinic-confirmed.html` | `/admin/walk-in/clinic/confirmed` |
| `/pages/client/clinic-visit-date.html` | `/account/registration/clinic/pets?flow=clinic` |
| `/pages/admin/inventory/pos.html` | `/admin/inventory#sell-product` |

Legacy browser GET/HEAD requests return 301 redirects. Original query strings are retained. Browser fragments are not sent to the server; browsers inherit them across redirects unless the target supplies a fragment, as the retired POS alias does. Inventory still uses `#overview`, `#products`, `#sell-product`, `#stock-in`, `#stock-out` and `#history`, including their existing fragment query parameters. Grooming registration still uses its existing single document and `?step=pets|services|review|consent|confirmed`; clinic and staff steps preserve their query/state contracts.

## Resources and cache compatibility

The nine existing fragment resources remain under `/pages/admin/...`: the pet edit form, Clinic Services, Grooming Services and six inventory panels. They are not browser page routes or redirect targets. Shared assets and document-relative lazy resource loads use root-relative URLs. ES module imports remain relative to their modules. Existing cache parameters remain; affected scripts, importers and fragment loaders also use `urls=20261103` to prevent older navigation code being reused after deployment.

The sidebar allowlists canonical management routes and canonicalizes old `return_to` values from bookmarked notification URLs. External origins, credentials, backslashes, undefined pages and recursive notification destinations remain disallowed.

## Existing sessions

The account-session credential remains the same HttpOnly, SameSite=Lax token cookie, with the same Secure policy, persistence and deadlines. Its path is now the application root so the two authorized clean HTML routes can receive it. Remembered-device cookies keep their `/api` path and existing behavior. Cookie issuance, status upgrade and logout expire the previous `/api` account-session cookie. Public resources retain their cache policy when the root cookie is present.

A browser with only the old `/api` cookie that directly opens Activity Log or Availability takes a one-time authorized bridge through its existing `/api/admin/.../page?canonical=1` endpoint. Successful authorization reissues the same token at the new path and returns to the clean URL without extending deadlines. Guests go to `/sign-in`; unauthorized roles remain denied. Subsequent page navigation uses the clean route directly. Existing API page endpoints still serve their documents when the migration flag is absent.

## Hostinger deployment

No hosting-directory migration, repository setting, new service, database migration or untracked Apache configuration change is required for the currently supported layouts.

Deploy the complete change, including `config/frontend.php`, both `.htaccess` files, HTML, scripts and protected resources. Do not overwrite the production `.env` with `.env.example`. Keep the site's current document root:

- Repository root: root `.htaccess` selects `public/index.php` as its directory index and forwards known legacy documents before Apache's physical-file shortcut.
- Laravel `public/` root: `public/.htaccess` selects `index.php`; Laravel continues serving the root-level frontend resource directories through the established allowlisted file route.

Both layouts require the same existing Apache `mod_rewrite`/`.htaccess` support (`AllowOverride` permitting these rules) and PHP front controller. Private-file deny rules run before static-file serving. Assets and fragments retain the existing-file fast path when physically under the document root.

After uploading, regenerate the application's existing deployment caches from the repository directory, using the site's selected PHP CLI:

```sh
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
```

Restart existing long-running queue workers through the normal deployment process so newly generated verification/recovery links use the updated code. If Hostinger has a separate CDN/page cache, purge cached HTML and old redirect responses there after uploading. No Hostinger account settings have been changed by this implementation.

An existing `PAYMONGO_RETURN_URL` pointing to the old grooming HTML remains supported as the frontend origin/path-prefix anchor; generated payment returns use clean routes directly. New configuration examples use `/admin/grooming`.

## Verification

Verified locally with the documented PHP 8.4 runtime:

- Complete PHP suite: **2,215 passed, 9 skipped, 30,919 assertions**. The skipped cases require the separate isolated MySQL runners.
- Frontend suite: **92/92 regression scripts passed**.
- Real headless Edge: canonical bookmark redirects preserve query/hash; refresh and Back/Forward pass; cold/cached sidebar paint, four role profiles, normal/hard reloads, notification origins, lazy inventory navigation, all inventory hash/action routes and mobile navigation pass. Cached navigation makes one session-status request per document and reuses identity without another `/api/me` call.
- Isolated Apache: repository and public document roots pass; **46 private-path checks** remain denied, all mapped physical browser files reach Laravel, assets/fragments retain direct serving and the shared favicon resolves.
- Laravel config/route caching succeeds with isolated disposable caches; generated named page routes remain available with cached configuration.
- All 51 changed JavaScript files and 33 changed/new PHP files pass syntax checks; `git diff --check` passes.
- Visible copy and element/class structure match the original across 52 affected HTML files; CSS files are unchanged.

The first broad run found obsolete URL/asset/cookie-path assertions, which were updated. Its existing real-time cooldown assertion returned 59 instead of 60 once; it passed unchanged on focused rerun and in the final full suite. A registration-selector assertion about obsolete cage copy was replaced with a check that keyboard switching reaches the correct canonical grooming destination, preserving the existing displayed copy.

Coverage includes all mapped documents and aliases, direct resource resolution, no database/session work for ordinary pages, permanent query-preserving redirects, actual cookie authorization/migration, existing authentication/session security, notification origins, registration workflows, inventory lazy loading, Apache access protections in both layouts, browser refresh/history/hash behavior, and deployment route/config caching.

Production itself has not been redeployed or tested. The static frontend assumes the supported domain-root deployment; a new reverse-proxy URL-prefix deployment would require a separate coordinated asset-base change. Existing payment URL prefix support remains intact. MySQL-only test skips do not establish MySQL concurrency validation.
