# Activity Log

The admin Activity Log and dashboard Recent Activity use `activity_logs`. Existing payment, stock movement, notification and recovery records remain authoritative domain records; the dashboard no longer reconstructs a competing history from current statuses. No historical events are backfilled.

## Files and responsibilities

- `database/migrations/2026_10_25_000004_create_activity_logs_table.php`: additive table, historical actor snapshots, unique event keys and query indexes. No existing business tables or records are altered.
- `app/Services/ActivityRecorder.php`, `app/Observers/ActivityObserver.php`: allowlisted event names, metadata and meaningful transitions. `app/Models/Concerns/RecordsActivityAtomically.php` makes standalone tracked model saves/stock increments atomic with their events; existing workflow transactions are reused.
- `app/Http/Controllers/ActivityLogController.php`: admin search, date/area/staff/type filters, 10/25/50-row pagination, safe details and six-event operational feed. Pet names are loaded in batches.
- `app/Support/ActivityPresentation.php`: shared display titles, historical transition matching, grouped filter choices, effective areas and safe historical full names. Original event identifiers, actors and timestamps are not rewritten.
- `routes/api.php`, `routes/web.php`, `.htaccess`, `UseAccountSessionCookie`: authorize both the full log's HTML and its API. The friendly `/pages/admin/activity-log.html` URL redirects to the protected `/api/admin/activity-log/page` route. The view lives outside the static pages directory, and direct access to its source is denied. Navigation uses the existing HttpOnly account session cookie without exposing its value.
- `resources/views/admin/activity-log.html`, `scripts/components/admin-activity-log.js`, `css/components/admin-activity.css`: native filters/table/pagination and keyboard-accessible details drawer. Alpine text rendering escapes event content. Session invalidation clears data and rejects pending responses.
- Shared sidebar/dashboard/API components: one sidebar insertion before Settings, role defaults, independent filter cache keys, request deduplication and stale-response guards. Asset versions are updated in all admin consumers. `tailwind.config.js` includes the protected view.

## Recorded actions

- Verified customer registration/profile/archive changes, walk-in customer records, pet creation/profile/archive changes.
- Actual pre-registration creation/rescheduling/cancellation; combined walk-in intake records one check-in rather than a duplicate walk-in/check-in pair. Successful check-in/reversal and grooming/consultation transitions are separate events. Bulk grooming starts/completions have explicit hooks inside their existing transactions.
- Automatic unused pre-registration expiry is attributed to System and inserted as one bounded batch per existing 200-record chunk.
- Successful grooming, clinic and POS payments. Provider-confirmed payments record System, preserving the existing checkout-initiator/invoice data separately. Paid events use unique keys, and the existing business locks prevent callback duplication.
- Inventory item/quantity/status changes, service catalogue changes, visit-level grooming price changes, clinic charge changes.
- Clinical record, vitals, treatment, confinement, observation and attachment changes record concise markers. Medical text and attachment paths are not copied into activity metadata. These events and financial events are excluded from staff dashboard feeds.
- Staff account/profile/status/role/email/password changes, completed assisted recovery, successful privileged sign-ins, known privileged-account failed sign-ins, and consequential availability/groomer/QR settings changes. Failed sign-in attempts identify the target account as the subject, without pretending the account was authenticated. Existing detailed recovery-security records are retained.

The feed applies an action allowlist as well as the operational flag. Admin defaults to All, Grooming Receptionist to Grooming, Clinic Receptionist to Clinic, and Veterinarian to Clinic with server rejection of All/Grooming requests. Only Admin can retrieve full history, filter options or details. Existing Revenue/KPI permissions remain unchanged.

## Activity presentation refinements

- Generic queue/grooming events resolve to specific names only when their recorded before/after values establish the action. Examples include Queue entry cancelled, Pre-registration cancelled, Grooming cancelled and Grooming start reversed. Missing or ambiguous metadata retains the original truthful generic label. Table, drawer, search, dropdown choices and dashboard use the same formatter.
- Original stable type filters continue to include their historical records. Additional display filters match exact transitions in the existing JSON changes; no new business actions or stored event identifiers are invented. Native non-selectable `optgroup` headings organize all supported choices, with All activities first.
- Account displays the full name from the existing immutable identity snapshot. The redundant Role row and raw account ID/username serialization are removed from details. New administrator events preserve their full name in the existing snapshot column while Performed by remains the admin username. Older administrator events without a recorded full name omit Account; no current user lookup or historical backfill is performed.
- One shared `.activity-dot` CSS mapping supplies 8px indicators: Grooming `#247FC0`, Clinic `#15947D`, System `#7861A8`, Shared operations `#7B8796`. Explicit recorded Clinic/Grooming areas take precedence. General inventory/customer/pet/POS events now record Shared operations. The known older System default for those shared workflows is normalized for display and area queries without changing stored rows; account/security/settings events remain System.
- Pointer-focused drawer controls and restored row buttons have a scoped focus marker that suppresses inherited keyboard outlines. Keyboard input clears that marker and retains `:focus-visible`, Tab/Shift+Tab trapping, Escape closing and focus restoration. Existing responsive table/drawer rules are retained; the new indicator track uses `minmax(0, 1fr)` for wrapping text.
- No extra requests, polling, packages, schema changes or account-name queries are introduced. Pagination, dashboard cache behavior and role isolation are preserved. Title matching reads the existing compact changes metadata in the same bounded queries.

Deploy the changed PHP files (including the new support formatter), protected HTML view, shared CSS, dashboard component and updated HTML asset references together. Run `npm run build` using the existing build process and upload `css/output.css` and the usual generated production assets. Restart long-lived workers through the existing deployment process if applicable (`php artisan queue:restart`). These refinements require **no migration or environment changes** and do not deploy themselves.

## Performance and limits

- No additional polling. Dashboard activity uses the existing cache/refresh mechanism and account/session isolation; changing its area refreshes only that feed.
- Feed results are bounded to six; table results are server-paginated. Timestamp, area, actor and action indexes support filters. Pet context reads are batched, avoiding per-row record lookups. Private log responses are `no-store` and are not added to browser presentation storage.
- Model-backed changes participate in the caller's transaction. Failed/rolled-back writes leave no successful event. Repeated no-op saves do not create events. Events have no edit/delete application endpoints, and model edits/deletes are rejected.
- Recording starts at deployment. Existing records have no invented history or actors. A new installation can therefore initially show an empty feed even with existing queue/payment data.
- Historical actor identity is snapshotted; related pet names are resolved from current pet records. A removed pet leaves the stored record reference intact. Generic clinical markers intentionally omit medical details.
- Direct SQL/import/maintenance scripts bypass Eloquent observers unless they call the recorder. The existing bulk grooming and expiry paths have explicit hooks; future bulk business writes must do the same. Unsupported refunds/voids/exports are not invented.
- Existing detailed recovery-security history remains separate for its verification-specific events. The centralized log includes completed recovery and account changes, without copying secret codes or tokens.
- The existing site's routing/authentication conventions are required; static-only hosting cannot serve the protected page. No new environment variables, packages, services or scheduled jobs are required.

## Production deployment

Local migration was applied to the development MySQL database only. Production was not changed.

Use the hosting account's existing PHP CLI and deployment process. Build static assets with `npm run build` on the build machine and deploy the resulting `css/output.css`, existing generated assets, new CSS/JS, updated admin HTML, application files, protected view, migration and routing files. Preserve the root `.htaccess` rules, including the source-view denial.

Enter maintenance mode before uploading the release:

```sh
php artisan down
```

Upload the release and generated assets, then run:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000004_create_activity_logs_table.php --force
php artisan optimize
php artisan queue:restart
php artisan up
```

Enter maintenance mode before exposing the new application files to traffic. Run the migration before reopening traffic; web recording deliberately fails if its table is missing. Restart long-lived queue workers using the existing Hostinger worker/cron process when applicable. No re-seeding is needed. If the deployment uses a different Apache document root, retain the existing mapping of the friendly page URL to Laravel and never expose `resources/views/admin/activity-log.html` as a static file.

## Verification

`ActivityLogTest` covers authorization, page/source access, all role defaults, safe projections, historical identity, admin username, system actor, filters/search/pagination, dates, immutable records, no-op/rollback/retry behavior, stock before/after values, clinical privacy, glossary checks and batched expiry. Existing dashboard and PayMongo tests now assert real recorded transitions and idempotent system-attributed callbacks. Frontend regressions cover filtering races, cache/session ownership, stale identity responses and drawer focus/close behavior.

Browser inspection used only the existing admin tab. No role switching, test-data entry, OTP submission or password-reset interaction was performed. Those manual functional checks remain with the user.

Initial implementation verification (October 25, 2026): 806 selected PHPUnit tests, 11,802 assertions, 805 passed and one existing skip. The skipped grooming concurrency test requires `tests/grooming-capacity-concurrency.php` and an isolated MySQL database; it was not run against the development database. A final focused rerun after query projection changes passed all 30 log/dashboard tests (356 assertions). Four frontend regression scripts and `npm run build` passed; CSS generation was repeated after final view changes. `git diff --check` passed. The build reported only the existing outdated Browserslist data notice.

UI refinement verification (October 25, 2026): all 168 selected backend tests passed (1,994 assertions), followed by a final 33-test log/dashboard rerun (481 assertions). The four activity, dashboard-refresh, data-cache and sidebar regression scripts passed; the activity script was rerun after the final touch-focus adjustment. The production build and diff whitespace check passed. Tests extend the original suites rather than retaining an obsolete renderer or separate indicator-color implementation. The existing admin tab was used for visual inspection of the table, native groups, account details and pointer-focus restoration; no role changes or business-data input were performed.
