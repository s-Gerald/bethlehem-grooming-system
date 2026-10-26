# Availability workspace — implementation and deployment

Implemented on local branch `gerald-frontend`. No commit, push, or production deployment was performed. The new migration was applied only to the verified local application database (`APP_ENV=local`, loopback MySQL).

## Behavior

- Availability is a dedicated staff workspace between Activity Log and Settings. It retains the Bethlehem shell, responsive sidebar, groomer management, hours, midday break, and blocked dates. Settings retains General and Security. Legacy `?tab=availability`, `?tab=capacity`, and corresponding hash links forward to the workspace; Capacity opens Grooming.
- Each Stop/Reopen action requires an explicit service selection and confirmation. It affects only that service for the Manila calendar day. Existing registrations and admitted visits remain intact. Subsequent service days follow ordinary hours and closures. Global blocked dates still apply to both services.
- A legacy `stop_today` row with no service continues to stop both services. Reopening one deactivates the original row and preserves the other service's stop and original creator attribution. Original historical rows remain available.
- Administrators and both receptionist subroles can manage every Availability section across services. Veterinarians can view the page and information, with no mutation controls; direct mutations return 403. Customers and guests cannot access privileged endpoints or the protected HTML.
- Grooming cage capacity is stored in `clinic_settings.grooming_cage_capacity`, initially 20, editable from 1 to 500 whole pets. Validation rejects unsupported values and a maximum below current occupancy.
- `OperationalCapacity` remains the sole source for physical per-pet Grooming occupancy and all capacity figures. Pre-registrations and incoming pets consume no cages. Checked-in, queued, grooming, awaiting-payment, and released pets awaiting collection continue to count. Physical pickup/archive, valid reversal, and other existing departure transitions free space. Clinic activity is excluded. Groomer duty capacity remains separate.
- Admissions, capacity edits, and intake stop/reopen actions share `DailyPetQueue`'s MySQL advisory lock and singleton transaction lock. Existing queue numbering and workflow locks are retained.

## Main files

| Responsibility | Files |
| --- | --- |
| Workspace and extracted state | `resources/views/admin/availability.html`, `scripts/components/admin-availability.js` |
| Protected entry and shared navigation | `pages/admin/availability.html`, `scripts/components/admin-sidebar.js`, `routes/web.php`, `routes/api.php`, `.htaccess` |
| Settings cleanup and old links | `pages/admin/settings.html`, `scripts/components/admin-settings.js` |
| Permissions and navigation authentication | `app/Models/User.php`, `app/Http/Middleware/UseAccountSessionCookie.php`, `bootstrap/app.php` |
| Persistent configuration and closures | `app/Models/ClinicSetting.php`, `app/Models/ClinicClosure.php`, related controllers |
| Service rules and capacity | `OperationalCapacity.php`, `RegistrationServiceDay.php`, `AvailabilityTimeWindowService.php` in `app/Services` |
| Shared cache dependencies and indicators | `scripts/api.js`, `scripts/components/admin-dashboard.js`, `scripts/components/client-dashboard.js` |

Other HTML changes update shared asset versions. Existing frontend and PHP tests were updated in place, including the original Settings, permissions, loading, capacity, session, and sidebar regressions. Obsolete Availability/Capacity tab state, placeholder capacity controls, and Advance Pre-register Window UI were removed. Active registration and Clinic time-window infrastructure remain.

## Data and API contract

Migration: `database/migrations/2026_10_27_000001_add_service_intake_and_grooming_capacity.php`.

- Adds an unsigned small integer maximum with database default 20.
- Adds a nullable indexed `clinic_closures.service` string. Existing rows are preserved and remain global.
- No new environment variables, dependencies, or database engine requirements.
- `stopped_today` is now `{ "clinic": false, "grooming": false }`, rather than a global boolean. Stop/Reopen requests require `service: "clinic"` or `"grooming"`. All active local consumers use the new contract.
- The Availability read includes hours, both intake flags, groomers, blocked dates, and the authoritative cage snapshot in one response. Blocked-date values remain date-only strings.

Availability loads operational information on its own page, with deduplicated in-flight reads, identity isolation, revision guards, and targeted mutation invalidation. Settings no longer fetches operational Availability data. Sidebars do not poll intake status. Existing customer refresh and cache behavior is preserved, with targeted cage-cache invalidation; capacity data is not calculated independently in JavaScript.

## Verification

- Full PHP 8.4 suite: **1,759 tests, 23,586 assertions, zero failures, eight environment-gated skips**. The skipped cases are six dashboard MySQL cases, the isolated Grooming concurrency case, and an optional phone-verification MySQL migration case. Separate MySQL runs cover the first two groups below.
- Final focused PHP verification after review fixes: **245 tests, 2,937 assertions, all passed**.
- `npm test`: **80/80 regression scripts passed**. Additional focused runs cover stale responses, explicit confirmation/retry, focus restoration, identity invalidation, legacy links, and no Availability requests from Settings or sidebars.
- `tests/grooming-capacity-concurrency.php`: **one real MySQL test, 130 assertions, passed**. Verifies observed lock contention, registrations and per-pet queue integrity, competing admissions under a changed cage limit, capacity changes racing with check-ins in both lock orders, and duplicate single-service stops. Disposable database removal was verified.
- `tests/dashboard-summary-mysql.php`: **six tests, 38 assertions, passed** after applying the complete current migration history to a disposable local MariaDB schema. Dashboard summary queries remained bounded: 16 for an empty queue, 19 for five visits, and 19 for thirty visits. Only the unrelated optional phone-verification MySQL test remains unexecuted outside the standard suite.
- `npm run build`: passed. The build emitted only the existing outdated Browserslist-data notice.
- `git diff --check`: passed.
- Read-only admin visual inspection: 320, 375, 414, 768, and 1,440-pixel widths; no horizontal overflow at the checked mobile/tablet widths; no console errors. Grooming capacity and the active sidebar link were inspected. Manual functional browser navigation, data entry, OTP, and password-reset testing remain with the user.

Logs are in `storage/logs/availability-*.txt` and are local verification output.

## Hostinger redeployment

Use the existing Laravel deployment layout and configured PHP executable. No production action has been performed.

1. Back up the production database through the existing deployment process.
2. Build the release with `npm ci` and `npm run build` in the normal build environment, or include the locally generated assets according to the current deployment process.
3. Upload the changed application files, new migration, `resources/views/admin/availability.html`, `pages/admin/availability.html`, new script, rebuilt `css/output.css`, versioned HTML references, and normal `public/build` artifacts. Include the updated root `.htaccess`: it routes the entry page through Laravel and denies direct access to the private HTML template.
4. Run these commands from the deployed Laravel root, using the installation's configured PHP binary:

```sh
php artisan down
php artisan migrate --force
php artisan optimize:clear
php artisan up
```

If deployment normally caches configuration/routes/views, rebuild those caches using the established commands after clearing them. Restart any long-lived workers through the existing process if applicable.

The migration is required before the new application serves traffic. It adds columns without deleting existing customer, booking, payment, staff, or closure records. Do not mix old and new intake code during rollout: the status contract and service-specific closure interpretation changed together. For a rollback, restore a coordinated code/database backup rather than dropping service information from active closure records.

After release, inspect `/pages/admin/availability.html`, confirm the sidebar order, and carry out the user's manual role, navigation, form, intake, admission, payment, and pickup checks. Browser functional testing has intentionally not been substituted for that review.
