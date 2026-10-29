# Customers Clinical History Implementation Plan

**Goal:** Replace Customers' expanded medical/vaccination cards with Clinic-style medical and confinement tables and read-only details inside the existing pet modal.

**Architecture:** Keep Clinic's current workspace and legacy profile responses compatible. Add a permission-checked, paginated clinical history mode covering finalized cases and eligible unlinked treatments. Extract Clinic's read-only section templates and pure display helpers for both real consumers; Customers loads only its selected history and detail.

**Tech Stack:** Laravel, Alpine.js, existing custom elements, Tailwind, Node VM regressions, PHPUnit.

**Spec:** User attachment `339c95eb-ae43-492e-b41b-dcdfca556d16/Pasted text.txt`.

## Constraints

- Preserve blue pet header, Overview, Grooming, editing, Clinic behavior and authorization.
- No nested detail modal, clinical mutations, new framework, database migration, commit, push or deployment.
- Customers: Overview / Grooming Records / Medical Records / Confinements; Phosphor only.
- No publication wording in Customers; retain backend lifecycle states.
- No manual localhost navigation or input testing; reuse the single existing role tab for visual inspection only.

## Files and verification

1. Update `tests/Feature/AdminPetProfileTest.php`, `AdminPetProfileController.php`, `PetProfileHistoryService.php`, and the existing API helpers.
   - Add failing coverage for finalized medical, medicine/vaccine-only and service-only cases, deduplication, historical treatments, pagination, pet scoping, forbidden roles and invalid records.
   - Add grooming-only and clinical history modes and a history-safe view of the existing details endpoint, without altering Clinic's default response.
   - Verify with the focused PHPUnit class and existing authorization tests.
2. Extract `scripts/components/clinic-record-presentation.js` from the existing Clinic formatters and section markup; wire both pages and update existing Clinic regressions to load the shared asset.
   - Preserve the Clinic section layout, field names, attachments, treatment and service details, and monitoring pagination.
   - Add Customers navigation/loading state in `admin-customers.js`; remove old vaccination-only helpers and cards in `clients.html`.
   - Test lazy requests, retries, context changes (including reopening the same pet), pagination, conditional sections, historical records, keyboard/back focus, and bounded request reuse.
3. Reuse existing styles and move only directly shared section styling as needed; update affected asset versions.
   - Verify Phosphor sprite references, Alpine template syntax, focused frontend regressions, existing Clinic regressions, production build, and final diff.

## Review focus

- A treatment-only session must appear once even with several treatments or services.
- Draft/voided treatments and unfinished cases must stay out of Customers without changing Clinic access to its own workspace data.
- Historical medicine records with no case retain their actual identity and date.
- Closing/reopening the same pet must reject late responses from the previous modal instance.
- Confinement navigation must preserve list scroll/focus and fetch monitoring only for the selected episode.


## Completed implementation and verification

- Customers now has Overview, Grooming Records, Medical Records and Confinements. Medical and confinement tables use Clinic's compact layout and Phosphor icons; selected records remain in the existing pet modal with back/scroll/focus restoration.
- Removed the old Customers medical cards, vaccine-only tab, obsolete vaccine status/dose helpers and their state. Backend vaccination functionality and client-facing history remain intact.
- `clinic-record-presentation.js` owns the shared read-only medical/treatment/service/confinement templates, treatment fields, date/time/price/service formatters, duration calculations and confinement fields. Clinic keeps its workspace behavior; Customers uses compact conditional sections and maps the treatment lifecycle to clinical wording.
- The existing pet profile endpoint has a paginated clinical-history mode, plus a grooming-only mode. Existing case details support a history-safe view. Eligible finalized cases appear once, including treatment-only and service-only sessions; eligible unlinked treatments retain their actual historical identity. New clinical history requests enforce existing Clinic permissions.
- Clinical lists load on selection (20 rows per page). Case details and monitoring are fetched only on selection, never for every row. Pending requests are deduplicated; completed detail/monitoring responses expire after 15 seconds. Modal context changes reject stale responses. Focus/visibility, session unlock and Clinic update events invalidate local history reuse; no new polling or persistent clinical cache was added.
- Updated obsolete Customers interface assertions and existing Clinic VM test harnesses for the shared script. A pre-existing status-tab assertion expected Portal Accounts despite HEAD already displaying Online Accounts; corrected only the assertion.

### Results

- Focused PHPUnit: **301 tests, 3,463 assertions passed**. Includes profile/history, customer interface, Clinic authorization, confinement, services/billing, treatment interface and preserved client histories. Used the project PHP 8.4 runtime and existing isolated in-memory SQLite/non-sending configuration. The sandbox could not read the test bootstrap; the authorized external test process completed successfully.
- **20 frontend regression scripts passed**: Customers clinical history/actions; Clinic case details, patient profiles, records, treatments, confinement, payment, services and history; initial-render/loading, navigation and caching checks.
- **21 modified/new JavaScript files passed syntax checks**. HTML nesting, one-root Alpine templates, **1,814 Alpine expressions**, pet-tab control relationships, Phosphor symbols and local asset paths verified.
- `npm run build` passed: generated policies, OCR/QR/PDF assets, Tailwind and Vite. Only the existing outdated Browserslist database warning appeared. Generated tracked CSS was refreshed; ignored Vite/vendor outputs retain the existing deployment conventions.
- `git diff --check` passed.

### Deployment and manual review

Both pages include the new shared script; affected API, UI script and CSS references have cache-busting versions. No new packages, server dependencies, environment changes or migrations are required. Deploy the changed backend files and static assets together through the existing Hostinger process, including the new shared script and regenerated CSS. No commit, push or deployment was performed.

Inspected the existing signed-in Customers tab in the ChatGPT browser without navigating or entering data. Manual modal navigation, responsive browser review and functional input/OTP/password testing remain with the user as requested. No Core Web Vitals measurements or live Hostinger validation are claimed.

### Changed files

- `app/Http/Controllers/AdminPetProfileController.php`
- `app/Services/PetProfileHistoryService.php`
- `css/input.css`
- `css/output.css`
- `pages/admin/clients.html`
- `pages/admin/clinic.html`
- `scripts/api.js`
- `scripts/components/admin-clinic-confinement.js`
- `scripts/components/admin-clinic.js`
- `scripts/components/admin-customers.js`
- `scripts/components/admin-vaccinations.js`
- `tests/Feature/AdminCustomersCardTypographyInterfaceTest.php`
- `tests/Feature/AdminPetProfileTest.php`
- `tests/admin-clinic-case-details-regression.cjs`
- `tests/admin-clinic-medical-record-regression.cjs`
- `tests/admin-clinic-patient-profile-regression.cjs`
- `tests/admin-clinic-payment-regression.cjs`
- `tests/admin-clinic-record-workflow-regression.cjs`
- `tests/admin-clinic-unified-records-regression.cjs`
- `tests/admin-clinic-vaccination-reconciliation-regression.cjs`
- `tests/admin-navigation-performance-regression.cjs`
- `tests/admin-universal-loading-regression.cjs`
- `tests/clinic-confinement-regression.cjs`
- `tests/clinic-first-render-regression.cjs`
- `tests/clinic-inventory-pricing-regression.cjs`
- `tests/clinic-subrole-workflow-regression.cjs`
- `tests/clinic-toast-regression.cjs`
- `scripts/components/clinic-record-presentation.js`
- `tests/admin-customers-clinical-history-regression.cjs`
- `docs/superpowers/plans/2026-10-29-customers-clinical-history.md`
