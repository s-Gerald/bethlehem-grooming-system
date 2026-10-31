# Independent Grooming and Clinic online registration

Implemented on local `gerald-frontend` at base revision `71a8232`. The remote lookup returned no `origin/gerald-frontend` ref. No commit, push or deployment was performed.

## Behavior

`/api/pre-registration/access` returns `services.grooming` and `services.clinic`, each containing `allowed`, `ongoing` and `message`. Overall `allowed` means either service is eligible and continues to control dashboard entry. Phone verification fields are preserved. Obsolete global `ongoing`, `message` and `has_ongoing_registration` fields were removed.

Each submission and direct form guard checks its own service. Existing intake transactions, MySQL shared intake lock and customer row locks remain intact. Redundant same-date duplicate queries were removed; the shared account-level check blocks active same-service registrations across all pets and dates while permitting terminal visits to be followed by another registration.

The selector keeps both tabs selectable, independently controls Continue, and displays one service-specific amber notice with a Phosphor warning icon. Dashboard cards, details routing, Grooming-only tracker and existing API cache/invalidation code are preserved and covered by regressions.

## Verification

- PHP 8.4 PHPUnit: 507 tests, 6,209 assertions, no failures; 506 passed and the MySQL-only concurrency case skipped on SQLite. Files: CustomerPreRegistrationAccessTest, CustomerPreRegistrationAccessInterfaceTest, ClinicAdministrationAuthorizationTest, GroomingAdministrationAuthorizationTest and QueuePreRegistrationModelTest.
- Real local MariaDB concurrency runner: 1 test, 212 assertions passed. Verified actual lock contention, duplicate same-service rejection and successful cross-service submissions for the same account/pet. The runner removed its disposable database afterward.
- Node regressions passed: online-registration-selector, grooming-flow, customer-dashboard-history, customer-data-cache, registration-service-day, preferred-arrival-window and grooming-consent. Coverage includes all four eligibility combinations, restored direct-form guards, simultaneous cards with colliding numeric IDs, correct detail routing, phone verification, terminal lifecycle, cache invalidation and loading/keyboard behavior.
- Production build passed. Existing outdated Browserslist-data warning remains. Generated CSS and versioned entry/module references are included.
- `git diff --check` passed.
- Inspected the existing client dashboard visually in the single ChatGPT browser tab. Manual page navigation, form input, OTP and password resets remain with the user; no manual functional browser testing was performed.

## Hostinger release

Deploy the changed PHP files, raw `pages/client/` HTML and `scripts/` modules together with `css/output.css`. Include the complete generated `public/build/` assets/manifest and existing generated vendor assets through the normal release process; Vite does not bundle the raw customer scripts. The updated query versions refresh changed modules and the selector stylesheet.

No new migrations, dependencies, routes, environment variables or jobs are required. Use the established PHP OPcache/cache refresh procedure if needed after upload. No production commands were run. Complete the user-owned manual functional checks after release.
