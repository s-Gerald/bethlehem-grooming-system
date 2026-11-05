# Grooming time CMS — release notes (2026-11-05)

The existing Services > Grooming modal now contains Details & Pricing and Time & Haircuts panels. Admins manage optional fixed/range estimates with separate Hours (0–99) and Minutes (0–59) inputs, by supported size. Regular, Deluxe, and Cat Full Grooming have package-scoped haircut catalogues; haircut options have no independent charges.

## Persistent configuration and migration

Apply `database/migrations/2026_11_05_000001_manage_grooming_times_and_haircuts.php`. It adds three JSON columns to `services`: `time_estimates`, `haircut_options`, and `legacy_complete_estimates`; it adds `booking_pets.grooming_preference_name` for historical names. This migration has already been applied to the local development database.

The original Regular/Deluxe complete estimates are preserved without splitting them or adding an invented haircut duration. In the Time & Haircuts panel, **Use base + haircut times** explicitly replaces that legacy mode when saved. Renaming/deactivating a haircut can preserve legacy mode. An unset required component produces **No estimated time** and does not prevent registration. Cat requests without configured haircut choices retain the checkbox and required instructions, with no fabricated complete estimate.

The files in `database/data/grooming-legacy-*.php` are immutable migration snapshots, not runtime fallbacks. The retired `config/grooming_estimates.php` and runtime preference defaults were removed. Existing service prices, inclusions, booking services, payments, queue policies, and actual timestamps are preserved.

The migration is deliberately forward-only: `down()` refuses to discard managed settings and historical names. Use a reviewed forward correction if necessary; do not run `migrate:fresh`, reset, or reseed production.

## Main affected files

| Area | Files and responsibility |
| --- | --- |
| Data | New migration and two legacy snapshot files; `app/Models/Service.php` exposes persisted settings and scoped haircut lookups; `app/Models/BookingPet.php` displays the stored name. |
| Administration | `app/Services/GroomingTimeConfiguration.php` validates Hours/Minutes and retains deactivated IDs; `app/Http/Controllers/GroomingPricingController.php` authorizes and atomically saves the current catalogue. |
| Shared rules | `app/Services/GroomingDuration.php` calculates base + haircut + non-included extras; `app/Services/GroomingServiceSelection.php` validates active, package-specific choices. |
| Registration/board | BookingController, WalkinController, AdminBookingController and GroomingForecastController save haircut snapshots, consume the managed catalogue, and accept the cat haircut flag. Existing GroomingQueueForecast consumers automatically use the shared calculator. |
| Editor | `pages/admin/services/sections/grooming.html`, `scripts/components/admin-grooming-services.js`, `scripts/components/admin-services.js`, `css/components/admin-services.css`, and Services page asset loading. |
| Customer/walk-in | Shared grooming-service, grooming-service-selection, grooming-estimates, booking-services-step and walk-in-services-step modules; forecast presentation and admin haircut choices. Related module URLs were versioned consistently so old cached code cannot retain the hardcoded behavior. |
| Regression coverage | New GroomingTimeCmsTest and grooming-time-cms-regression; updated duration, authorization, forecast, catalogue and editor fixtures/tests. |

## Verification

- Final affected PHP regressions: **471 tests, 6,138 assertions; one existing skip**. Includes both real registration endpoints, active/inactive/foreign cat haircut validation, name snapshots, changed forecast configuration, missing estimates, permissions, addition, inclusions, queue forecasts, pricing and payment regressions.
- Frontend suite: **94/94 scripts passed**. Includes actual customer/walk-in checkbox change handlers, draft preservation, strict time inputs, formatting, and forecast payloads.
- Production `npm run build`: passed. Existing Browserslist metadata warning remains; no dependency change is needed for this feature.
- Full PHP suite: **2,284 tests, 31,924 assertions; four failures and nine skips** at the time of the full run. All four failures also reproduced independently in unchanged `AdminCredentialChangeTest`: `test_admin_recovery_preserves_the_staff_identity_and_requires_both_proofs` (all three datasets) and `test_recovery_hold_blocks_old_mailbox_challenges_sessions_resets_and_self_service`. They expect HTTP 202 but receive 422 from account recovery/password-reset availability checks. The subsequent targeted run includes the additional new grooming tests.
- Single admin browser tab: inspected existing and updated modal, package legacy grouping and à la carte range controls. Checked 320/375/414/768 px without overflowing controls; no console errors captured. Restored the viewport and cancelled the unsaved editor. No browser form data, OTP, or password reset was submitted. Manual functional testing remains with the user.

## Hostinger deployment steps

1. Back up the production database and current release. Verify that the previous grooming catalogue and haircut-requested migrations are applied. Use the verified hosting PHP CLI/web runtime described in README; PHP 8.4 is the project's test target. No package dependency versions changed.
2. Prepare assets with `npm ci` followed by `npm run build` in the build environment. Put the existing production release into maintenance before replacing application files. Upload the changed application, migration/data snapshots, configuration, frontend pages/modules, component CSS, generated `css/output.css`, and built assets using the existing release process. Ensure the retired duration configuration file is removed from the release.
3. During a brief maintenance/release window, apply code and schema together. From the application root, using the verified PHP binary:

   ```sh
   php artisan down
   php artisan migrate --force --path=database/migrations/2026_11_05_000001_manage_grooming_times_and_haircuts.php
   php artisan config:clear
   php artisan config:cache
   php artisan queue:restart
   php artisan up
   ```

   If the host uses a long-running application process, restart it through the existing hosting workflow. Keep the database and migration snapshots across releases. The migration makes no external API calls.
4. Purge the existing hosting/CDN asset cache if enabled and reload the application. Verify the two editor panels, retained prices/inclusions, Regular/Deluxe legacy estimates, read-only staff access, and subsequent forecast refreshes. Once an admin switches a package to component times, configure all desired components; unset values intentionally yield no complete estimate.
5. Complete the user-owned manual checks for service edits, cat checkbox A/B/C states, customer/walk-in registration, and queue displays. Account-recovery test failures above remain a separate release consideration.

No commits, pushes, branch creation, production deployment, or monthly data simulation were performed.
