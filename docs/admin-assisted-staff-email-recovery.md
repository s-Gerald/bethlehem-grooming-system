# Phase 3 — admin-assisted staff email recovery

Implemented on `gerald-frontend`, 2026-10-25. The additive migration was applied only to the confirmed local development MySQL database; the account count remained unchanged. No commit, push, Hostinger deployment, or production resource change was made.

## Implemented

Admin Settings → Security → Staff Account Details now offers **Assist Email Change** beside the email of eligible active staff. Pending setup keeps Phase 1's **Change Email**. Deactivated/archived accounts cannot start recovery. Existing filters and the underlying active/deactivated state retain their meaning.

The same details modal transitions through the recovery form and **Verify Recovery Request**, then returns to details after approval. Approved requests show **Recovery pending** or **Recovery expired**, their proposed address, and **View Recovery / Cancel Recovery**. Management requires an admin password to resend/renew the link or cancel an approved recovery. There is no new admin page, navigation entry, list-row action, stacked modal, or decorative status card.

The sole new staff-facing page is `pages/client/confirm-email-recovery.html`. It accepts a fragment token, removes it from the address bar before its first verification request, displays the proposed address read-only, and requires the existing account password. Success shows **Email Updated**, the sign-in instruction, and **Back to Sign In**. It creates no authenticated session and never forwards a different browser account's bearer token. A page restored from browser history requires reopening the original email link.

Backend additions are `StaffEmailRecoveryService`, its controller/model, three encrypted notifications, and the additive recovery migration. `AccountEmailChangeService` is the shared account update/revocation implementation used by both Phases 2 and 3. Session freshness checks now have one implementation in `AccountSessionService` for both email workflows.

```text
POST /api/admin/security/staff/{staff}/email-recovery
POST /api/admin/security/staff/{staff}/email-recovery/{recovery}/confirm
POST /api/admin/security/staff/{staff}/email-recovery/{recovery}/code
POST /api/admin/security/staff/{staff}/email-recovery/{recovery}/resend
POST /api/admin/security/staff/{staff}/email-recovery/{recovery}/cancel
GET  /api/admin/security/staff/{staff}/email-recovery/history
POST /api/staff/email-recovery/verify
POST /api/staff/email-recovery/complete
```

The first six routes require an authenticated, usable administrator session. The two public routes require the unguessable recovery credential; completion additionally requires the staff password. Public proof endpoints do not grant admin privileges or accept a client-supplied target user ID.

## UI/UX and references

The contextual member-management action and new-address verification draw on [Slack's administrator email-management pattern](https://slack.com/help/articles/225531168-Change-a-members-email-address). Explicit suspension/revocation and subsequent fresh sign-in draw on [Microsoft Entra's session-revocation pattern](https://learn.microsoft.com/en-us/entra/identity/authentication/howto-mfa-userdevicesettings). Bethlehem adds its own required independent identity check, administrator password/code approval, and staff password proof; it does not copy a direct verified-email overwrite.

The controls extend the current blue `#315b7e` actions, white/slate surfaces, rounded fields/modal, Phosphor icons, OTP inputs and success toast. Password visibility, paste/backspace/arrows, accessible labels/errors, focus trapping/restoration, duplicate-submit guards, scrollable mobile content, and wrapping/stacking footers follow existing patterns. A one-shot local timeout updates the selected request's expired label without polling. Passwords clear after submitted requests and cancellation; nonsensitive form values survive validation failures.

Only the existing admin Settings session was inspected in the ChatGPT browser. No tab/account switch, navigation, reload, data entry, OTP submission or password-reset interaction was performed. The new modal views and recovery page have not been visually exercised in the live browser, including at mobile widths. Source layout checks and automated interaction regressions supplement, but do not replace, that acceptance work.

## Security and operating procedure

**Independent identity verification is required before practical use.** No existing documented independent clinic procedure was found. The clinic should adopt this minimum procedure before using the feature:

1. An authorized administrator meets the staff member in person and confirms that they are the employee associated with the existing account, using trusted employment records and the clinic's established recognition/verification process. Resolve uncertainty with the designated clinic manager using an already established contact channel.
2. Confirm the account and intended replacement email with that verified employee. Do not treat an email from the proposed address, access to the old mailbox, the administrator OTP, personal trivia or the checkbox itself as identity proof.
3. Select the reason and attest only after that check. The fixed recorded method is `in_person_employment_record`; the record identifies the attesting administrator and time. No identity document upload, biometric data or security questions are collected.

If the staff member also forgot the existing password, this workflow cannot complete. Contact the clinic administrator for a separately authorized higher-risk recovery procedure. Do not use the potentially compromised old mailbox or falsely attest safe access merely to bypass the hold.

This is an application-specific recovery procedure, informed by [NIST's treatment of documented agent-assisted recovery and lifecycle records](https://pages.nist.gov/800-63-4/sp800-63b/events/). It does not claim NIST IAL/AAL certification. The administrator email OTP is an additional check, not independent phishing-resistant MFA.

- Initiation locks/rereads the administrator, target and real initiating token. It requires a current admin password, supported active staff role, identity attestation/method, normalized valid replacement email, and availability. It leaves the registered email and staff access unchanged until admin code approval.
- The purpose-specific six-digit admin code is hashed and tied to the administrator, initiating session, target, proposed email, reason, attestation and request snapshots. Confirmation rechecks current administrator role/status, password/email fingerprint, session ownership/replacement/expiry/inactivity lock, and target eligibility/password/role/email snapshot. Codes expire at the original deadline, have five attempts and a 60-second resend cooldown, and are single-use. Resend/cancel/replacement cannot reset the retained challenge budget before its original expiry.
- Only correct administrator approval establishes the durable recovery hold and queues the staff link. It revokes that target's existing tokens, login challenges, password-reset grants, unconfirmed credential changes and self-service email changes. Superseded queued verification messages cannot deliver valid credentials. Other accounts' sessions and operational records are unaffected.
- The hold is represented by `users.email_recovery_id`, separate from `is_active`. Login, challenge issuance/resend/confirmation, session creation, protected APIs, password recovery and self-service email changes enforce it. Ordinary requests read the already-loaded account attribute; they do not query the recovery table.
- Staff links use 256 random bits, SHA-256 hashes in recovery records, a 30-minute deadline, fragment URLs, no-referrer markup, no persistent token storage and no third-party resources on the landing page. Raw credentials exist only transiently in memory and inside encrypted durable notification jobs necessary for delivery. They are not returned in admin payloads, ordinary logs or audit events.
- Final completion locks accounts in a stable order, then rechecks the token, time, attempt budget, target snapshot, original administrator authorization/current eligibility, existing staff password and email uniqueness. It updates the same user's email/verification timestamp, consumes the link, completes the durable record, revokes remaining conflicting credentials and clears the hold atomically. User ID, username, password hash, name, role and linked records retain their identities.
- Mail insertion failure rolls back the associated request/approval/rotation. Database queue guards suppress superseded or expired codes/links; queued mail is not an inbox-delivery guarantee. Informational notice failure after commit does not reverse the identity change. Security notices go to the previously registered email without a recovery credential or the full proposed address and explain how to contact the clinic. No additional previously established independent notification destination exists in this workflow.

Reauthentication, pending-address verification and notices follow the relevant principles in [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [Email Validation and Verification](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html), and [Forgot Password](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html). Normal self-service still requires both mailboxes. Assisted recovery is a separately authorized procedure for losing the old mailbox, not an exception inserted into Phase 2's verification method.

## Expiration, cancellation and security review

An expired link **never releases the hold**. Resend/renew requires a usable admin session and current password, checks the original authorization/target snapshot and availability, rotates the token, and preserves staff password failures. Five incorrect staff passwords lock that request even across resend. A changed password/role/email or invalidated authorizing administrator makes the old request unusable and leaves access suspended for explicit resolution; resending cannot approve a changed snapshot.

Cancelling an approved recovery requires current admin password verification and an explicit statement that access through the current email is safe to restore. It consumes the link and clears only the recovery hold; deactivation/archival still prevents access, and no session is created. If safe access cannot be established, retain the hold and escalate through the clinic's independent procedure. Do not use cancellation as an insecure password-recovery shortcut.

An unapproved challenge can be dismissed by its initiating admin session without creating or lifting a hold. If an approval response was lost, dismissing that stale code view restores authoritative pending state; it cannot cancel an already approved recovery without password/safety confirmation.

Recovery records retain original/proposed addresses, reason, attesting admin/method/time, authorization and resolution timestamps. `staff_email_recovery_events` records initiation, rejected admin codes, supersession, approval, link issuance/resend, rejected staff passwords, safe-access confirmation, cancellation, completion and expiration with the appropriate actor. No raw passwords or OTP/link secrets enter the audit trail. Resolved history remains in the database and is available only through the administrator history endpoint (latest 30 requests, events fetched in one query).

Expiration is enforced immediately from its stored deadline. Its audit event is recorded when an expired request is used/resolved, or by the existing Laravel scheduler every five minutes, without lifting access restrictions. The event time represents observation; the record retains the exact expiry deadline. No production history cleanup or destructive retention policy was added.

## Phase 1/2 compatibility and cleanup

Pending setup correction, staff setup/resend/cancellation, active staff self-service, both mailbox codes, profile/password changes, role management, deactivation/reactivation, login OTP, reset flows, session locking/replacement and cross-tab logout remain covered by the affected regression suites. Reactivating a staff member does not lift their recovery hold or claim that recovery access has been restored.

Removed the duplicate final email-update/revocation block and its unused imports/private session checker from `StaffEmailChangeService`. Both real consumers now use shared mutation/session validation. Existing OTP input/countdown handlers serve the new modal without a second OTP implementation. Existing fixture, interface, modal, cache and auth tests were updated; the new page alone has a distinct frontend regression file. The genuine two-process SQLite/MySQL lock-probe harness is shared between self-service and recovery cases. Still-valid coverage was retained.

## Performance and caching

The staff list eager-loads recovery pointers in a bounded query rather than requesting each row. Recovery actions reuse current details and merge the returned account locally, invalidate stale account reads by revision, and perform no page reload or full list refresh. Operational caches/fetching for dashboard, clinic, grooming, inventory, POS and transactions remain unaffected. No frontend polling or new package was added. Mail and expiration audit work use existing queue/scheduler infrastructure. Core Web Vitals were not measured for this feature.

## Executed verification

Using PHP 8.4.26 from the project's test tools, the complete affected regression run passed **334 tests, 4,841 assertions**:

```powershell
$taskPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $taskPhp artisan test --compact tests/Feature/AdminCredentialChangeTest.php tests/Feature/AdminStaffAccountManagementTest.php tests/Feature/PrivilegedCredentialSecurityTest.php tests/Feature/AdminSecuritySettingsInterfaceTest.php tests/Feature/StaffSettingsInterfaceTest.php tests/Feature/EmailVerificationTest.php tests/Feature/PasswordResetTest.php tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/SessionLockTest.php
```

The final native MySQL integration run passed **30 tests, 489 assertions**, using only randomly named disposable local databases with host/name guards and automatic cleanup:

```powershell
& $taskPhp tests/run-staff-email-mysql.php
```

It includes three-role completion, migration compatibility, durable mail behavior, prior self-service races and twelve real overlapping recovery cases: start/start, approval/approval, completion/completion, resend/completion, completion/resend, competing account email claim, role/completion in both orders, approval/password-reset in both orders, and approval/self-service completion in both orders. Each concurrency case proves a second process encountered an actual writer/row lock, then checks the serialized outcome.

After those runs, the queue test was strengthened to check initial issuance rollback and delivery of security notices on a configured authentication queue. Focused reruns passed on both SQLite and native MySQL: **1 test, 21 assertions each** (overlapping coverage, not additional independent tests):

```powershell
& $taskPhp artisan test --compact tests/Feature/AdminCredentialChangeTest.php --filter=recovery_durable_queue
& $taskPhp tests/run-staff-email-mysql.php recovery_durable_queue
```

The first expanded MySQL attempt exceeded the runner's former 120-second limit. The focused default integration set now has a bounded 300-second limit and completed successfully. During development, failing regressions reproduced missing routes, cancellation-budget reset, stale mailbox routing and incorrect OTP backspace binding; all were corrected. Existing password-control counts and dated script-key expectations were updated for the added UI.

Frontend verification commands:

```text
node tests/admin-security-settings-regression.cjs
node tests/staff-email-recovery-regression.cjs
node tests/frontend-auth-session-regression.cjs
node tests/email-delivery-regression.cjs
node tests/admin-data-cache-regression.cjs
node tests/admin-sidebar-cache-regression.cjs
node tests/session-inactivity-regression.cjs
```

All seven scripts passed. They cover modal transitions, validation/identity attestation, OTP binding/paste/backspace, duplicate submissions, focus during submission/resend, password clearing, pending/expired states, resend/cancellation, one-shot expiry presentation, token scrubbing, link-page success/errors/history, unrelated browser-session preservation and operational cache isolation.

The final locked-row query reuse was checked with 13 focused SQLite cases (169 assertions) and all three staff-role success cases on native MySQL (93 assertions), using the existing filters rather than repeating unrelated tests. These overlap the broader runs above.

`npm run build` passed, generating the standalone CSS and normal Vite build artifacts. It retains the existing outdated Browserslist dataset warning; no dependency update was introduced. PHP syntax checks passed for all 24 changed PHP files, changed JavaScript compiled, new HTML IDs/local script references were checked, and `git diff --check` passed. Isolated config/route cache checks passed, and their temporary files were removed. The local additive migration succeeded and preserved the account count. The full repository PHP/frontend suites and live mailbox flows were not run.

## Hostinger readiness and deployment order

1. Back up the production database and follow the established approved release process. Include normal Composer autoloadable classes, both route files, raw static Settings/API/component files, the new page/script, `css/output.css` and generated Vite assets. Vite does not package these standalone HTML/JS files; deploy them as well. Settings preload/script/CSS references and the recovery page carry dated asset versions.
2. **Do not assume Phase 2's migration exists on Hostinger.** After deploying compatible files, run migrations in timestamp order through the approved process, typically `php artisan migrate --force`. The required dependency order is `2026_10_25_000002_create_staff_email_changes.php` followed by `2026_10_25_000003_create_staff_email_recoveries.php`, after earlier pending-setup/session migrations. The latter adds a nullable indexed user recovery pointer and two separate history tables; it does not rebuild/reseed account data. Do not roll back this migration in production as a substitute for operational recovery; rollback discards recovery history and holds.
3. Preserve existing production `APP_KEY`, database credentials, SMTP settings and durable database queue on the issuance database. No new environment variable is required. Ensure workers/cron consume the existing authentication queue, including `AUTH_MAIL_QUEUE` when configured. Restart long-lived workers after deployment so they load the new encrypted notification classes. Keep the existing Laravel scheduler running for expiration audit recording.
4. Preserve the application's configured HTTPS `FRONTEND_URL`/`APP_URL`, frontend API origin and production session/cookie security settings. Recovery links use that configured frontend URL, support HTTPS and deployment subpaths, and contain no localhost-specific logic. Keep the existing HTTPS/Secure/HttpOnly/SameSite cookie policy; this feature does not relax it. Public recovery proof is not cookie-based login.
5. Rebuild route/config caches through the normal release process. Compatibility was verified with temporary cache files: eight recovery routes and four existing self-service routes. The running app's normal cache files were not overwritten, and temporary verification files were removed.
6. Reload local Settings to load updated static assets before manual acceptance. No Hostinger migration, deployment, worker restart or production acceptance was performed by the agent.

## Manual acceptance still required

The user retains live browser navigation, account/data entry, OTP submissions, password-reset interaction and real-mailbox testing. Verify the new modal and recovery page at desktop/mobile widths, keyboard/focus behavior, actual SMTP delivery/cooldowns, old-mailbox notices, expiry/resend/cancellation, final success, and fresh login with the new email or unchanged username. Verify a forgotten-password scenario is referred to the clinic rather than bypassed, and that safe-access attestation is used honestly. Confirm Phase 1/2 live flows and deployed Hostinger queue/scheduler/HTTPS behavior through the approved release process.

Automated checks support implementation readiness; live visual, mailbox and production acceptance remain unverified.
