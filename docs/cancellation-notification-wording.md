# Cancellation notification wording

Implemented on gerald-frontend. No commit, push or deployment was performed.

The previous admin Grooming formatter assumed every cancellation was online; customer titles also assumed pet-linked notices were online. Clinic staff cancellation did not notify registered customers.

CancellationNotificationContent now shares persisted-origin wording between the admin and customer formatters. Booking booking_type/walkin_id and Clinic appointment_type/walkin_id determine Walk-in, Online Registration or neutral Visit labels. Known staff/owner actors, cancellation reasons and cross-service causes are retained. Admin rows remain self-contained; customer bodies avoid repeating their title. Partial Grooming notices identify the affected pet, including legacy messages without a pet link.

AdminBookingController now associates pet cancellations with pet_id. AdminClinicController creates the missing registered-owner notification inside the existing cancellation transaction. Existing status, permission, payment and confinement protections and cancellation controls are unchanged. Retries and unregistered-owner cases do not create extra portal notices. No SMS or email changes.

Verification: affected PHPUnit run passed 493 tests, with one unrelated MySQL-only case skipped on SQLite (494 cases, 6,214 assertions). Subsequent focused formatter, delivery/retry and notification-write rollback tests passed. admin-notification-center, client-notification-ui and admin-grooming-board Node regressions passed. PHP syntax and git diff whitespace checks passed. No frontend assets changed, so no build was required.

The current Grooming tab was listed in the ChatGPT browser, but capture failed; live visual inspection was unavailable this turn. Manual workflow testing remains with the user.

Deploy the changed PHP controllers and new shared service together. Regenerate Composer's autoload map if the Hostinger release uses an authoritative class map, and refresh PHP OPcache using the normal release procedure if necessary. No database migration, new dependency, environment variable, job or asset version is required.
