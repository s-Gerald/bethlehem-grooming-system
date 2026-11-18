# Owner Account Form Implementation Plan

**Goal:** Give the Owner one Security form that requires the current password for every change and uses OTP for credential changes.

1. Update `AdminCredentialChangeTest` to reproduce missing and incorrect Owner profile passwords. Verify partial name updates preserve credentials and the session. Add password verification to the existing Owner profile action under the user-row lock.
2. Extend the Settings and sidebar regressions for changed fields, no-op and duplicate submission guards, conditional password validation, mixed results, and OTP. Consolidate the Owner fields and submit handler in `admin-settings.js` and `settings.html`. Keep the profile and credential API calls focused. Save names before requesting the existing credential challenge and retain their saved values if that request fails.
3. Exercise the unified form at desktop and narrow widths in the existing browser harness. Run the focused PHP and frontend tests before the broader affected checks.

## Verification commands

Run from `C:/bethlehem-clinic-frontend` using PHP 8.4.

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminCredentialChangeTest.php --filter 'owner_profile|test_admin_username_change_requires|test_expired_change_does_not|test_owner_names_save_before'
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminCredentialChangeTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/AccountSecurityActivityTest.php tests/Feature/SessionLockTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/ActivityLogTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/TransactionHistorySearchTest.php tests/Unit/ActorAttributionTest.php
& $clinicPhp vendor/bin/phpunit tests/Feature/AdminCredentialChangeTest.php
node tests/admin-security-settings-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/admin-loading-regression.cjs
node tests/credential-policy-regression.cjs
node tests/human-name-regression.cjs
$env:PATH = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84;' + $env:PATH
npm test
git -c core.safecrlf=false diff --check
```

The focused password and OTP run passed 7 tests and 192 assertions after the route change. The Owner and staff interface tests passed 12 tests and 264 assertions. The broader affected run passed 323 tests and 6,289 assertions before the final throttle isolation. The final `AdminCredentialChangeTest` run passed 97 tests and 1,430 assertions. The frontend suite passed all 104 scripts. No failures remain in the checks run.

The mixed submission regression reproduced a 429 response when prior profile saves consumed the credential route’s request allowance. Giving the existing Owner profile route its own throttle key resolved the failure and kept the credential challenge limit intact.

For the browser check, run the local Laravel server from `public` with `& $clinicPhp -S 127.0.0.1:8000 C:/bethlehem-clinic-frontend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. From the repository root, set `PLAYWRIGHT_PATH` to the local Playwright installation and `LOCAL_REVIEW_CASES` to `owner-profile,manager`, then run `node tests/admin-sidebar-first-paint-browser.cjs`. The browser tests use mock account data. The Owner profile passed at 320, 375, 414, 768, and 1440px, including the combined edit at desktop width. The existing Owner and Manager Security checks passed at 390 and 1440px.
