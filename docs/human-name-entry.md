# Human names and customer name editing

Implemented on `gerald-frontend`, October 28, 2026. No commit, push, deployment, migration, or production data change was performed.

## Policy and coverage

`resources/data/human-name-policy.json` supplies the common 100-character limit and explicit common-Mc allowlist. Laravel loads it through `config/human_names.php`. `npm run build:names` generates the matching policy in `scripts/utils/human-name.js`; both implementations use `tests/Fixtures/human-names.json` for conformance.

`app/Support/HumanName.php` and `app/Rules/ValidHumanName.php` centralize backend behavior. `User::normalizeName()` delegates to that policy. Entirely lowercase/uppercase input is formatted; mixed casing remains intentional. Examples include Gerald, De La Cruz, de la Cruz, McDonald, O'Neill, Peña, Muñoz, hyphenated names, combining accents, and CJK names. Ordinary unnecessary spaces are collapsed. Numeric characters in Unicode category N, controls, unsupported symbols, and separator-only values fail validation. The browser strips numbers while retaining valid text and cursor position, defers changes during IME composition, and shows a short associated inline error. Valid typing/deletion clears the error immediately; required errors appear on blur/submission.

Integrated entry points:

- Signup and pending registration editing/recovery: `pages/client/signup.html`, `scripts/auth/client/signup.js`, `AuthController::registrationData()`; POST `/api/register` and PUT `/api/register/pending`.
- Grooming/clinic owner entry: `pages/admin/walk-in-booking.html`, `scripts/components/walk-in-owner-step.js`, `StoreWalkinRequest`, `StoreClinicWalkinRequest`, and `StoreUnregisteredCustomerRequest`; existing owner-check and walk-in routes. Selected existing owners still resolve their stored identity, including historical names, without recasing or rewriting them.
- Add Customer: `pages/admin/clients.html`, `scripts/components/admin-customers.js`; POST `/api/admin/customers/unregistered`.
- Staff creation/profile: `pages/admin/settings.html`, `scripts/components/admin-settings.js`, `AdminSecurityController`; POST `/api/admin/security/staff-accounts`, POST `/api/settings/security/profile/validate`, PATCH `/api/settings/security/profile`.
- Named Pet Groomers: `resources/views/admin/availability.html`, `scripts/components/admin-availability.js`, `PetGroomerController`; existing POST/PATCH `/api/admin/pet-groomers` routes.
- Customer Personal Information: `pages/client/settings.html`, `scripts/components/client-settings.js`, `scripts/api.js`, and `CustomerController::updateOwnName()`; PATCH `/api/customer/profile/name`.

Middle initials and non-human-name inputs retain their existing behavior. Historical records are not normalized when read. Staff username generation retains its previous independent casing convention. Duplicate matching and account ownership are unchanged.

## Customer editing and cache behavior

The existing Personal Information card provides Edit name, current-password confirmation with reveal, Save changes, and Cancel. It preserves drafts after password errors, prevents duplicate submissions, clears the password after completion/cancellation, and restores the latest saved values on Cancel without a request. Unchanged submissions do not call the API.

The endpoint uses existing Sanctum, account-status, active-session, customer-role, and throttle middleware. It locks the authenticated account, verifies the current password with Hash::check on every request, accepts only the two names and current_password, and returns the existing `/me` identity shape. Incorrect passwords return `errors.current_password: ["Incorrect password."]`. It creates no OTP flow, credentials, or new session, and exposes/logs no password. Normal model activity recording remains intact.

Successful updates replace only the profile cache and cached sidebar name. Profile revisions reject older in-flight `/me` responses. Persistent-session tabs receive an owner-scoped name signal, merge the names into their retained profile, and refresh visible Settings/dashboard identity from that cache. A tab that missed the signal during navigation reconciles its old retained profile when initializing. Session-only logins keep their existing storage lifetime. Pets, booking history, notifications, capacity, and clinic data are not invalidated by a name update.

## Verification

- `npm test`: 85/85 regression scripts passed.
- Affected Laravel suites: 757 tests, 10,232 assertions, no failures, one existing MySQL-only capacity skip. Suites include HumanName, CustomerNameUpdate, EmailVerification, AdminStaffAccountManagement, AdminCredentialChange, AdminUnregisteredCustomer, AdminCustomerDeletion, grooming/clinic authorization, independent authentication sessions, and Settings interface coverage.
- Final focused Unicode/name/profile checks after cleanup: 10 tests, 161 assertions, passed. Final cache/session/Settings frontend regressions passed after the missed-signal regression was added.
- `npm run build`: passed. Existing outdated Browserslist-data notice remains; no dependency changes were needed.
- Browser acceptance used the existing synthetic loopback fixture, without database access: Edit, Unicode-number rejection, error clearing on empty input, blur capitalization, incorrect-password retry, successful save/avatar refresh, and read-only return were verified. Layout was checked at 320, 375, 414, and 768 pixels with no horizontal overflow.
- Existing unconditional staff-casing assertions were updated in place. A pre-existing staff-email attribute-order assertion was converted to an actual DOM attribute check, and a walk-in fixture was corrected to use an allowed email domain, preserving its pickup/persistence assertions. Legacy invalid-name fixtures still cover username fallback using historical model data, while new staff entry rejects those names.

PHP tests used the documented portable PHP 8.4 runtime and the repository's in-memory SQLite/non-sending configuration. Windows sandbox file checks require running PHPUnit outside that sandbox; no development database was reset.

## Hostinger redeployment

1. Use the existing release process for this branch. Build with `npm ci` and `npm run build` in the release/CI environment; deploy the new policy JSON/config/classes together with all changed frontend files, `css/output.css`, and generated `public/build` assets. Standalone HTML and scripts are not bundled by Vite and must be included. The dated script/CSS references cover these assets.
2. Refresh Composer's optimized autoloader using the existing production install step, and rebuild the existing Laravel configuration and route caches so the policy and PATCH route are available. Preserve the existing production URLs, APP_KEY, credentials, HTTPS cookies, and session settings.
3. No database migration, historical-name rewrite, new environment variable, or OTP/SMS/email provider configuration is required for this feature.
4. Smoke-test the deployed signup, both walk-in paths, staff creation/profile, Add Customer, and customer Edit/Cancel/password retry/Save. Verify the changed name on dashboard navigation and another authenticated persistent-session tab. Production acceptance and deployment have not been performed here.
