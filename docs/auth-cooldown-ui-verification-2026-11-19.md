# Authentication cooldown presentation verification

Implemented on `gerald-frontend`, based on fetched `backend/gerald-frontend` at `e5db4aca1a4a1d14c3d0894b2b8bd0122a97d1d8`. Repository instructions and directly affected implementations were inspected before changes.

## Result

Existing verification cooldowns now show a compact amber warning immediately below the code inputs: “Too many incorrect codes. Try again in 1:00.” The visible clock decreases each second, clears at zero, and uses semibold tabular numerals. General authentication cooldowns use “Too many attempts.” Genuine validation, expired-code, delivery and operation errors keep their existing red treatment.

The warnings render the existing server-derived deadlines; they do not introduce another timer or change security policy. Verification, resend and code-expiration deadlines remain independent. Countdown completion never submits, sends a message, reloads or moves focus. Screen-reader status changes at countdown boundaries rather than each tick.

Privileged and customer Settings confirmation codes remain editable while verification is paused. Confirmation stays disabled until the existing server-defined wait ends and the code is complete. The privileged button retains “Confirm change.”

## Surfaces audited

| Surface | Outcome |
| --- | --- |
| Owner/Admin/Manager/Staff privileged credential confirmation | Corrected: live amber warning below code grid, independent resend clock, editable digits, stable confirmation label. Compact grid/footer also fit a 320px viewport. |
| Staff password-change request | Corrected: live warning near the form, using the same remaining time as its existing button countdown. |
| Staff email change: request, current-email code, new-email code | Corrected: action and code warnings; resend remains on its own control. |
| Admin-assisted staff email recovery: start, code, manage | Corrected: visible action/code warnings using the existing state and timers. |
| Public staff email recovery confirmation | Corrected: existing action feedback displays live amber remaining time and restores genuine error styling when needed. |
| Customer Settings email/password confirmation | Corrected: code warning beneath input, separate general action warning, independent resend clock, editable code, authoritative expiration preserved. |
| Customer password-change request | Corrected: live warning next to the password form rather than static global error feedback. |
| Customer phone verification/replacement | Corrected: live verification warning, separate resend/retry/action clocks, neutral code-expiry helper. Shared entry points load the updated module. |
| Forgot password: email request, OTP, reset submission | Corrected: live warnings from existing deadlines, separate resend clock, expiration preserved. Normal successful resend pacing is not incorrectly described as excessive attempts. |
| Password setup and expired-link replacement request | Corrected: live amber action/resend feedback. A directly related frontend listener-order issue was fixed so the existing replacement-link action is reachable after an expired link. |
| Registration submission | Corrected: existing server retry now displays a live amber warning; genuine errors remain red. |
| Password sign-in | Existing visible live countdown retained. |
| Sign-in email OTP | Existing visible clock, verification/resend separation, restoration and no automatic requests retained. |
| Registration/email-link verification and authentication resend controls | Existing understandable live action countdowns retained; no duplicate warning added. |
| Session unlock | Existing live action-button countdown retained; no extra dependency introduced into session handling. |

## Implementation boundaries

- Shared presentation is additive to `VerificationCode`: `cooldownMarkup` and `renderCooldown` reuse `formatCountdown` and render existing timers. One scoped CSS component supplies the compact warning treatment.
- No backend, route, configuration, database, cooldown progression, threshold, expiration, abuse ceiling or session policy changed. Existing server `retry_after` was sufficient throughout.
- Owner name-only changes still require the current password without OTP; credential changes still use privileged OTP. Existing selective updates, identity propagation and role distinctions remain intact.
- Changed asset query strings ensure deployed cached pages load the corrected scripts. Dashboard/history/pet/notification entry-point changes only update the shared phone module's cache version.
- The expired setup-link compatibility fix registers the existing replacement-link listener before the asynchronous link inspection can return early. It adds no new security rules or API behavior.

## Tests run

All final checks passed. No unresolved test failures.

PHP command, using the installed PHP 8.4 runtime:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' vendor/bin/phpunit tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/PasswordResetInterfaceTest.php tests/Feature/AdminCredentialChangeTest.php tests/Feature/CustomerPasswordChangeTest.php --log-junit storage/logs/cooldown-ui-final.xml
```

Result: **133 tests, 2,259 assertions**. This covers interface placement plus the existing Owner/privileged/customer credential workflows. [PHP output](C:/bethlehem-clinic-frontend/storage/logs/cooldown-ui-final.txt).

Frontend regression commands, all passed:

```text
node tests/auth-cooldown-presentation-regression.cjs
node tests/admin-security-settings-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/client-settings-loading-regression.cjs
node tests/phone-auth-cooldown-regression.cjs
node tests/staff-email-recovery-regression.cjs
node tests/customer-signup-verification-regression.cjs
node tests/manager-security-regression.cjs
node tests/customer-data-cache-regression.cjs
node tests/email-delivery-regression.cjs
node tests/customer-dashboard-history-regression.cjs
```

Real browser command, passed:

```powershell
$env:PLAYWRIGHT_PATH = 'C:/Users/Gerald/AppData/Local/npm-cache/_npx/e41f203b7505f1fb/node_modules/playwright'
node tests/auth-cooldown-presentation-browser.cjs
```

The browser check uses production markup/scripts with isolated API fixtures and headless Edge. It checks the privileged modal at **320, 375, 414 and 768px**, plus customer confirmation and phone dialogs. Coverage includes 1:00 → 0:59, independent verification/resend/expiry clocks, warning placement and computed colors/typography, genuine red errors, editable modal codes without API calls, automatic control restoration, no automatic submit/resend, no per-second live-region changes, and no horizontal overflow. [375px screenshot](C:/bethlehem-clinic-frontend/storage/app/testing/auth-cooldown-ui.png).

Additional checks: `node --check` on changed application JavaScript, `git diff --check`, and confirmation that `app`, `config` and `routes` have no diff. No production build or unrelated full test suite was needed for these scoped script/HTML/CSS changes. Browser API fixtures do not exercise live SMTP/SMS delivery.

### Failures investigated during development

- The new expired setup-link test exposed an actual frontend listener-order defect; fixed and verified.
- One existing customer-dashboard phone fixture expected a permanent `locked` state that the starting branch's production implementation no longer returned. It now tests the current `active` challenge plus temporary `retry_after`, preserving verification-blocking and independent-resend coverage.
- Test harnesses were updated where needed to model real DOM methods, separate timer IDs, versioned module identity and Alpine's deferred visibility update. Final regressions and browser checks pass.
- The dashboard regression emits Node's existing experimental VM-module-loader warning; this is not a test failure.

## Intentional presentation exceptions

No audited interactive cooldown with a known remaining duration is left with only static “Please wait” feedback. Already clear button-based live countdowns were retained where another warning would duplicate information. Existing sign-in/session formats remain readable and live.

Network in-flight labels such as “Sending…” or “Please wait…” have no authoritative retry duration and remain busy states. Non-interactive security-alert deduplication and provider/emergency limits were not changed; this task does not invent countdowns for those backend notification policies.

## Exact files changed

The following list includes this report. Cache-only entry-point changes are identified above; all other files implement or verify the directly related cooldown presentation.

- [css/components/auth-cooldown.css](C:/bethlehem-clinic-frontend/css/components/auth-cooldown.css)
- [css/components/verification-code.css](C:/bethlehem-clinic-frontend/css/components/verification-code.css)
- [docs/auth-cooldown-ui-verification-2026-11-19.md](C:/bethlehem-clinic-frontend/docs/auth-cooldown-ui-verification-2026-11-19.md)
- [pages/admin/settings.html](C:/bethlehem-clinic-frontend/pages/admin/settings.html)
- [pages/client/confirm-email-recovery.html](C:/bethlehem-clinic-frontend/pages/client/confirm-email-recovery.html)
- [pages/client/dashboard.html](C:/bethlehem-clinic-frontend/pages/client/dashboard.html)
- [pages/client/forgot-password.html](C:/bethlehem-clinic-frontend/pages/client/forgot-password.html)
- [pages/client/grooming-history.html](C:/bethlehem-clinic-frontend/pages/client/grooming-history.html)
- [pages/client/my-pets.html](C:/bethlehem-clinic-frontend/pages/client/my-pets.html)
- [pages/client/notifications.html](C:/bethlehem-clinic-frontend/pages/client/notifications.html)
- [pages/client/pet-details.html](C:/bethlehem-clinic-frontend/pages/client/pet-details.html)
- [pages/client/set-up-password.html](C:/bethlehem-clinic-frontend/pages/client/set-up-password.html)
- [pages/client/settings.html](C:/bethlehem-clinic-frontend/pages/client/settings.html)
- [pages/client/signup.html](C:/bethlehem-clinic-frontend/pages/client/signup.html)
- [scripts/auth/client/signup.js](C:/bethlehem-clinic-frontend/scripts/auth/client/signup.js)
- [scripts/auth/email-recovery.js](C:/bethlehem-clinic-frontend/scripts/auth/email-recovery.js)
- [scripts/auth/forgot-password.js](C:/bethlehem-clinic-frontend/scripts/auth/forgot-password.js)
- [scripts/auth/set-up-password.js](C:/bethlehem-clinic-frontend/scripts/auth/set-up-password.js)
- [scripts/auth/verification-code.js](C:/bethlehem-clinic-frontend/scripts/auth/verification-code.js)
- [scripts/components/admin-settings.js](C:/bethlehem-clinic-frontend/scripts/components/admin-settings.js)
- [scripts/components/client-settings.js](C:/bethlehem-clinic-frontend/scripts/components/client-settings.js)
- [scripts/components/customer-phone-verification.js](C:/bethlehem-clinic-frontend/scripts/components/customer-phone-verification.js)
- [scripts/components/customer-pre-registration-button.js](C:/bethlehem-clinic-frontend/scripts/components/customer-pre-registration-button.js)
- [scripts/components/customer-settings-confirmation.js](C:/bethlehem-clinic-frontend/scripts/components/customer-settings-confirmation.js)
- [tests/Feature/AdminSecuritySettingsInterfaceTest.php](C:/bethlehem-clinic-frontend/tests/Feature/AdminSecuritySettingsInterfaceTest.php)
- [tests/admin-security-settings-regression.cjs](C:/bethlehem-clinic-frontend/tests/admin-security-settings-regression.cjs)
- [tests/auth-cooldown-presentation-browser.cjs](C:/bethlehem-clinic-frontend/tests/auth-cooldown-presentation-browser.cjs)
- [tests/auth-cooldown-presentation-regression.cjs](C:/bethlehem-clinic-frontend/tests/auth-cooldown-presentation-regression.cjs)
- [tests/client-settings-loading-regression.cjs](C:/bethlehem-clinic-frontend/tests/client-settings-loading-regression.cjs)
- [tests/customer-dashboard-history-regression.cjs](C:/bethlehem-clinic-frontend/tests/customer-dashboard-history-regression.cjs)
- [tests/customer-signup-verification-regression.cjs](C:/bethlehem-clinic-frontend/tests/customer-signup-verification-regression.cjs)
- [tests/frontend-auth-session-regression.cjs](C:/bethlehem-clinic-frontend/tests/frontend-auth-session-regression.cjs)
- [tests/phone-auth-cooldown-regression.cjs](C:/bethlehem-clinic-frontend/tests/phone-auth-cooldown-regression.cjs)
- [tests/staff-email-recovery-regression.cjs](C:/bethlehem-clinic-frontend/tests/staff-email-recovery-regression.cjs)
