# Grooming time CMS — release notes (2026-11-05)

The existing Services > Grooming modal now contains Details & Pricing and Time & Haircuts panels. Admins manage optional fixed/range estimates with separate Hours (0–99) and Minutes (0–59) inputs, by supported size. Regular, Deluxe, and Cat Full Grooming have package-scoped haircut catalogues; haircut options have no independent charges.

## Persistent configuration and migration

Apply `database/migrations/2026_11_05_000002_retire_legacy_grooming_estimates.php` after the existing `2026_11_05_000001_manage_grooming_times_and_haircuts.php` migration. The retirement migration drops only `services.legacy_complete_estimates`. Current `time_estimates`, `haircut_options`, and historical `booking_pets.grooming_preference_name` values remain intact. Both migrations have been applied locally.

Only saved base package, selected haircut, and applicable separate à la carte times contribute to estimates. The legacy section, switch, API fields, and calculation branch have been removed. No complete legacy value is converted into a base or haircut duration. Configure unset components manually through the existing editor. An unset required component produces **No estimated time** and does not prevent registration. Cat requests without configured haircut choices retain the checkbox and required instructions, with no fabricated complete estimate.

The files in `database/data/grooming-legacy-*.php` remain because the already shipped initial migration requires them. They are immutable migration history, not runtime fallbacks. Existing service prices, inclusions, booking services, payments, queue policies, and actual timestamps are preserved. Local before/after digests verified that every non-legacy service value and every booking-pet record remained identical while two populated legacy records were retired.

The retirement migration's rollback restores only an empty nullable column for schema compatibility; it does not reconstruct retired legacy values. The original CMS migration remains forward-only. Back up the production database and release together; do not run `migrate:fresh`, reset, or reseed production.

## Restore default prices

The restore action already changed local form values without a backend write. It now shows **Defaults selected. Save changes to apply.** The proposed prices remain in the editor until Save changes; Cancel, Close, Escape and backdrop dismissal share the existing draft-discard path. Restoring defaults leaves the persisted catalogue, time settings, haircuts, and inclusions untouched. Successful saves retain the established Service updated feedback and catalogue reload.

## Main affected files

| Area | Files and responsibility |
| --- | --- |
| Data | New retirement migration; `app/Models/Service.php` removes the obsolete field and cast. |
| Administration | `app/Services/GroomingTimeConfiguration.php` and `app/Http/Controllers/GroomingPricingController.php` remove the legacy switch, request behavior and response field. |
| Shared calculation | `app/Services/GroomingDuration.php` always uses persisted components, including for existing queue pets. GroomingQueueForecast and its assignment/order/projection rules are unchanged. |
| Editor | `pages/admin/services/sections/grooming.html`, `scripts/components/admin-grooming-services.js`, `scripts/components/admin-services.js`, `css/components/admin-services.css`, and `pages/admin/services.html` remove legacy UI/state/styles and add draft restoration feedback. The unused legacy display formatter wrapper and its Services-only script load were removed. Editor asset versions were refreshed. |
| Regression coverage | New `GroomingLegacyEstimateRemovalTest.php` and `grooming-price-restore-regression.cjs`; updated GroomingTimeCmsTest, GroomingDurationTest, GroomingAdministrationAuthorizationTest, SeedsGroomingTimeCatalogue and admin-loading regressions. |

## Verification

- Final affected PHP regressions: **475 tests, 6,194 assertions; one existing skip**. Includes migration preservation, rejection of stale legacy influence, price-only writes preserving times, both real registration endpoints, permissions, addition, inclusions, cat states, historical names, queue, pricing and payment regressions.
- Dynamic forecast integration: save fixed, increased ranged, decreased fixed, and unset base times through the actual admin endpoint; fresh customer/staff previews and the active/queued pets update consistently. Actual timestamps, groomer assignment and queue numbers remain unchanged. Exactly one full catalogue query serves each multi-pet forecast request.
- Frontend suite: **95/95 scripts passed**. Includes restore without saving, dismissal/reopen, fresh catalogue reads, save-only persistence, unchanged time settings, and existing cache invalidation after CMS writes.
- Production `npm run build`: passed. Existing Browserslist metadata warning remains; no dependency change is needed for this feature.
- Single admin browser tab: inspected the existing editor and refreshed Time & Haircuts panel, confirming removal of the legacy section and switch while preserving supported sizes, haircut rows and actions. Checked 320/375/414/768 px without overflowing controls; no console errors captured. Restored the viewport and dismissed the unsaved editor. No browser form data, OTP, or password reset was submitted. Manual functional testing remains with the user.

## Hostinger deployment steps

1. Back up the production database and current release. Verify that the previous grooming catalogue and haircut-requested migrations are applied. Use the verified hosting PHP CLI/web runtime described in README; PHP 8.4 is the project's test target. No package dependency versions changed.
2. Prepare assets with `npm ci` followed by `npm run build` in the build environment. Put the existing production release into maintenance before replacing application files. Upload the changed application, migration/data snapshots, configuration, frontend pages/modules, component CSS, generated `css/output.css`, and built assets using the existing release process. Ensure the retired duration configuration file is removed from the release.
3. During a brief maintenance/release window, apply code and schema together. From the application root, using the verified PHP binary:

   ```sh
   php artisan down
   php artisan migrate --force --path=database/migrations/2026_11_05_000001_manage_grooming_times_and_haircuts.php
   php artisan migrate --force --path=database/migrations/2026_11_05_000002_retire_legacy_grooming_estimates.php
   php artisan config:clear
   php artisan config:cache
   php artisan queue:restart
   php artisan up
   ```

   If the host uses a long-running application process, restart it through the existing hosting workflow. Keep the database and migration snapshots across releases. The migration makes no external API calls.
4. Purge the existing hosting/CDN asset cache if enabled and reload the application. Verify the two editor panels without legacy controls, retained prices/inclusions, read-only staff access, default-price draft feedback and subsequent forecast refreshes. Configure any unset base/haircut times manually; missing required components intentionally yield no complete estimate.
5. Complete the user-owned manual checks for service edits, Restore/Cancel/Save, cat checkbox A/B/C states, customer/walk-in registration, and queue displays.

No commits, pushes, branch creation, production deployment, or monthly data simulation were performed.
