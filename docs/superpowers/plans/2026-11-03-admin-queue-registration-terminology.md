# Admin/Staff Queue Registration Terminology

**Goal:** Complete the supplied admin/staff terminology standard on the current `gerald-frontend`, preserving customer changes, UI conventions, technical contracts, historical data, and queue rules.

**Approach:** Edit the existing presentation and notification generators in place. Keep the existing notification presenter, shared cancellation content, and activity label map as the sources of truth. No new runtime modules, migrations, dependencies, or functionality.

- [x] Update `pages/admin/{appointments,clinic}.html` and `scripts/components/{admin-dashboard,admin-clinic,admin-archive}.js`; inspect `admin-notifications.js` and Clinic archive presentation and change only if needed. Version modified scripts in their actual HTML consumers, including `pages/admin/{dashboard,archive}.html`.
- [x] Update remaining visible copy in `app/Http/Controllers/{AdminClinicController,NotificationController}.php`, `app/Models/Notification.php`, and `app/Services/{ActivityRecorder,PetServiceSessionPolicy}.php`. The previously completed `BookingController`, `ClinicWalkinController`, `AdminBookingController`, shared cancellation content, customer notification presenter, and expiry copy required no further production changes.
- [x] Extend `tests/Feature/AdminNotificationCenterTest.php` before changing notification compatibility. Cover historical spellings, already-correct wording, missing related records, names, reasons, walk-ins, untouched database snapshots, and existing filtering/counts/permissions. Normalize only generated registration phrases in relevant event types.
- [x] Update obsolete existing expectations in the directly affected Node and PHPUnit tests; retain legacy input fixtures. Exercise Grooming cards/dialogs/archive details, Clinic labels, notifications, activity labels, check-in/queue invariants, and shared customer consumers.
- [x] Run focused tests, PHP/JS syntax, module compilation, asset-version checks, and `git diff --check`. Inspect only the existing admin dashboard browser tab at responsive widths; leave manual navigation, data entry, OTP, password resets, and full workflows to the user.

**Acceptance:** Online sources say `Online Queue Registration`; activity filter label is `Online Queue Registrations`; Grooming dialog says `Cancel queue registration?`; Clinic helper says `Provided by the pet owner during queue registration.` Internal values and operational status labels are unchanged. No stored-record rewrites, additional queries, requests, or polling.

## Actual changes

- Grooming incoming cards, appointment entry sources, Clinic case labels, backend appointment-type labels, archived Grooming entry sources, and the existing-registration warning now use the approved terms.
- Notification titles and fallback messages use `New Online Queue Registration`, `New Clinic Online Queue Registration`, and `Grooming Queue Registration Rescheduled`. No-show source details and the activity filter use the new wording; the `pre_registrations` key and type mappings remain unchanged.
- The existing historical presenter now recognizes generated registration prefixes and no-show clauses for the relevant notification types. It leaves legacy words in names, cancellation reasons, and unrelated text intact, and does not modify stored snapshots. Existing ISO schedule-date formatting is limited to generated `on`/`from`/`to` date fields so ISO-shaped substrings in references or names are preserved. Already-correct labels remain idempotent.
- The shared activity label map now supplies `Queue Registration submitted/updated/cancelled/expired/marked No Show` to the dashboard and activity log. Cross-service cancellation message generation uses `queue registration`; cancellation behavior is unchanged.
- `css/custom.css` adds one scoped rule allowing the longer incoming source badge to wrap inside its existing 160px column. Other layouts, badge styling, and status text remain unchanged.
- Modified script references in the four existing admin HTML consumers, and the Grooming CSS reference, use `queue-copy=20261103-admin-v1`. No migrations, new dependencies, or generated deployment files are needed.

## Modified files

- PHP production: `app/Http/Controllers/AdminClinicController.php`, `app/Http/Controllers/NotificationController.php`, `app/Models/Notification.php`, `app/Services/ActivityRecorder.php`, `app/Services/PetServiceSessionPolicy.php`.
- UI/assets: `pages/admin/appointments.html`, `pages/admin/clinic.html`, `pages/admin/archive.html`, `pages/admin/dashboard.html`, `scripts/components/admin-dashboard.js`, `scripts/components/admin-clinic.js`, `scripts/components/admin-archive.js`, `css/custom.css`.
- Existing PHPUnit tests: `tests/Feature/AdminNotificationCenterTest.php`, `tests/Feature/ClinicAdministrationAuthorizationTest.php`, `tests/Feature/GroomingAdministrationAuthorizationTest.php`, `tests/Feature/ActivityLogTest.php`.
- Existing frontend tests: `tests/admin-grooming-board-regression.cjs`, `tests/admin-grooming-history-regression.cjs`, `tests/admin-clinic-medical-record-regression.cjs`, `tests/admin-clinic-patient-profile-regression.cjs`, `tests/admin-notifications-browser.cjs`.
- This implementation and verification record. No new test files or runtime components were added.

## Verification

- **93 unique focused PHPUnit tests, 1,246 assertions passed** using the documented PHP 8.4.26 runner and existing isolated SQLite `:memory:` configuration. Execution outside the sandbox was necessary for its bootstrap ACL limitation. Coverage includes notifications, activity labels, role permissions, filters, unread/read state, pagination, archive display, Clinic/Grooming cancellation and transfer messages, no-shows, queue assignment/check-in ordering, and shared customer registration behavior. The historical normalization regressions were observed failing before the corresponding fixes and then passing.
- **12 frontend regression scripts passed:** `admin-grooming-incoming-card`, `admin-grooming-board`, `admin-grooming-history`, `admin-clinic-medical-record`, `admin-notification-center`, `admin-notifications-date-grouping`, `admin-activity-log`, `admin-clinic-patient-profile`, `admin-clinic-history`, `customer-grooming-visit-summary`, `confirmation`, `clinic-visit-summary`.
- JavaScript syntax passed for all 8 changed JS/CJS files; PHP syntax passed for all 9 changed PHP files. The three modified browser entrypoints bundled in memory with the installed esbuild. CSS parsing, asset-version checks, original UTF-8 BOM preservation, and `git diff --check` passed.
- Focused source scan found no obsolete visible labels in the affected admin/staff implementations. Legacy phrases remain deliberately in the compatibility matcher and historical test inputs.
- Used the single existing ChatGPT browser tab at `/admin/dashboard`, without page navigation or form input. The refreshed activity feed visibly shows `Queue Registration submitted`; DOM measurements at 320, 375, 414, 768, and 1280 pixels showed no horizontal overflow. Saved desktop/mobile evidence, restored the viewport, and left the tab open. The browser reported no console errors or warnings.

## Limits and deployment

Manual navigation, actual data entry, OTP, password resets, and complete role workflows remain with the user as requested. Grooming/Clinic/Archive pages and their modals were verified through source review and focused automated regressions, and were not manually traversed in the localhost browser. The existing multi-role Playwright notification browser script had its obsolete fixtures updated and passed syntax checking; it was not executed, preserving the one-role-tab boundary. The complete npm production build was unnecessary for these static copy/CSS changes; module bundling and syntax were checked instead. No Hostinger deployment or database changes were performed. Deploy the changed PHP and static assets together through the normal process.
