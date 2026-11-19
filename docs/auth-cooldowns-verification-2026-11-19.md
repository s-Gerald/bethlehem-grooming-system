# Authentication cooldown verification — 2026-11-19

Implemented in the existing `gerald-frontend` checkout of `rij-summit/bethlehem-grooming-system`. Fetched `backend/gerald-frontend` before changes and confirmed it matched HEAD `860aa2dc0f197c33f05696efba4e980a0a56cf15`. Read the current repository `AGENTS.md`. Changes are local and uncommitted; no deployment was requested or performed.

## Implemented behavior

| Workflow | Ordinary cooldown |
| --- | --- |
| Password sign-in | Every five failures: 60, 180, 300, 300… seconds; existing identifier + IP key and IP flood protection retained. Successful password proof clears that pair. |
| Password recovery OTP | Every five failures: 60, 180, 300, 300… seconds. Same unexpired OTP works afterward; successful verification consumes it. Existing recovery expiry remains independent. |
| Owner/Manager/Staff privileged credential OTP | Same progressive policy. Resend rotates the code, and replacing a challenge does not reset source guessing history. |
| Customer email/password security OTP | Same progressive policy. Resend/cancel/restart retain broader guessing history; the obsolete three-send-per-challenge ceiling is removed. Challenge/session/account fingerprints and expiration remain authoritative. |
| Customer current password for security changes | Same progressive policy, scoped by account + source. Reads/state checks do not record failures. |
| Staff self-service email OTP and staff email-recovery proofs | Same progressive policy; code/link expiration, account holds, identity attestations and eligibility checks remain intact. |
| Customer phone OTP and phone replacement password | Same progressive policy. Same unexpired phone code works after cooldown; resend/cancel/restart do not reset failure history. Successful phone verification clears relevant source history and resend progression. |
| Interactive authentication emails | Successful durable issuance gives the next resend delay of 60, then 180, then at most 300 seconds. Shared normalized destination pacing covers registration, login, recovery and security changes across purposes. Rejected requests and failed queue insertions do not advance it. |
| Phone verification SMS | Successful provider issuance gives the next resend delay of 60, then 180, then at most 300 seconds. Account and phone history survive candidate changes/cancellation. Failed/uncertain provider calls do not advance progression; their existing short retry-safety cooldown and emergency cost reservations remain. |
| Customer password-change endpoint flood protection | 10 requests/account/minute and 30 requests/IP/minute. |
| Previously long authentication/security route flood protection | Starts/changes use 10/minute; resends use 30/minute. Service pacing controls actual message issuance. |

The shared policy resets after 1,800 seconds of inactivity; successful verification clears the applicable failure state where appropriate. Source failure keys exclude replaceable challenge/session IDs and include the source IP. No schema changes or new dependencies were introduced. Existing database/Redis cache and durable mail queue architecture remain in use.

`LoginEmailChallengeService` and `LoginEmailChallengeController` were not modified. Their existing 10-minute code validity, 1/3/5 failure progression, cumulative budgets, replacement-code behavior, source isolation, replay protection and transaction/locking behavior remain. Shared email issuance pacing applies to their sends.

Owner first-name/last-name-only changes continue to require the current password without OTP. Owner username/password changes retain privileged OTP. Existing selective updates, identity propagation, roles, session replacement, password policy/breach screening, one-time consumption, delivery errors and security activity are covered by the passing regression suites.

Existing countdown utilities remain in use. The frontend now treats exhausted attempt rounds as temporary server-directed cooldowns. Controls recover automatically without refresh, submission or email/SMS sending. Countdown labels update visually without adding per-second live-region announcements. Staff-email button bindings no longer retain permanent zero-attempt disabling.

## Deliberately retained longer limits

- Login OTP emergency budgets: 50 incorrect attempts/account/15 minutes and 100/IP/15 minutes. Existing outstanding-login-challenge ceiling also remains. These protect high-volume or distributed automation.
- Authentication email emergency source budgets: 60/IP/hour and 200/IP/day. Destination 12/hour and 50/day ceilings and their obsolete environment settings are removed.
- SMS emergency budgets: 60/IP/hour, 100/global/hour and 500/global/day. Old user/phone 5/hour and 20/day settings are removed.
- `security_alert` retains its deliberate 86,400-second warning-email deduplication. `security_notice` retains its existing semantics. These are non-interactive communications.
- OTP and invitation/link lifetimes are expiration deadlines, not retry lockouts; they are preserved.
- External email/SMS provider restrictions and delivery failures remain authoritative.
- The two existing `3/10` middleware limits on ordinary account creation (`admin/security/accounts` and `admin/security/staff-accounts`) remain unchanged, as explicitly excluded business operations.

## Verification results

- Baseline before behavior edits: 417 PHP tests, 5,512 assertions, one existing opt-in MySQL skip; no failures.
- New regressions first reproduced the old password escalation and email-purpose bypass. The shared-helper tests initially failed because the helper did not yet exist.
- Final combined authentication/security run: **686 tests, 10,024 assertions, zero failures/errors, one skip** (the existing phone migration test requiring explicit opt-in to a disposable local MySQL database).
- All nine Node regression scripts listed below passed.
- PHP syntax checks passed for all 33 changed/new PHP files; JavaScript syntax checks passed for the four changed application scripts; `git diff --check` passed.
- No unresolved genuine regressions or unrelated pre-existing failing tests were found. Intermediate obsolete assertions/fixtures were updated to the requested policy while retaining security coverage. A syntax error introduced during controller cleanup was corrected, linted, and included in the clean final run.
- Live SMTP/SMS delivery and opt-in MySQL testing were not performed. Provider behavior was verified through the existing fakes, concurrency fixtures and isolated SQLite/database-cache tests.

## Exact final PHP command

Run from `C:/bethlehem-clinic-frontend`:

```powershell
$clinicPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
$authPhpTests = @(
    'tests/Unit/ProgressiveAuthThrottleTest.php'
    'tests/Unit/AuthenticationEmailPolicyTest.php'
    'tests/Unit/SmsSendBudgetTest.php'
    'tests/Feature/AuthenticationRouteThrottleTest.php'
    'tests/Feature/EmailVerificationTest.php'
    'tests/Feature/PasswordResetTest.php'
    'tests/Feature/AdminCredentialChangeTest.php'
    'tests/Feature/CustomerPasswordChangeTest.php'
    'tests/Feature/PhoneVerificationTest.php'
    'tests/Feature/VerifyFirstRegistrationTest.php'
    'tests/Feature/IndependentAuthenticationSessionsTest.php'
    'tests/Feature/SessionLockTest.php'
    'tests/Feature/AccountSecurityActivityTest.php'
    'tests/Feature/AccountPasswordPolicyTest.php'
    'tests/Feature/GroomingCompletionSmsTest.php'
    'tests/Feature/PasswordResetInterfaceTest.php'
    'tests/Feature/AdminSecuritySettingsInterfaceTest.php'
    'tests/Feature/PrivilegedCredentialSecurityTest.php'
)
& $clinicPhp vendor/bin/phpunit @authPhpTests --log-junit storage/logs/auth-cooldowns-final.xml
```

The final console output is in `storage/logs/auth-cooldowns-final.txt`; PHPUnit used the repository's forced testing environment, in-memory SQLite, fake mail/provider services and isolated cache settings. Earlier focused runs selected subsets of these same files, including progressive helper/email tests, password/email reproductions, database-cache OTP verification, expiry/cancellation/resend scenarios and route configuration.

## Exact frontend commands

```powershell
node tests/frontend-auth-session-regression.cjs
node tests/admin-security-settings-regression.cjs
node tests/staff-email-recovery-regression.cjs
node tests/client-settings-loading-regression.cjs
node tests/phone-auth-cooldown-regression.cjs
node tests/customer-data-cache-regression.cjs
node tests/email-delivery-regression.cjs
node tests/manager-security-regression.cjs
node tests/customer-signup-verification-regression.cjs
```

JavaScript syntax checks used `node --check` on `scripts/auth/forgot-password.js`, `scripts/components/admin-settings.js`, `scripts/components/customer-phone-verification.js` and `scripts/components/customer-settings-confirmation.js`. PHP syntax checks used the PHP 8.4 binary above with `-l` for every changed/new PHP file.

## Exact files changed or added

- [.env.example](C:/bethlehem-clinic-frontend/.env.example)
- [app/Http/Controllers/AdminSecurityController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/AdminSecurityController.php)
- [app/Http/Controllers/AuthController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/AuthController.php)
- [app/Http/Controllers/CustomerSecurityChangeController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/CustomerSecurityChangeController.php)
- [app/Http/Controllers/PasswordResetController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/PasswordResetController.php)
- [app/Http/Controllers/StaffEmailChangeController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/StaffEmailChangeController.php)
- [app/Http/Controllers/StaffEmailRecoveryController.php](C:/bethlehem-clinic-frontend/app/Http/Controllers/StaffEmailRecoveryController.php)
- [app/Jobs/Middleware/TrackAuthenticationMailDelivery.php](C:/bethlehem-clinic-frontend/app/Jobs/Middleware/TrackAuthenticationMailDelivery.php)
- [app/Providers/AppServiceProvider.php](C:/bethlehem-clinic-frontend/app/Providers/AppServiceProvider.php)
- [app/Services/AuthenticationEmailPolicy.php](C:/bethlehem-clinic-frontend/app/Services/AuthenticationEmailPolicy.php)
- [app/Services/CustomerPhoneVerificationService.php](C:/bethlehem-clinic-frontend/app/Services/CustomerPhoneVerificationService.php)
- [app/Services/CustomerSecurityChangeService.php](C:/bethlehem-clinic-frontend/app/Services/CustomerSecurityChangeService.php)
- [app/Services/PasswordResetService.php](C:/bethlehem-clinic-frontend/app/Services/PasswordResetService.php)
- [app/Services/PrivilegedCredentialChangeService.php](C:/bethlehem-clinic-frontend/app/Services/PrivilegedCredentialChangeService.php)
- [app/Services/SmsSendBudget.php](C:/bethlehem-clinic-frontend/app/Services/SmsSendBudget.php)
- [app/Services/StaffEmailChangeService.php](C:/bethlehem-clinic-frontend/app/Services/StaffEmailChangeService.php)
- [app/Services/StaffEmailRecoveryService.php](C:/bethlehem-clinic-frontend/app/Services/StaffEmailRecoveryService.php)
- [app/Support/ProgressiveAuthThrottle.php](C:/bethlehem-clinic-frontend/app/Support/ProgressiveAuthThrottle.php)
- [app/Support/SecurityCode.php](C:/bethlehem-clinic-frontend/app/Support/SecurityCode.php)
- [config/authentication.php](C:/bethlehem-clinic-frontend/config/authentication.php)
- [config/services.php](C:/bethlehem-clinic-frontend/config/services.php)
- [docs/auth-cooldowns-verification-2026-11-19.md](C:/bethlehem-clinic-frontend/docs/auth-cooldowns-verification-2026-11-19.md)
- [docs/superpowers/plans/2026-11-19-auth-cooldowns.md](C:/bethlehem-clinic-frontend/docs/superpowers/plans/2026-11-19-auth-cooldowns.md)
- [pages/admin/settings.html](C:/bethlehem-clinic-frontend/pages/admin/settings.html)
- [routes/api.php](C:/bethlehem-clinic-frontend/routes/api.php)
- [scripts/auth/forgot-password.js](C:/bethlehem-clinic-frontend/scripts/auth/forgot-password.js)
- [scripts/components/admin-settings.js](C:/bethlehem-clinic-frontend/scripts/components/admin-settings.js)
- [scripts/components/customer-phone-verification.js](C:/bethlehem-clinic-frontend/scripts/components/customer-phone-verification.js)
- [scripts/components/customer-settings-confirmation.js](C:/bethlehem-clinic-frontend/scripts/components/customer-settings-confirmation.js)
- [tests/Feature/AdminCredentialChangeTest.php](C:/bethlehem-clinic-frontend/tests/Feature/AdminCredentialChangeTest.php)
- [tests/Feature/AuthenticationRouteThrottleTest.php](C:/bethlehem-clinic-frontend/tests/Feature/AuthenticationRouteThrottleTest.php)
- [tests/Feature/CustomerPasswordChangeTest.php](C:/bethlehem-clinic-frontend/tests/Feature/CustomerPasswordChangeTest.php)
- [tests/Feature/EmailVerificationTest.php](C:/bethlehem-clinic-frontend/tests/Feature/EmailVerificationTest.php)
- [tests/Feature/GroomingCompletionSmsTest.php](C:/bethlehem-clinic-frontend/tests/Feature/GroomingCompletionSmsTest.php)
- [tests/Feature/IndependentAuthenticationSessionsTest.php](C:/bethlehem-clinic-frontend/tests/Feature/IndependentAuthenticationSessionsTest.php)
- [tests/Feature/PasswordResetTest.php](C:/bethlehem-clinic-frontend/tests/Feature/PasswordResetTest.php)
- [tests/Feature/PhoneVerificationTest.php](C:/bethlehem-clinic-frontend/tests/Feature/PhoneVerificationTest.php)
- [tests/Feature/VerifyFirstRegistrationTest.php](C:/bethlehem-clinic-frontend/tests/Feature/VerifyFirstRegistrationTest.php)
- [tests/Unit/AuthenticationEmailPolicyTest.php](C:/bethlehem-clinic-frontend/tests/Unit/AuthenticationEmailPolicyTest.php)
- [tests/Unit/ProgressiveAuthThrottleTest.php](C:/bethlehem-clinic-frontend/tests/Unit/ProgressiveAuthThrottleTest.php)
- [tests/Unit/SmsSendBudgetTest.php](C:/bethlehem-clinic-frontend/tests/Unit/SmsSendBudgetTest.php)
- [tests/admin-security-settings-regression.cjs](C:/bethlehem-clinic-frontend/tests/admin-security-settings-regression.cjs)
- [tests/client-settings-loading-regression.cjs](C:/bethlehem-clinic-frontend/tests/client-settings-loading-regression.cjs)
- [tests/frontend-auth-session-regression.cjs](C:/bethlehem-clinic-frontend/tests/frontend-auth-session-regression.cjs)
- [tests/phone-auth-cooldown-regression.cjs](C:/bethlehem-clinic-frontend/tests/phone-auth-cooldown-regression.cjs)
