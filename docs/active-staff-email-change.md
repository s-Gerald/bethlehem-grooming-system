# Phase 2 — active staff self-service email change

Implemented on `gerald-frontend`, 2026-10-25. No commit, push, Hostinger deployment or production database change was made. The additive migration **was applied to the confirmed local development MySQL database** so the running app can use the feature after reloading Settings.

## Implemented

Active staff can select Change Email beside the registered address in Settings → Security → Your Account. Edit Profile remains separate. One modal moves through the new-email/password form, current-email OTP, new-email OTP, and Email Updated state. The registered address changes only after both mailbox confirmations.

The backend adds StaffEmailChangeService, a focused controller, a pending-state model, two notifications, shared AccountEmail/SecurityCode helpers, and one new table. Routes are staff-only, authenticated and throttled:

```text
POST   /api/settings/security/email-change
POST   /api/settings/security/email-change/{change}/confirm
POST   /api/settings/security/email-change/{change}/resend
DELETE /api/settings/security/email-change/{change}
```

Pending state is included in the existing own-account response. Closing the modal preserves a request; Resume rereads that scoped account state. Cancel invalidates its code and returns to account details. An expired request permits a fresh request. No background polling or staff-list request was added.

## UI/UX

The interaction follows [Slack's contextual email action and password confirmation](https://slack.com/help/articles/207262907-Change-your-email-address), and [Atlassian's preservation of account identity through verified email changes](https://support.atlassian.com/atlassian-cloud/kb/change-atlassian-account-email-addresses/). The additional two-mailbox safeguards follow the requested [OWASP authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) and [email verification guidance](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html); login email OTP is not treated as independent MFA.

The modal retains Bethlehem's blue primary/header treatment, white background, slate labels, rounded controls, Phosphor icons and OTP presentation. It has no extra page, settings tab, large stepper or stacked modal. There is one short form explanation; current-email verification has the requested brief administrator-assistance message. No recovery bypass was added.

Inputs have accessible labels/errors, keyboard focus, password visibility, paste/backspace/arrow-key OTP behavior and submission guards. The footer stacks on narrow screens and OTP columns shrink within the modal. Focus stays inside the modal during loading and returns to Resume or Change Email after closing/cancellation. Success shows only Email Updated, the sign-in explanation and Back to Sign In.

The existing grooming receptionist Settings page was inspected in the ChatGPT browser. No other account/tab, page navigation, form input, OTP or password reset was performed. The newly implemented modal has not been visually exercised in that live session or at mobile widths; those acceptance checks remain with the user.

## Security

- The service rereads/locks the account and real session token, then the pending request. It checks active/nonarchived/nondeleted staff eligibility, initial setup state, session ownership, replacement/expiry/lock state and the current password. Admin/customer access and unrelated target fields are rejected.
- Each request is tied to the initiating token and a fingerprint of the password and role/subrole. Replaced sessions, changed passwords/roles and a changed registered email cannot complete stale requests.
- Six-digit random codes use framework password hashing with stage separation. Current-email confirmation rotates to a distinct new-email code. Confirm/resend includes the expected stage, so duplicate old-stage submissions cannot advance the new stage.
- Existing security-code conventions supply five incorrect attempts, a 60-second resend cooldown and the configured code TTL (10 minutes by default). The total request expires after two code TTLs. Code expiry is capped by that absolute deadline. Attempts and cooldown survive cancellation/replacement until the original deadline; resending does not reset them. Email messages show their actual expiration time in Philippine Time.
- Shared normalization, syntax/150-character rules and indexed email availability checks cover users and retained pending customer registrations. The database unique constraint is authoritative at final save. A proposed address is never placed in `users.email` before completion, nor reserved as a second account identity.
- Code issuance and encrypted durable queue insertion share a transaction. Initial issuance, stage-transition and resend insertion failures roll back to the prior valid state. Cancelled/superseded jobs are suppressed through existing delivery guards and pending/session eligibility checks. No raw OTP/hash/session-binding fields are returned in pending payloads.
- Completion atomically updates the same user's email/verified timestamp and revokes all their tokens. Outstanding login challenges, recovery grants and unconfirmed credential changes for that account are removed. Other users and operational records are untouched. Future login/recovery use the new address; the username/password remain intact.
- The old mailbox receives an informational notice after commit. A failure to enqueue that notice is logged without credentials and does not undo completion.

The new service owns its retryable account → token → pending-state transaction. The session middleware still validates these routes first, then lets the service recheck the session at mutation time; this prevents HTTP exception rendering from swallowing transaction retries. Other route transaction behavior is unchanged. Password reset now locks the account before its recovery grant to match identity changes. Real overlapping-process tests and an injected-deadlock retry test cover these boundaries.

Active legacy staff without an existing verified timestamp still verify both addresses; completion establishes the corrected verified-email state.

The frontend clears only the completing session through existing auth clearing/logout synchronization. It shows success before navigation, does not create a session, and rejects delayed completion for a newer browser login. Account request revisions prevent old identity responses from restoring the previous email after sign-out. A token-scoped completion guard lets a final response settle before a background 401 can redirect: successful revocation shows Email Updated, while a failed operation still allows genuine authentication failure to sign out normally. No new polling or request-timeout policy was introduced.

## Phase 1 compatibility

Pending staff creation/correction, resend, cancellation, activation, expired links and account identity remain covered by the existing AdminStaffAccountManagementTest and settings regressions. Phase 1 now delegates normalization/rules/availability to AccountEmail with the same behavior. Its endpoint, setup tokens and admin dialog remain separate from active self-service verification.

## Phase 3 integration

Phase 3 now adds a separate administrator authorization/recovery path, documented in [Admin-assisted staff email recovery](admin-assisted-staff-email-recovery.md). It reuses AccountEmail, SecurityCode, AuthenticationMailDelivery and the OTP handlers. Both workflows use AccountEmailChangeService for final account mutation/revocation and AccountSessionService for session freshness. A specifically authorized recovery hold supersedes that user's self-service request; it does not bypass either mailbox check within this Phase 2 workflow. The results below document the original Phase 2 verification; current combined verification appears in the Phase 3 report.

## Tests

Using the README's PHP 8.4.26 executable, the affected suite passed **299 tests, 4,385 assertions**:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' artisan test --compact tests/Feature/AdminCredentialChangeTest.php tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php tests/Feature/EmailVerificationTest.php tests/Feature/PasswordResetTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/SessionLockTest.php
```

The final broad run includes the follow-up pending-account, new-code/request expiry, secret-free payload, legacy active-account and unrelated-session preservation checks. Earlier focused runs overlap this suite and are not additional independent tests. The full repository PHPUnit suite was not run.

The same three-role success paths, future login/recovery, migration and eight concurrency cases ran on a disposable localhost MySQL database: **13 tests, 199 assertions passed**. The runner checks local configuration and database naming, refuses web execution, and removes its test database/configuration afterward:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' tests/run-staff-email-mysql.php
```

The concurrency cases prove overlapping SQLite writer locks and native MySQL row locks for request/request, current/current confirmation, final/final confirmation, resend/final, final/resend, final/password-reset, password-reset/final, and competing account email claims. At most one claimant wins; no unrelated session or duplicate account is created.

Real database queue tests cover initial insertion failure, current-to-new transition failure, failed resend rollback, encrypted jobs, exact current/new recipients, cancelled/superseded suppression and failure of the post-change notice. Other cases cover ownership, incorrect password/email/code, pending/deactivated/archived accounts, expiry, cancellation/restoration, attempt/cooldown retention, sensitive-payload exclusion and future mail routing.

All six relevant frontend scripts passed:

```text
node tests/admin-security-settings-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/email-delivery-regression.cjs
node tests/admin-data-cache-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/session-inactivity-regression.cjs
```

`npm run build`, JavaScript syntax checks, `git diff --check`, migration up/down without user mutation, and isolated route/config cache checks passed. Build output retains the existing outdated Browserslist dataset warning; no dependency update was introduced.

Issues found during implementation were resolved: string session-activity timestamps now use the middleware's Carbon parsing convention; required migration dates use dateTime to support strict MySQL timestamp modes; the service owns its retryable transaction; two exact-second resend tests freeze their clocks instead of relaxing expected values. No executed check remains failing.

## Cleanup

Removed the email rules/availability implementation made redundant by AccountEmail and the controller's unused Str import. Existing OTP generation/limits/TTL now delegate to SecurityCode, and existing OTP input/countdown handlers accept the second real consumer instead of maintaining a competing input system. Existing tests/fixtures were extended in place; applicable interface tests remained valid. The optional MySQL runner reuses the same feature scenarios.

## Performance

The initial Security account request adds one indexed lookup for this staff member's pending state. Resume uses one explicit scoped refresh. Verification/cancellation use focused operations, with no full staff list, page reload, unrelated feature request or new polling. Pending-stage operations leave operational caches intact. Final sign-out uses the existing security cleanup of session-owned presentation/identity caches; it does not broadcast operational data invalidations or alter operational records. No Core Web Vitals measurement was made for this modal feature.

## Hostinger readiness and release

1. Include the new controller/model/service, AccountEmail/SecurityCode helpers, both notifications, the migration, all changed backend files, `routes/api.php`, raw `pages/admin/settings.html`, `scripts/api.js`, `scripts/components/admin-settings.js`, compiled `css/output.css`, and normal Vite build outputs. Static pages/scripts are not bundled by Vite. The page's API preload/script, component and CSS references include the new asset version.
2. **A production migration is required**, through the established approved release process:
   `php artisan migrate --force --path=database/migrations/2026_10_25_000002_create_staff_email_changes.php`
   It creates only `staff_email_changes`; no account table rebuild, reseed, destructive data reset or new environment variable is needed. Use the existing compatible PHP/Composer runtime and MySQL configuration. The local migration has already succeeded; it was not run against Hostinger.
3. Keep the existing durable database authentication queue on the issuance database and its worker/cron. No new provider/queue-processing mechanism is needed. Preserve production mail credentials, HTTPS URLs and cookie settings. Restart long-lived workers through the normal release process so they load the new notification/model classes.
4. Rebuild route cache when enabled so the four named routes are available; rebuild config cache as required by the release process. Compatibility was tested with disposable files; the running app's caches were not replaced. No production URL or localhost assumption was added to the feature.
5. Reload the current local Settings page once to load the new static assets. Manually verify the form, current/new OTPs, resend/cancel/resume, mobile layout/keyboard behavior, final success/sign-out and re-login using the new email or unchanged username. Verify actual mailbox/SMTP delivery and the deployed Hostinger worker configuration before production acceptance.

Automated behavior is verified on SQLite and local MySQL. Live browser/real inbox acceptance and the actual Hostinger environment remain unverified, as reserved for the user. This report does not claim complete production/manual verification.
