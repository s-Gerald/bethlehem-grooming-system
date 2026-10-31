# Pet session exclusivity

Implemented on local `gerald-frontend` from `75186ff`. No commit, push or deployment was performed.

## Changes

- `PetServiceSessionPolicy` is the shared persisted-pet conflict policy. Account eligibility remains independent through `CustomerPreRegistrationAccessService`.
- BookingController and ClinicWalkinController reject customer cross-service conflicts with HTTP 409, without cancellation.
- WalkinController, ClinicWalkinController, AdminClinicController create/check-in and AdminBookingController check-in apply replacement inside the existing DailyPetQueue transaction and shared intake lock. Only unchecked, unpaid pending online registrations can be replaced; operational sessions and payment locks are protected.
- Partial Grooming replacement uses the existing cancellation audit fields. Booking cancellation is shared with the existing admin cancellation helper. Other pets and their service records remain intact; the last cancelled participation closes the booking.
- CustomerNotificationController attributes transfer notices to the persisted pet and cancelled service, using the existing cancellation type and presentation. No SMS/email changes.
- PetController supplies optional owner-scoped batched availability. The API/pet service and booking pet step request fresh data on entry/restoration, exclude unavailable pets, remove invalid draft selections and fail closed on lookup errors. Staff customer lookup, walk-in pet cards, Grooming confirmation and Clinic profile show contextual conflicts/replacement notices.
- Raw HTML/module asset references are versioned through their affected import chains.

## Verification

PHP 8.4 final affected-suite run: 586 tests, 7,701 assertions, no failures (585 passed; one MySQL-only test skipped on SQLite and passed separately against MariaDB). Coverage includes covering customer rejection in both directions, different owned pets, partial/last-pet cancellation, operational conflicts, idempotent notifications, actual Clinic case creation and Grooming check-in, all-pet prevalidation, and rollback on intake or customer notification failure. Customer lookup query-growth checks stayed constant.

The isolated local MariaDB concurrency runner passed with 229 assertions and observed actual lock contention. It verifies same-service duplicate protection, same-pet cross-service submission rejection and a physical replacement race with one winner/one cancellation notification. Its disposable database was removed.

Eleven frontend regression scripts passed: pet-session-selection, pet-selection-step, grooming-flow, customer-data-cache, customer-dashboard-history, walk-in-pet-step, walk-in-review, clinic-subrole-workflow, client-notification-ui, admin-dashboard-refresh and admin-dashboard-capacity. JavaScript syntax and whitespace checks passed.

Production build passed with the existing outdated Browserslist-data warning. No new CSS classes required generated stylesheet changes.

The ChatGPT browser listed the existing client dashboard tab but failed when attaching/capturing it. This turn's live visual inspection could not be completed. No extra role tab was opened. Manual navigation, data input, OTP and password resets remain with the user.

## Hostinger release

Deploy all changed PHP, raw HTML and JavaScript together, including the new service and versioned entry/import references. Include the normal complete generated `public/build/` manifest/assets and packaged vendor assets. Raw customer/admin scripts are not bundled by Vite.

Run the established Composer autoload regeneration if production uses an authoritative class map, and the normal PHP OPcache refresh procedure after release. No new migration, dependency, route, environment setting, cron or job is required. Complete user-owned manual workflow/visual acceptance after deployment.

This policy supersedes the earlier independent-registration release's same-pet cross-service allowance; different pets may still use different services simultaneously.
