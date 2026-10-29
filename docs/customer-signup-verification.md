# Customer signup, verification, and recovery

> Current October 29 magic-link, one-hour pending retention, OTP and deployment behavior is documented in [customer-authentication.md](customer-authentication.md). The notes below are retained as history; their signup OTP, 48-hour/configurable retention, 45-second resend and repeatable-confirmation descriptions are superseded. Do not apply the historical retention environment settings below.

Implemented against the latest local `gerald-frontend` branch on October 22, 2026.
The working directory is `C:/bethlehem-clinic-frontend`; its configured remotes
are `s-Gerald/bethlehem-clinic-frontend` and `rij-summit/bethlehem-grooming-system`.
No remote was changed and no deployment was performed.

## Root cause and investigation

Current registration already stores new attempts in `pending_customer_registrations`.
However, its unique validation blocked a second signup with the same email,
username, or phone, and its private edit credential could only be recovered from
the original browser tab. Public resend could issue a code, but verification
previously activated the stored signup immediately, without a separate review
step when the original edit credential had been lost.

Legacy users are a real possibility, not just a hypothetical pending-row problem.
The `AuthController` immediately before commit `7faf394` created a `users` row
before sending verification. Existing unverified users can therefore reserve an
email while `User::registeredCustomer()` excludes them from customer lists.
Current sign-in already blocked unverified customers, but normal password-reset
eligibility did not require a customer's verified email. Its generic response
could also appear successful for a nonexistent/pending account, without sending
anything. These distinct behaviors explain the reported combination.

The configured local database was unavailable, including a separate loopback
MySQL connection probe. No actual customer's email, row state, or history was
identified. Investigation established the source-code paths and reproduced both
pending and legacy states in isolated tests; it did not establish which state
held the reported address.

## Database lifecycle and all five email states

| State | Outcome |
| --- | --- |
| Neither table | Signup saves hashed credentials and details in pending only. No customer session. |
| Pending only | Repeat signup continues to generic email verification without changing stored details. Email ownership proof permits the registration panel, review, and completion. |
| Verified user only | Repeat signup uses the same public verification response as other existing emails. Resend sends no registration code; use sign-in or normal password recovery. |
| Unverified user only | Eligible legacy customers receive a registration code. Ownership proof permits review; explicit completion updates the existing user ID. |
| Both tables | The existing user takes precedence. Verified/disabled/deleted/noncustomer users cannot be activated through pending codes. An eligible legacy user's own code authorizes review; completion preserves its ID/history and removes the same-email pending row atomically. |

An inconsistent pending row beside an already verified/disabled account cannot
overwrite that account and expires through normal pending retention. There is
no blind account merge or bulk deletion of users. Legacy disabled, archived, or
deleted accounts remain unavailable; the clinic must resolve their status.
`unregistered_customers` and walk-in ownership/business logic remain separate.

`CustomerRegistrationService` centralizes completion and recovery authorization.
New customer creation, consumed-pending deletion, verification state, and first
session creation share one transaction. A failed session insert rolls all of it
back. Legacy completion updates names/identifiers/password only after authorized
review, preserves role/tier/IDs and linked pets/bookings, invalidates old sessions,
and resets phone verification if the phone changes. Existing database uniqueness
for email, username, and non-null phone remains intact.

Registration locks matching identifiers in both tables before validation.
Verification and completion lock their registrant row, recheck collisions, and
retry database deadlocks up to three times. Uniqueness constraints remain the
final protection against duplicate users.

## API and UI behavior

- `POST /api/register` creates a pending row or returns HTTP 202 with the generic
  `requires_verification` response. Public responses never claim recovery eligibility;
  only the successful OTP's existing `requires_registration_review` grant permits
  the conditional signup recovery panel. An email-only request cannot overwrite any
  existing details/password, retrieve details, activate a user, or create a session.
- Original-tab edits continue using the hashed private `registration_token` at
  `PUT /api/register/pending`. Edits rotate that credential, invalidate the old
  code, and preserve the current attempt budget/cooldown.
- `POST /api/email/resend` reuses the existing rate-limited mail issuance service.
  Public responses and delivery receipts remain generic; only the private edit
  credential permits inspecting pending-mail delivery.
- `POST /api/email/verify` with `{ email, code, registration_token }` completes an
  original pending signup when the credential still matches and no user collides.
- Without that matching edit credential, or for a legacy user, successful OTP
  verification consumes the code and returns `requires_registration_review`,
  minimal saved details, and a cryptographically random 64-character
  `registration_recovery_token`. Only its SHA-256 hash is stored. Responses are
  `no-store`. This is an authorization grant, never an authenticated session.
- The grant lasts ten minutes, is single-use, and cannot outlive pending retention.
  The existing signup form shows the owner-confirmed details, fixes the proven
  email, and requires a newly entered password and explicit completion. The same
  PUT endpoint accepts `registration_recovery_token`, revalidates uniqueness,
  consumes the grant, and completes registration. Invalid submissions retain the
  grant until expiry so the customer can correct their fields.
- No password or OTP is stored in browser storage. Details/edit/review grants use
  the existing tab-scoped session storage. Clearing that storage requires fresh
  email proof, rather than losing access permanently. Browsers must permit
  session storage for the cross-page review step.
- Signup preserves entered fields on errors and focuses server-invalid fields.
  Repeat signup uses the existing verification page without saving unproven input
  as recovered account details. The permanent unfinished-registration signup link
  is removed; recovery remains accessible through sign-in feedback and Forgot
  Password. The conditional signup panel appears after email proof and reuses the
  saved details. Code entry retains masked
  email, autofill/paste support, visible focus, accessible alerts, resend cooldown,
  and duplicate-submission guards. No extra expiry countdown, modal, or page was added.
- Pending and unverified customer sign-in cannot issue sessions. Verified customer
  and eligible admin/staff login confirmation remain unchanged.
- Normal password-reset eligibility now requires verified email for customers at
  request, code verification, and final reset. Pending customers do not qualify.
  Legacy unverified customers must use registration recovery. Forgot Password
  keeps its generic response and first shows neutral confirmation with an explicit
  Enter code action; generic API success cannot automatically open reset OTP entry.
  The registration recovery entry remains visible on the request and confirmation
  screens. Signup verification no longer has a Use another email button or handler;
  login verification and existing links retain their separate handlers. Eligible admin/staff
  reset and staff setup behavior remain intact.

## OTP and queued mail policy

`RegistrationEmailCodeService::TTL_MINUTES` is **10**. Issuance records one server
time for both `last_sent_at` and its ten-minute deadline. Validation and queued
mail guards enforce both the stored deadline and ten minutes from issuance,
including old OTPs whose stored deadline was fifteen minutes. Codes lacking
issuance metadata must be replaced through resend. Already-issued legacy links
retain their original deadline and single-use compatibility; no new links are issued.

Registration resend cooldown remains **45 seconds** and the maximum is **five
invalid attempts**. Superseded codes cannot pass the current hash check. Correct
but expired codes cannot authorize a grant or activate an account. Expiry-specific
feedback for a code is shown only after it matches, limiting public disclosure.
Route throttles remain in place. Incorrect code attempts do not reset on resend
or edit during the current validity window.

Both HTML and plain-text emails render `This code expires in 10 minutes.` from
the service constant, show the six-digit code, state its purpose, retain Bethlehem
branding, and say an unrequested registration email may be ignored.

Login confirmation retains its configurable **15-minute default**
(`LOGIN_CONFIRMATION_TTL_MINUTES`) and **60-second resend cooldown**. Password-reset
codes retain their separate configurable **15-minute default**
(`PASSWORD_RESET_TTL_MINUTES`) and **45-second cooldown**. Staff setup links retain
24-hour validity. These expiration policies were not changed. A read-only check of the current local
configuration confirmed registration 10 minutes / 45 seconds / five attempts,
login 15 minutes, password reset 15 minutes, and pending retention 48 hours.

The existing encrypted durable queue, transactional job insertion, retry/backoff,
shared receipt locks, rollback on insertion failure, immediate-delivery fallback,
and suppression of replaced/consumed/locked issuances remain in use. Registration
jobs also check pending retention and the ten-minute maximum. Mail retries never
restore an earlier hash. Existing bounded private delivery-status checks are
reused; no background polling was introduced.

## Retention, migration, and deployment

The default is **48 hours from `created_at`**, independent of OTP expiry. Sending,
editing, or incorrectly verifying does not extend it. Pending rows without a
creation timestamp are considered expired. Expired rows are excluded from sign-in,
resend, completion, and related staff identifier reservations. Fresh signup prunes
them before validation, releasing email/username/phone immediately.

One `PendingCustomerRegistration::pruneExpired()` implementation serves signup and
the existing daily scheduled cleanup. No scheduler was added. The cleanup's
indexed DELETE takes database row locks; it cannot remove users or customer history.

Apply the forward migration
`2026_10_22_000001_add_registration_recovery_grants.php`. It adds nullable unique
hashed review grants and expiry timestamps to the two registrant tables, plus an
index on pending `created_at`. It is repeatable after partial schema changes and
has tested up/down behavior. Applied migrations were not rewritten.

Deployment steps:

1. Back up the database through the existing deployment process. Apply pending
   migrations with `php artisan migrate --force` before serving the updated app.
2. Set `PENDING_REGISTRATION_RETENTION_HOURS=48` explicitly for the new policy.
   If hours is absent, an explicitly configured legacy
   `PENDING_REGISTRATION_RETENTION_DAYS` is honored in days. Remove/replace the
   legacy variable when adopting 48 hours; existing installations with `...DAYS=7`
   otherwise deliberately retain their seven-day setting.
3. Refresh configuration with `php artisan config:cache` and restart workers with
   `php artisan queue:restart`. Publish the updated static auth pages/scripts and
   registration templates together. Their changed asset versions invalidate old
   browser script caches. The existing CSS classes are reused.
4. Retain the existing Hostinger scheduler cron invoking `artisan schedule:run` and
   current durable database queue/worker or supported immediate-mail setup. Check
   `php artisan schedule:list` for exactly one `pending-customer-registrations:prune`.
   Keep HTTPS frontend/sender and shared cache requirements from
   `docs/hostinger-email-auth.md`.
5. Legacy link handling and notification URL hydration are temporary compatibility
   only. Remove them together after pre-code links expire and pre-release link
   jobs drain. Do not remove historical migrations. OTPs without issuance metadata
   require a fresh code rather than bypassing the maximum lifetime.

## Code cleanup and performance

Removed duplicate pending cleanup predicates, duplicated customer-creation/session
responses and the now-unused customer payload helper from verification. Completion
and clearing verification state have shared sources. Existing backend/frontend
tests were updated for credentials, recovery responses, retained collision data,
ten-minute expiry, and both rendered email formats; old activation expectations
were replaced in their original tests. The normal password-reset interface tests
were inspected and remain valid without changing their expectations.

The extra work is bounded indexed identifier locks/lookups on registration and
hashed grant lookups during recovery. Validation does not fetch customer lists.
The locked identifier matches are reused for the recovery decision rather than
queried again. Existing user verification adds an indexed email lookup so a legacy user wins a
collision. Recovery sends only on explicit action, and reuses existing requests,
queue code, and form. Unrelated customer/admin queries are unchanged; the full
suite's existing growth probe still reported five queries for both three and
thirteen customers. No N+1 query, new scheduler, cache invalidation scheme, or
polling loop was added. Production Core Web Vitals were not measured.

## Executed verification and limitations

- Baseline: 109 existing email verification/password reset/interface tests passed
  (1,135 assertions); the original frontend signup regression passed.
- New regressions were observed failing for expiry, abandoned signup blocking,
  missing ownership review, unverified reset eligibility, pre-release OTP lifetime,
  and expiry disclosure before implementing their fixes.
- Final focused authentication/customer suites: **220 tests passed, 2,682 assertions**.
  The separate final overlapping-request/runtime-capability check also passed
  (one test, 14 assertions).
- `npm test`: **76/76** regression scripts passed. Signup and frontend auth/session
  regressions were rerun successfully after the final JavaScript updates.
- Full backend suite, PHP 8.4 with a process-only 512 MB memory limit:
  **1,433 tests, 18,726 assertions; one failure, seven skips**. The first full run
  reached the runtime's default 128 MB limit; application memory settings were
  not changed. The one failure is
  `AdminPetProfileTest::test_service_case_summary_uses_saved_line_totals_and_handles_empty_history`
  (`0.04` expected, `0.02` returned). It also fails in isolation with HEAD versions
  of every changed application class loaded from disposable copies. The existing
  service-history rounding implementation and assertion were left intact.
- Actual overlapping verification requests ran in separate PHP processes against
  a disposable SQLite database using supported IMMEDIATE transactions. The second
  process proved it observed the first process's writer lock. Results: HTTP 200
  and 422, one user, one token, zero pending rows. This does not substitute for
  MySQL/InnoDB concurrency testing on staging.
- Queue insertion rollback, replaced/expired/locked mail suppression, transport
  retries, legacy linked-history preservation, grant forgery/expiry/replay,
  exact 600-second boundary, identifier uniqueness, and atomic completion are covered.
- No live inbox/Hostinger deployment or production data mutation was performed.
  Local MySQL was unavailable. The available browser timed out reaching localhost,
  so responsive visual inspection was not completed; existing layout/CSS were retained.

Commands (PowerShell; use the available PHP 8.4 executable on Windows):

```powershell
$authPhp = 'C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe'
& $authPhp -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter 'EmailVerificationTest|PasswordResetTest|PasswordResetInterfaceTest|IndependentAuthenticationSessionsTest|SessionLockTest|TestingEnvironmentTest|AdminUnregisteredCustomerTest|AdminDashboardCustomerSearchTest|AdminCredentialChangeTest|AdminStaffAccountManagementTest' --no-progress
npm test
node tests/customer-signup-verification-regression.cjs
node tests/frontend-auth-session-regression.cjs
& $authPhp -d memory_limit=512M vendor/phpunit/phpunit/phpunit --no-progress
```

## Manual testing checklist: localhost and Hostinger staging

Use disposable test registrations and a controlled inbox in both environments.
On localhost, start the existing frontend/API and configured queue worker. On
Hostinger staging, apply the migration/config/worker steps above first.

1. Submit a new signup. Confirm only one pending row and no user/session; verify
   absence from Admin Customers Active. Receive both HTML/text email versions.
2. Abandon signup, open another browser/device or clear tab session storage, and
   repeat signup with the same email. On the verification screen, request a code
   if needed. Confirm
   names/password are unchanged before proof, then review saved details, choose a
   password, and explicitly complete. Confirm one verified active user and no pending row.
3. Use an unrelated browser to POST the same email with different details/password
   or a forged grant. Confirm no overwrite, protected details, activation, or session.
4. Request a new code after 45 seconds; confirm the earlier code fails and the new
   one succeeds once. Test just before ten minutes, at ten minutes, and after ten
   minutes. Test five wrong codes and route throttling; request a fresh code after lockout.
5. Let a review grant expire or replay a consumed grant. Confirm rejection. Correct
   a validation error before grant expiry and verify that entered fields remain.
6. On disposable fixtures, age pending `created_at` beyond configured retention
   while keeping `updated_at` recent. Confirm resend/sign-in cannot revive it and
   fresh signup can reuse its email, phone, and username. Verify scheduled pruning.
7. Exercise an unverified legacy user with linked pet/booking records, and a
   same-email pending collision. Confirm review uses the user's code/details,
   completion preserves its user ID and relationships, and consumes the pending row.
   Confirm a verified/disabled account cannot be replaced by pending credentials.
8. Verify pending/unverified customers cannot use normal password reset or sign in.
   Verify a completed customer, admin, and established staff member retain normal
   sign-in confirmation and password recovery, with their independent expiry policies.
9. Pause mail workers, issue and replace codes, then resume workers. Only the current
   live issuance should send. Simulate delivery retry and verify it neither restores
   an old code nor creates an extra account. Confirm existing delivery feedback.
10. On staging MySQL, submit the same original verification or recovered-completion
    request concurrently. Confirm one successful completion, one rejection, one user,
    one session, and no pending row. Also overlap cleanup, edits, and verification.
11. Use keyboard only, paste/autofill an OTP, and simulate network/server errors.
    Confirm focus, alerts, preserved fields, disabled busy controls, and resend recovery.
    Inspect at 320, 375, 414, and 768 px for clipping/overflow and readable actions.

Design references applied selectively to the existing Bethlehem interface:
[GOV.UK email confirmation](https://design-system.service.gov.uk/patterns/confirm-an-email-address/),
[GOV.UK error messages](https://design-system.service.gov.uk/components/error-message/),
[NN/g form errors](https://www.nngroup.com/articles/errors-forms-design-guidelines/),
and [OWASP password recovery](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

## Localhost browser follow-up — October 22, 2026

This pass opened and interacted with the running application at
`http://127.0.0.1:8000/index.html`, using the current uncommitted local code.
No GitHub or Hostinger application was accessed. The user chose to keep the
environment unchanged and limit this pass to UI validation after the local
database/mail prerequisites were identified.

Newly verified in the actual browser:

- Signup required fields, invalid email, password mismatch and minimum length,
  error announcements, and preservation of entered details. Invalid client-side
  submission made no registration request.
- Registration verification's ten-minute wording, readable masked email, six
  code cells, numeric keyboard entry, formatted paste, disabled incomplete/busy
  controls, loading text, and incorrect-code feedback against an unknown test email.
- Double-clicking Verify produced one request. Resend cleared the old input,
  displayed loading, and used the server's 45-second cooldown. Existing three-per-
  ten-minute abuse limits subsequently extended the displayed interval as intended.
- A fresh tab without prior verification state showed the email-entry recovery
  screen. Its resend attempt received HTTP 429 and retained the server's retry
  interval. This checks the entry UI and throttling, not recovery of a pending row.
- Unknown-identity sign-in rejection, generic Forgot Password progression, masked
  reset email, keyboard Enter submission of an invalid reset code, and navigation
  from Forgot Password to unfinished-registration recovery.
- Signup, verification, sign-in, and Forgot Password layouts at desktop 1280px,
  tablet 768px, and mobile 375/320px; verification also at 414px. No horizontal
  overflow or inaccessible primary controls were found after viewport settling.
  Some full-page captures misrendered the layout; viewport captures and measured
  element geometry confirmed the correct rendering without a layout code change.

One confirmed UX defect was fixed: successful resend confirmation used red error
styling and `role="alert"`. `scripts/auth/client/signup-verification.js` now marks
that confirmation as `role="status"`; `css/components/verification-code.css` gives
it the existing neutral text color. Failures keep their error alerts and styling.
`pages/client/verify-email.html` versions the changed script and stylesheet.
The existing `tests/customer-signup-verification-regression.cjs` now checks both
the success status and failed-resend alert; no new test file or API was added.

The updated regression was observed failing before the fix and passed afterward:
`node tests/customer-signup-verification-regression.cjs`. JavaScript syntax and
`git diff --check` passed. The previously reported 220 backend tests and 76 frontend
scripts were not rerun in this follow-up and remain historical results.

Observed HTTP results were expected negative/generic responses: registration
verification 422, resend 200/429, sign-in 401, password request 202, reset-code
verification 422. No transport failures, unexpected 5xx responses, or application
JavaScript console errors were observed. There was no signup POST, successful
verification, customer-session issuance, or normal password reset in this pass.

Remaining unverified: actual new registration and atomic completion, abandoned
pending-row recovery/overwrite protection/duplicate counts, pending or legacy
unverified eligibility, verified-customer sign-in/reset, actual expired-code
responses, mail content/delivery, and MySQL concurrency. XAMPP PHP can reach the
local MySQL database, but both registrant tables lack the new recovery-grant
column. The existing additive migration is still unapplied; local mail uses
external SMTP rather than a capture mailbox. No migration, database fixture,
customer-data change, SMTP delivery, or environment configuration change was made.

The temporary extra browser tab was closed, viewport overrides were reset, and
the original tab was returned to the application's homepage. Source is suitable
to push for review; this UI pass does not establish deployment readiness. Apply
the existing migration and complete controlled end-to-end/mail and staging MySQL
checks before treating deployment validation as complete.

## Recovery eligibility and UI cleanup follow-up — October 22, 2026

Public repeat signup no longer returns `requires_registration_recovery` for either
verified or unfinished accounts. Both retain the same neutral response. The
existing OTP-authorized review grant controls the conditional registration panel;
unknown, expired, disabled, and verified accounts cannot obtain that grant.
Repeat signup preserves stored account details and clears stale local edit/review
credentials before generic verification. Losing the review grant returns to email
verification. The original-tab signup/edit and legacy recovery paths are retained.

Forgot Password preserves its initial description, blue styling, and shared OTP
component. Generic success shows a neutral confirmation before explicit Enter code;
reload, cooldown, change-email, keyboard focus, and OTP/reset states remain covered.
The unfinished-registration entry remains available. The permanent signup recovery
link and signup verification's Use another email control/handler were removed.
The recovery link display selector now respects hidden controls, preventing the
back action from appearing on the initial email screen.
No endpoint, database migration, account-status polling, or additional request was
introduced; existing asset caching remains in place with revised script versions.

Current automated verification:

- PHP 8.4: **152 tests, 1,851 assertions**, all passing, filtered to
  `EmailVerificationTest`, `PasswordResetTest`, `PasswordResetInterfaceTest`,
  `IndependentAuthenticationSessionsTest`, `SessionLockTest`, and
  `TestingEnvironmentTest`. These use isolated SQLite and fake/test mail, including
  the existing overlapping-verification regression.
- `customer-signup-verification-regression.cjs`,
  `frontend-auth-session-regression.cjs`, and `email-delivery-regression.cjs` passed.
  Existing tests were updated for the removed public recovery flag, ownership-gated
  panel, neutral reset confirmation, preserved cooldown/focus, and lost grants.
- Changed JavaScript syntax checks and `git diff --check` passed.

Browser work in this pass was limited to static desktop UI inspection. No forms,
OTP submissions, actual registration, password reset, live email delivery, database
migration, or viewport-based functional testing were performed. Those manual
workflows remain with the user. Earlier full-suite and browser results above are
historical and were not repeated in this pass. Work remains on `gerald-frontend`;
no branch, commit, push, or deployment was created.
