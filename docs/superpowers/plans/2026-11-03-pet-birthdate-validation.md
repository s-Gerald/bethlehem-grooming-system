# Pet Birthdate Validation Implementation Plan

**Goal:** Apply the inclusive Manila calendar range from today minus 40 years through today to new or changed pet birthdates.

**Architecture:** One Laravel validation rule serves pet writes, grooming registration, and clinic intake. One frontend module serves the existing native date inputs and inline errors. Resolve historical exceptions against existing authorized pets without new queries.

**Constraints:** Preserve optional dates, saved records, customer field locks, confirmations, six-month grooming eligibility, and existing layouts. No dependencies, migrations, environment changes, commits, or pushes. Browser inspection uses the existing customer tab only; manual navigation and functional testing belong to the user.

1. [x] Add failing endpoint and date-boundary regression tests; verify rejection of future/old/invalid dates and preservation of unchanged records.
2. [x] Implement `ValidPetBirthdate` and apply it to `PetController`, `BookingController`, `StoreClinicWalkinRequest`, and resolved clinic pet updates. Verify with focused PHPUnit tests.
3. [x] Implement shared frontend range/error handling and wire My Pets, the dynamically loaded admin editor, and both online registration pages. Test Manila midnight, leap years, restored/programmatic values, native invalid events, confirmation blocking, and legacy constraints.
4. [x] Refresh existing asset/fragment version references, run related regressions and production build, review the diff, and report limitations.

**Review focus:** An unchanged historical value must pass native validation without widening calendar selection; date errors must appear before confirmations, including native validation failures; midnight must refresh boundaries before native submission; new pets must never receive a legacy exception; clinic selections must be validated after owner resolution.

## Implementation notes

- Backend: added `app/Rules/ValidPetBirthdate.php`; updated `PetController.php`, `BookingController.php`, `ClinicWalkinController.php`, and `StoreClinicWalkinRequest.php`.
- Frontend: added `scripts/components/pet-birthdate.js`; updated `my-pets.js`, `admin-customers.js`, and `booking-pet-step.js`, plus their existing date input markup in `my-pets.html`, `pet-edit-form.html`, `booking-pet-details.html`, and `grooming-pre-registration.html`.
- Deployment: refreshed script references in those pages, `pages/admin/clients.html`, `pages/admin/clinic.html`, `admin-clinic.js`, and `grooming-pre-registration.js`, as well as the dynamically fetched admin editor fragment.
- Regression coverage: added `PetBirthdateTest.php` and `pet-birthdate-regression.mjs`; extended the existing customer profile, clinic authorization, grooming authorization, cache/modal, confirmation, breed/fur, and grooming-age tests.
- Both implementations use Manila calendar days with inclusive limits and leap-day clamping. Only unchanged existing birthdates receive the historical exception. The frontend temporarily suspends only that field's range constraints during a legacy submission and restores the canonical picker limits on the next task; other native validation remains active.
- Admin requests that omit birthdate now preserve the saved date. No historical records, migrations, permissions, grooming eligibility rules, or other date fields were changed.

## Verification

- Reproduced the baseline endpoint failures before implementation: a future birthdate was accepted and an admin edit omitting birthdate cleared a legacy value.
- Focused PHPUnit run: 146 tests / 2,034 assertions passed on PHP 8.4.26 with SQLite in memory. Subsequent customer completion/lock coverage passed in a 28-test / 1,074-assertion customer suite; an 18-test / 195-assertion birthdate run passed after adding four saved-pet/empty-birthdate booking cases.
- Final `npm test`: 92/92 regression scripts passed, including calendar boundaries, midnight rollover, drafts, native errors, admin saves, My Pets locks, confirmations, breed restoration, and grooming eligibility.
- `npm run build`: passed. The existing Browserslist dataset warning is non-blocking; no dependencies were updated.
- Changed JavaScript syntax checks, new PHP rule syntax check, and `git diff --check`: passed.

## Manual verification boundary

Inspected the existing Add Pet modal in the single customer browser tab without navigation, data entry, or submission. User retains manual testing of native picker behavior, page navigation, real data entry, OTPs, and password resets. No commit or push was made.
