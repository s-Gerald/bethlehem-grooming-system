# Phase 1: pending staff email correction

Implemented locally on 2026-10-25. No commit, push, deployment, production account change, migration, dependency, or environment-variable addition was made.

## Implemented

An administrator can correct an existing pending veterinarian, clinic receptionist, or grooming receptionist through Staff Account Details. `Change Email` sits beside the displayed email. The same dialog switches to `Change Staff Email`, with the staff name, current email, empty replacement input, admin password visibility control, one explanatory sentence, Cancel, and Update & Send Setup Email.

Cancel returns to details and restores focus/scroll; Escape, backdrop, and close dismiss safely. An in-flight correction prevents dismissal and duplicate submissions. Field errors have labels, alert semantics, `aria-invalid`, and `aria-describedby`. Invalid, incomplete, unchanged, and known-invalid submissions are disabled. Recoverable failures retain the email and clear the password. Success updates the list record and open details locally, retains Pending setup, and produces one toast saying the email was **queued**.

The implementation extends Bethlehem's existing blue/slate colors, white rounded dialog, inputs, buttons, typography and Phosphor icons. The mobile footer stacks buttons and the content scrolls within the existing dialog height. No new page, navigation item, nested modal, or second confirmation was introduced.

The interaction adapts management of outstanding invitations from [GitHub's invitation guidance](https://docs.github.com/en/organizations/managing-membership-in-your-organization/inviting-users-to-join-your-organization) and [Slack's contextual pending-invitation actions](https://slack.com/help/articles/360060363633-Manage-pending-invitations-and-invite-links-for-your-workspace). Neither reference is presented as using this exact email-correction form.

## Backend and security

`PATCH /api/admin/security/staff/{staff}/setup-email` accepts only `email` and `current_password`, under existing authenticated admin middleware and request throttling. A transaction rereads and locks the administrator, checks current eligibility/password, locks the setup issuance before the staff account, and rechecks the target. Active, already-activated/deactivated, archived, deleted and nonstaff targets are rejected. A cancelled target is rechecked even if details were loaded earlier.

Creation and correction share email syntax/length rules and availability checks in PendingStaffAccountService. Addresses are trimmed/lowercased. User and retained pending-customer reservations are checked, the target is excluded, unchanged addresses are rejected, and database uniqueness conflicts become email-field errors. MySQL uses the configured case-insensitive email collation and indexed equality; SQLite uses explicit case-insensitive comparison. Correction locks reservation/user lookups during mutation.

PasswordResetService remains the single setup-link issuance path. Correction and resend rotate the secure 64-character token, store its SHA-256 hash, and retain the existing 24-hour expiration. Token rotation and encrypted database mail-job insertion now occur in the same transaction. Synchronous queue insertion failure rolls back the email and token. The existing queue guard suppresses superseded jobs. API responses expose account data, never setup tokens. Actual asynchronous inbox delivery is not claimed.

These controls apply [OWASP's reauthentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#require-re-authentication-for-sensitive-features) and [single-use, time-limited ownership verification guidance](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html#email-ownership-verification) to initial account setup. The existing username/password setup page establishes access to the corrected mailbox and verifies it on activation. No email-change OTP was added.

Account ID, names, username, password placeholder, role/subrole and unrelated metadata are preserved. Other users' sessions are not revoked. Resend uses the corrected mailbox, cancellation still works, and old/expired/used links are rejected.

## Future compatibility and cleanup

Shared validation and delivery remain in existing services for real current consumers. Phase 2 and Phase 3 routes, state, tables, controls and recovery flows were not added.

Removed resend's superseded post-commit token restoration branch: the transaction now supplies rollback. Extracted the existing staff payload mapping for both list loads and mutation responses, avoiding a second mapping implementation. Updated the existing frontend pending fixture to use the actual inactive pending state, and updated password-control expectations for the added field. Existing valid setup, role, OTP, recovery and session tests remain intact. StaffSettingsInterfaceTest required no changes and passed.

## Performance

Correction uses the returned account payload instead of reloading the staff list. The existing request epoch/deduplication mechanism remains; the mutation advances its revision so older list responses cannot overwrite the result. No polling, new dependency, unrelated feature fetch, dashboard/cache invalidation, or unrelated session invalidation was introduced. Cache regression tests exercise the actual API request and verify unrelated cached data survives. No before/after Core Web Vitals measurement was performed; this is a modal interaction change, not a page-load optimization.

## Tests executed

Using the README's PHP 8.4.26 interpreter:

```powershell
& 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe' artisan test --compact tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php tests/Feature/PasswordResetTest.php tests/Feature/EmailVerificationTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/SessionLockTest.php
```

Result: **244 tests passed, 3,672 assertions**. After adding and fixing the final stale-target cancellation case, the affected AdminStaffAccountManagementTest was rerun: **70 tests passed, 1,161 assertions**. These runs overlap; they are not 314 distinct tests. Initial missing-endpoint tests failed as expected before implementation. Fixture/runner issues and implementation regressions found during development were resolved; no failure remains in the executed focused checks. The full repository PHPUnit suite was not run.

Coverage includes all three roles, identity preservation, old-link invalidation, activation through the replacement link, validation and password failures, unauthorized/ineligible accounts, real unique-index conflict, expired invitations, corrected resend/cancel, stale state, encrypted durable queueing, superseded-job suppression and real queue-insertion rollback.

Five two-process cases explicitly prove an overlapping SQLite writer lock: correction/correction, correction/resend, resend/correction, correction/completion and completion/correction. They verify the resulting email, activation state, recipient and surviving token hash. These use disposable databases and do not prove Hostinger MySQL/InnoDB locking behavior.

Frontend scripts passed:

```text
node tests/admin-security-settings-regression.cjs
node tests/admin-data-cache-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/session-inactivity-regression.cjs
node tests/email-delivery-regression.cjs
```

The security regression was rerun after the final known-invalid submission guard and passed. It covers form validation, failure recovery, cancellation/focus, keyboard focus containment, loading/dismissal protection, duplicate submission, one success toast, local state updates and stale-list rejection. These are Node component/API regressions, not a browser-rendered mobile acceptance test.

`npm run build` passed, including OCR assets, generated CSS and Vite assets. It reported the existing outdated Browserslist dataset warning. `git diff --check` passed. Route caching and listing passed with a disposable cache file, which was removed; the running application's route/config caches were left untouched.

On this Windows sandbox, PHP's `is_readable()` returned false for existing files. PHPUnit ran outside that sandbox restriction with isolated test database/configuration settings; no test bootstrap or runtime requirements were weakened.

## Browser and remaining acceptance

The existing admin Settings/Security page was opened in the ChatGPT browser and visually inspected. It showed the established Bethlehem styling and zero pending accounts. No other role/tab was opened, no page navigation or data entry was performed, and no OTP or password reset was submitted.

The new pending-account dialog has **not** been visually exercised in the live browser, including at mobile widths. Manual navigation, actual account input, OTP/password reset, and inbox/link acceptance remain with the user as requested. Production MySQL concurrency and real mail transport/inbox delivery remain unverified. Automated success is not a claim of complete production/manual verification.

## Hostinger release steps

1. Use the existing reviewed release/upload process after approval. Include the modified controller, both services, `routes/api.php`, `pages/admin/settings.html`, `scripts/api.js`, `scripts/components/admin-settings.js`, and `css/output.css`. Vite does not bundle the raw static HTML/scripts: upload those source files too. Include generated `public/build/` when the normal release process ships Vite assets.
2. No new migration, table, column, dependency or environment setting is required. Preserve production data and existing configuration. Reuse the existing HTTPS `FRONTEND_URL`/application URL and deliverable mail settings; no localhost URL was added.
3. Retain the existing durable database authentication queue on the issuance database and its worker/cron process. AuthenticationMailDelivery already enforces this relationship. Verify the existing production email indexes/case-insensitive collation and MySQL concurrency during release acceptance.
4. Rebuild production route cache if enabled (`php artisan route:cache`) so the new PATCH route is available. Configuration caching remains compatible; rebuild it only as required by the established release process. Restart long-lived queue workers through the existing process (`php artisan queue:restart`) so they load the new code.
5. Reload Settings once after installing the new assets. Cache-version parameters were updated for the page's API script/preload, settings component and generated CSS. Corrections themselves do not require a page refresh.
6. Manually verify one pending account per role, validation/password rejection, cancel/close behavior, corrected setup mail, rejection of the previous link, successful setup, resend/cancel and mobile keyboard/scroll behavior. No deployment was performed in this task.
