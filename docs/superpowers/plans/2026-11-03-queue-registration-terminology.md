# Queue Registration Terminology Implementation Plan

**Goal:** Apply the supplied customer terminology standard to Grooming and Clinic registration without changing workflows or queue rules.

**Architecture:** Edit existing visible strings in place. Keep the shared confirmation and dashboard summary renderers; update their current callers' asset query versions for deployment. No new runtime modules or dependencies.

**Tech Stack:** Laravel/PHP, HTML, JavaScript modules, Node regression scripts, PHPUnit.

## Scope and checks

- [x] Update `pages/client/{pre-register,grooming-pre-registration,clinic-visit-summary,clinic-visit-confirmed,dashboard,grooming-history}.html` and `scripts/components/{booking-review-step,booking-consent-step,booking-confirmed-step,clinic-visit-summary,clinic-visit-confirmed,customer-pre-registration-button,grooming-visit-summary}.js`. Preserve the dashboard's `Start Queue Registration`, information icon/note, walk-in text, print availability, statuses, reference/queue distinction, and all behavior.
- [x] Update customer success/availability copy and directly related registration notifications in `app/Http/Controllers/{BookingController,ClinicWalkinController,CustomerNotificationController,AdminBookingController}.php`, `app/Services/{RegistrationServiceDay,PreRegistrationExpiry,CancellationNotificationContent}.php`, and phone-entry text in `scripts/services/booking-form-access-guard.js`. The AdminBookingController edit changes only the message sent to customers. The existing shared cancellation formatter continues to serve its actual consumers. Saved customer notification text is normalized on presentation without modifying stored records.
- [x] Version changed modules and their immediate importers: `scripts/components/{grooming-pre-registration,booking-pet-step,pre-registration-options,client-dashboard}.js`, the customer HTML entrypoints above, `pages/client/{my-pets,pet-details,settings,notifications,booking-pet-details}.html` where they consume changed scripts. Use `queue-copy=20261103-v1`, preserving existing query parameters. No file deletions.
- [x] Update existing tests in `tests/{customer-grooming-visit-summary,online-registration-selector,confirmation,grooming-consent}-regression.mjs` and `tests/Feature/{CustomerPreRegistrationAccessInterface,GroomingSinglePageInterface,QueuePreRegistrationModel,GroomingAdministrationAuthorization,ClinicAdministrationAuthorization,CustomerNotificationPagination,AdminNotificationCenter}Test.php`. Add `tests/clinic-visit-summary-regression.mjs` for the previously uncovered dynamic Clinic submit/retry behavior. New assertions exercise observable output and submission behavior.
- [x] Run focused Node regressions and PHPUnit suites for registration, confirmation, summaries, access, and check-in. Check PHP/JS syntax and the diff. Inspect the existing browser tab at desktop/mobile widths without navigating or submitting forms.

## Review focus

- Initial and restored submit labels both read `Submit Queue Registration`.
- Online confirmations use `Queue Registration Received`; printed titles identify the service.
- Existing walk-in confirmations still show actual assigned queue numbers.
- Clinic dashboard details show the new summary name and entry source; printing remains disabled.
- New online submissions return a registration reference and no assigned queue number; check-in behavior remains unchanged.

## Verification results

- PHPUnit, PHP 8.4.26: **102 tests, 742 assertions passed** across three focused runs. Used the existing `phpunit.xml` isolation (SQLite `:memory:`), with execution outside the sandbox because its ACL checks prevent PHPUnit from opening the readable bootstrap.
- Nine frontend regression scripts passed: `online-registration-selector`, `registration-service-day`, `customer-grooming-visit-summary`, `confirmation`, `grooming-consent`, `grooming-flow`, `clinic-visit-summary`, `customer-dashboard-history`, and `client-notification-ui`.
- Syntax checks passed for 17 JavaScript files and 14 PHP files. Seven browser entrypoints bundled successfully in memory with the installed esbuild, without generating deployment files. Changed customer script/import references passed the asset-version check. `git diff --check` passed.
- In the single ChatGPT browser tab, the service selector shows `Queue Registration | Bethlehem` and `Queue registration available`, preserving the original information note, icon, spacing, colors, and typography. DOM measurements at 320, 375, 414, 768, and 1280 pixels showed no horizontal overflow. Desktop and mobile screenshots were captured; the viewport override was reset.
- Focused scan found no outdated visible `Online Registration` wording in the affected customer implementations. The old phrase remains only in the customer notification compatibility matcher, where it is an input to normalization.

## Remaining manual checks and deployment

As requested, actual browser navigation, data entry, OTP submissions, password resets, and end-to-end desktop/mobile workflow testing remain with the user. Review, submission, confirmation, print-document content, summary dialogs, failed submissions, and check-in semantics were covered with automated tests; the full interactive flows were not visually traversed. The complete npm production build was not run for these static copy changes; browser module bundling and syntax were verified instead. No Hostinger deployment was performed. Deploy the changed static HTML/JavaScript and PHP files together using the normal project deployment process; no migrations or dependencies are needed.
