# Customer phone management and grooming completion SMS

## OTP transport diagnosis and fix — October 26, 2026

### Confirmed local cause and evidence

The PHP **8.4.26** executable serving port 8000 had no configured CA bundle in its own `php.ini`. A non-sending Laravel HTTP GET of the public iProgSMS documentation reproduced **cURL error 60**, categorized as `tls_verification_failed`, with **no HTTP response**. Certificate verification failed before an authenticated OTP request could be submitted. The separate XAMPP PHP installation already had usable CA settings; changing its INI would not correct the running PHP 8.4 server.

Laravel configuration inspection showed that configuration was **not cached**, the provider base was the official HTTPS domain, a token was configured and matched the private local `.env`, the application key was present, and verification was available/enforced. Only booleans were reported; no token was printed. Incorrect credentials, insufficient credits, DITO routing, and an obsolete payload were not established as causes. The reproduced transport failure is conclusive for the tested PHP runtime, but individual historical browser submissions were not replayed or traced retrospectively.

The local environment fix was applied to `C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.ini`, after saving `php.ini.before-otp-ca-20261026-012358.bak` alongside it:

```ini
curl.cainfo="C:/xampp/apache/bin/curl-ca-bundle.crt"
openssl.cafile="C:/xampp/apache/bin/curl-ca-bundle.crt"
```

This existing CA bundle is readable. TLS verification stays enabled. With the corrected INI, the same **unauthenticated public documentation GET returned HTTP 200**, including a final check in 1,335 ms. The owner restarted localhost; port 8000 was then confirmed to use PHP 8.4 with a process start time after the INI update. No production configuration was changed, and no token-bearing provider request, private account endpoint, paid send or live OTP verification was used. The temporary command-line diagnostic helper was removed after inspection.

### Provider contract and focused code correction

Rechecked the [official iProgSMS documentation](https://www.iprogsms.com/api/v1/documentation). The existing HTTPS JSON POST to `/api/v1/otp/send_otp`, `api_token`, local `09` destination, `expires_in_minutes`, and `message` containing `:otp` match the documented request. The documented success includes string `status: "success"`, a string six-digit `otp_code`, `otp_code_confirmed: false`, the local destination and an expiry timestamp. Verification remains `/api/v1/otp/verify_otp` with the same bound destination and the submitted code. The documented invalid-token example has numeric body `status: 500` and `message: "Invalid Token"`; tests now confirm rejection classification with both HTTP 200 and HTTP 500 envelopes. Generic HTTP 500 errors and HTTP 408 timeouts remain uncertain. The account's real token has not been tested with a paid request.

No phone-normalization or success-validation rule was relaxed. A genuinely different destination, numeric rather than string code, missing data, invalid/expired timestamp or excessive expiry still fails closed. No evidence supported a provider-format workaround. The unchanged full clinic OTP message meets the application GSM-7 limit; actual provider/template acceptance and handset text remain unverified until the owner test.

`IprogSmsOtpService` now emits one warning per failed OTP operation through Laravel's logger, with message `iProgSMS OTP failure`. Context is limited to operation, an internal failure category, HTTP status (or null), elapsed milliseconds, allowlisted content type (`json`, `html`, `other`, `unknown`), numeric cURL errno when available, and expected-field presence booleans when useful. Categories include `tls_verification_failed`, `connection_failed`, `request_timeout`, `provider_rejected`, `http_error`, `unexpected_response`, `destination_mismatch`, `invalid_expiry`, and `configuration_unavailable`. No request/response body, raw error text, URL, headers, token, full phone, OTP, fingerprint or SMS text is logged or returned to customers. Use the configured Laravel log destination, normally `storage/logs/laravel.log`, and inspect only this diagnostic event's allowlisted context.

Confirmed pre-submission failures (certificate validation, DNS resolution, connection establishment), explicit provider rejection and definite HTTP client rejection now persist **failed**. Ambiguous timeout, server error or malformed acceptance remains **uncertain**. Both preserve the existing send reservation, cooldown and budgets; neither can verify a phone. The existing recovery modal uses the short `SMS could not be sent. Try again or use another number.` copy for failed attempts and retains the uncertain wording otherwise. There is no automatic retry. Existing three-second connection/eight-second request timeouts, redirect blocking, shared locks, five-minute expiry, single-use HMAC proof, attempt limits, account/phone/IP/global budgets, uniqueness, password-authorized replacement, recovery and cached `/api/me` behavior remain. Grooming SMS sending and dynamic pet-name formatting are unchanged.

### Exact files updated

- `app/Services/IprogSmsOtpService.php`: safe failure classification and diagnostics.
- `app/Services/CustomerPhoneVerificationService.php`: persist failed versus uncertain and return safe recovery metadata without extra requests.
- `scripts/components/customer-phone-verification.js`: failed-send wording in the existing recovery view.
- `tests/Feature/PhoneVerificationTest.php`, `tests/customer-dashboard-history-regression.cjs`: update existing failure expectations and cover safe logging, TLS/DNS/connect/timeout failures, rejection, malformed responses, cooldowns, no retry, and failed recovery.
- Cache references only: `scripts/components/client-settings.js`, `scripts/components/customer-pre-registration-button.js`, `scripts/components/pre-registration-options.js`, and `pages/client/dashboard.html`, `pages/client/grooming-history.html`, `pages/client/my-pets.html`, `pages/client/notifications.html`, `pages/client/pet-details.html`, `pages/client/pre-register.html`, `pages/client/settings.html`. Related imports use `20261026-otp`; API/CSS versions stay unchanged.
- `docs/customer-phone-verification.md`: this report and acceptance procedure.
- Local runtime configuration outside Git: the PHP 8.4 INI and its backup identified above.

### Verification and visual inspection

- PHP 8.4 PHPUnit filter `PhoneVerificationTest|GroomingCompletionSmsTest|ClinicSmsMessageTest|CustomerPreRegistrationAccessTest`: **82 tests, 816 assertions, zero failures, one optional MySQL check skipped**. Provider calls are faked, including transport exceptions with synthetic secrets; assertions confirm safe logs/client responses and preserved cooldowns/budgets/security.
- Full `npm test`: **81/81 regression scripts passed**, including Settings, shared verification modal, pre-registration/cache/navigation and the previously repaired admin timer mock.
- `npm run build`: **passed**, including OCR assets, Tailwind and Vite. Existing Browserslist-data/experimental VM-module notices are non-fatal.
- `git diff --check`: passed.
- Inspected the existing signed-in Gerald Settings page in the ChatGPT browser. Its verified-number card retains the Bethlehem blue/white styling, spacing and controls. No manual navigation, data entry, OTP submission or password reset was performed. The failed recovery view is covered by the existing mocked frontend suite; no live send was used to force that view.

### Hostinger and one-handset acceptance

No migration, new environment variable, queue worker or scheduled task is needed. No commit, push or deployment was performed. Deploy the changed application files and cache-version references together through the existing release process, with the normal frontend build. Existing SMS flag/limit names remain. Hostinger must have working outbound HTTPS and a trusted CA bundle for its own PHP runtime; inspect that runtime if it reports cURL 60. **Do not copy the Windows CA path to Hostinger or disable certificate verification.** A host already using valid CA trust needs no corresponding INI edit. Follow the host's normal reload/config-cache procedure only when its runtime/configuration changes. No Hostinger account or production configuration was changed or verified here.

1. On the restarted local server, refresh Settings and let existing cooldowns/limits expire naturally. Use the existing verification/change flow for one owner-controlled DITO number. If replacing the verified number, provide the current password yourself. Select **Send Code once**; do not clear budgets or repeatedly resend.
2. If accepted, confirm the handset receives one message with the clinic header, six-digit code, five-minute validity and `Do not share.` footer, with no unintended provider-added text. Submit the code yourself and confirm the new number becomes verified only afterward. Confirm the provider's actual credit usage privately.
3. If it fails, stop sending and inspect the single safe `iProgSMS OTP failure` event. A transport error has no HTTP status; `provider_rejected` records a rejection; timeout/malformed acceptance remains uncertain and must not be automatically retried. Inspect the corresponding provider activity privately rather than resubmitting. If an accepted request has no handset delivery, or a rejection persists after secure transport succeeds, contact iProgSMS with the operation, timestamp/timezone, HTTP status/category and any independently known provider activity/message ID. Never supply tokens, OTPs, full payloads or private log files.

No provider-side fault is confirmed, and **real OTP acceptance/delivery is not claimed**. The owner-controlled acceptance test is the remaining step to establish DITO delivery and actual template behavior.

## Phone-verification recovery update — October 26, 2026

The shared modal now shows **Change number** beside the masked destination for active, expired, uncertain and attempt-locked challenges. It returns to the existing phone-entry form in the same dialog, prefills a candidate already known to this browser session, focuses the input and sends no request. **Back to code** restores a still-valid challenge until a new attempt is submitted. Re-entering the same known active candidate reuses its code instead of sending another SMS. The helper now reads `Smart/TNT numbers are not supported.` for the owner's current SMS setup; validation still accepts Philippine mobile formats without prefix blocking or carrier detection.

Uncertain sends show **Verification Unavailable**, the masked destination, Change number and a cooldown-aware **Try Again** button. Unusable OTP controls are hidden. Expired codes show `Code expired. Send a new code.`; attempt lockout still blocks sends even after changing the input. Editing does not change server state, restart cooldowns, reset attempt/send budgets, cancel a valid code, or request `/api/me`. New candidate submissions still use the existing authenticated send route and locks. That route supersedes the old generation only after validation, authorization and budget checks. It saves the account phone only after provider verification and atomic proof consumption. A customer replacing an already verified number must authorize a new candidate with the current password again; the existing verified number remains active.

The only backend change is an additional safe `challenge` object in the existing `sms_send_uncertain` error response, built from the already available attempt values. It contains the same metadata as recovery, not the full phone, OTP, fingerprint, token or provider response. This avoids an extra state query for normal provider failures. A lost HTTP response triggers one read of the existing state endpoint, without polling or retrying the paid request. Full candidate numbers known in memory can be reused when the recovered generation matches; after a full page refresh, a candidate not already known from the profile may need to be re-entered because recovery intentionally exposes only a masked destination.

Existing Settings confirmation, the original pre-registration destination, native dialog focus trapping, X/Cancel, blocked Escape/backdrop dismissal, and stale-response guards remain. The existing countdown is reused and removed on close. The old unconditional OTP display and duplicate send/resend handling were replaced in place. No grooming SMS job, queue, provider API, template, credential or notification behavior changed.

Updated implementation files: `customer-phone-verification.js`, the existing verification CSS, and `CustomerPhoneVerificationService.php`. The Settings/entry imports and affected customer HTML script references use `20261026-recovery` cache versions. Existing `PhoneVerificationTest.php` and `customer-dashboard-history-regression.cjs` now cover candidate switches, old-code rejection, preserved budgets, failure metadata, recovery views, local step transitions, password authorization, single timers, lost responses, and existing Settings/pre-registration success. No new test file or parallel modal was added.

Verification: focused PHP **74 tests / 521 assertions**, no failures and one optional local MySQL skip; Settings, modal/dashboard, cache, selection-flow and authentication JS scripts passed. Full `npm test`: **80/81 scripts passed**; the sole unrelated baseline failure remains `admin-settings-intake-regression.cjs` with `window.clearTimeout is not a function` in its existing mock. Production build and `git diff --check` passed; the build retains the existing outdated Browserslist-data notice. The final expiry-focus refinement also passed the modal/dashboard and Settings scripts. Only the provided Gerald dashboard was opened for browser inspection; its phone is already verified, so live modal inspection was unavailable there; manual navigation, data entry, OTP submissions and password resets remain with the owner. No live SMS was sent.

This fix needs **no migration or new environment variable**. Deploy the changed PHP/JS/CSS/HTML files through the existing Hostinger release process, including normal rebuilt assets; keep the versioned imports together and refresh any deployed HTML/CDN cache if it retains old references. Existing SMS environment, database queue and scheduler settings stay as documented below. No new worker or infrastructure setup is needed. The owner still needs to verify real-device failure/retry/change flows, mobile rendering and keyboard behavior. No commit, push, deployment, production `.env` change or database operation was performed for this recovery fix.

## Initial SMS implementation — October 25, 2026

Implemented locally on `gerald-frontend`, October 25, 2026. No branch, commit, push, production configuration change, real SMS submission, or Hostinger deployment was performed. The additive completion-outbox migration was applied to the confirmed local MySQL server after checking the local environment, loopback host and server hostname. The account count was unchanged. `.env` was not changed.

## What changed

- Settings retains its existing Account card, avatar and read-only fields. Phone actions are Add, Verify, Change, or Continue verification / Cancel change. A pending replacement leaves the old verified number visible and authoritative.
- The existing lazy modal and six-cell OTP input serve Settings and Grooming/Clinic entry. Opening only reads safe challenge metadata. It never sends SMS. Explicit Send Code / Resend Code actions are required. Closing preserves an active challenge; reopening or refreshing can recover it without another paid send. Settings confirmation stays on Settings; pre-registration continues to the selected destination. Existing ongoing-registration restrictions have priority.
- Replacing a verified number requires the current password, checked server-side with an account-scoped five-failure/hour budget. Initial Add/Verify does not require this password step. Resending an authorized replacement requires the original phone ownership to remain unchanged. Provider verification, uniqueness checks, account locking, HMAC binding and atomic proof consumption remain in place. A replacement queues a brief security email through the existing database mail infrastructure; email/queue failure cannot undo committed phone ownership.
- Both fixed SMS types use the existing iProgSMS HTTP boundary. The formatter counts GSM-7 units, including two-unit extensions, and preserves the full header/footer. Single-pet SMS uses the booking's actual registered pet name. Missing, incompatible, injected/control-character or overlong names use the fixed generic fallback. Multiple active pets use the plural message, never a list of names.
- Grooming SMS is recorded at the common whole-visit completion transition, including bulk completion, individually finished pets and cancellation of the last unstarted pet after others finish. Partial completion retains its existing in-app notice and sends no external SMS. Canceled pets are excluded from the completion subject. Portal association comes only from `bookings.user_id`; matching contact information never establishes a link.
- Grooming-completion email delivery and its unused notification/templates were removed. Existing in-app, recovery, authentication and other emails remain.

## Provider contract and acceptance limits

Checked against the [official API documentation](https://www.iprogsms.com/api/v1/documentation) on October 25, 2026:

- OTP creation uses `POST /api/v1/otp/send_otp`, custom `message` with `:otp`, and five-minute expiry. Verification still uses `POST /api/v1/otp/verify_otp`. Codes are provider-generated and provider-verified.
- Ordinary completion SMS uses `POST /api/v1/sms_messages`. A successful response must have HTTP success, numeric `status: 200` and a valid `message_id`. This records **accepted**, never confirmed handset delivery.
- Separate message-header/footer request parameters and automatic approved-template behavior are not documented. The application therefore composes the full message in `message`, with one clinic header and the correct per-type footer. No dashboard template API or dashboard setting was changed.
- Keep the global footer blank. No dashboard change is prescribed from an assumption. Before enabling paid completion SMS, send controlled OTP and completion acceptance messages to an owner-controlled handset. Confirm exactly one clinic header, the separate correct footers, no provider-added text causing segmentation, and actual provider credit usage. If the approved dashboard header is automatically appended, resolve that behavior with iProgSMS/support before activation; do not blindly delete an approved KYC header.
- The [official network announcement](https://www.iprogsms.com/announcements) distinguishes normal `IPROGOTP` support for Smart/TNT from regular shared-sender restrictions and fallback routing. The UI includes only the requested short helper. There are no prefix blocks, paid network-detection calls or assumptions of identical coverage. Regular grooming SMS to Smart/TNT remains a provider/sender acceptance limitation with the current shared sender.
- A documented message-status endpoint accepts a known provider message ID. Inspect accepted IDs through the provider dashboard/status API when investigating; do not resubmit. A timeout without an ID cannot safely prove rejection. No real-phone delivery was verified by this implementation.

## Final SMS text and computed GSM-7 lengths

These are composed application messages, including two LF separators. Actual provider additions/charging require the handset check above. Joseph is an example of runtime pet data, not a production constant.

OTP: **92 units** after six-digit substitution:

```text
Bethlehem Animal Clinic
Your verification code is 482913. Valid for 5 minutes.
Do not share.
```

Single pet, example actual booking name Joseph: **90 units**:

```text
Bethlehem Animal Clinic
Joseph's grooming is complete.
Please visit the clinic for pickup.
```

Multiple active pets: **92 units**:

```text
Bethlehem Animal Clinic
Your pets' grooming is complete.
Please visit the clinic for pickup.
```

Missing/unsafe/overlong single-pet name: **92 units**:

```text
Bethlehem Animal Clinic
Your pet's grooming is complete.
Please visit the clinic for pickup.
```

A single ordinary GSM-7 pet name can occupy up to 76 units in this format (160 total); extension characters consume two units. Unicode is not silently transliterated or truncated.

## Authenticated lifecycle and budgets

Customer-only Sanctum routes retain active-account/session restrictions:

| Endpoint | Purpose |
| --- | --- |
| `GET /api/phone-verification/state` | Explicit-open recovery: challenge ID, masked destination, active/expired/locked state, remaining expiry/cooldown and verification eligibility |
| `POST /api/phone-verification/send` | Validated phone and optional current password; replacement requires the password |
| `POST /api/phone-verification/resend` | Same authenticated customer's challenge ID |
| `POST /api/phone-verification/verify` | Challenge ID and six-digit code |
| `POST /api/phone-verification/cancel` | Supersede the named challenge and clear proof; retain old verified phone, cooldown and attempt counters |

The state endpoint never returns a plaintext OTP, fingerprint, full destination, raw provider response or credentials. `/api/me` adds only pending-replacement metadata using an indexed existence query, without a separate challenge-state request on page load. Verification refreshes the existing profile once and invalidates profile/access caches. Send/cancel invalidate the profile's pending metadata while preserving eligibility and unrelated caches. No background OTP sends, retries or state polling were added.

Existing shared customer/destination locks and the global budget lock remain. `SmsSendBudget` is the common reservation source for OTP and grooming submissions:

- OTP expiry 5 minutes; resend cooldown 60 seconds; verification failures 5/hour per account, retained across number switches/resends/cancellation.
- Sends: account 5/hour, destination 5/hour, requesting IP 20/hour, global 100/hour and 500/day. Grooming jobs have no requesting-client IP and share the other paid-send budgets.
- New configurable account/destination daily caps default to 20. These constrain repeated account abuse without reducing the existing clinic-wide daily allowance. Global daily allowance is now configurable, default 500; choose a lower private value if appropriate for the clinic's real volume.
- Malformed/claimed numbers, same already verified number, invalid password, exhausted budgets and contending requests never call the provider. Uncertain provider attempts still reserve cost/cooldown.

Use shared persistent database/Redis cache locks and counters. Array/null stores fail closed outside tests. Keep the same cache namespace and application key across PHP workers. Clearing limiter cache discards budgets. These protections do not replace edge rate limiting/WAF protection.

## Durable completion delivery

Forward migration: `database/migrations/2026_10_25_000004_create_grooming_completion_sms.php`.

The outbox has a unique `booking_id`, indexed pending state/queue timestamp, linked account ID, safe operational state and optional provider message ID. It stores neither message bodies nor phone numbers. States are pending, accepted, failed, uncertain, or skipped; `queued_at` distinguishes queued pending work. No historical migration was modified.

The completion transaction inserts the event. After commit, the service inserts an event-ID-only job on the existing database queue. Queue insertion failure leaves recoverable pending work and does not fail grooming completion. The Laravel scheduler recovers pending/stale queued events in bounded batches. Duplicate queued jobs cannot produce duplicate submissions: one atomic database claim changes pending to uncertain before any provider call. A crash, lost response, timeout or redelivery cannot automatically resubmit that event. The job has one try even when the worker's general default is three. This provides at most one automatic submission, not exactly-once physical delivery.

The worker rechecks the linked active customer, current authoritative verified phone, finished visit and all active pet states. It never uses a pending replacement. A newly committed verified replacement is read before sending. Missing configuration, budget exhaustion or rejection cannot roll back the already completed visit. Provider HTTP runs outside database transactions, with three-second connect/eight-second total timeout, redirects disabled and no automatic retry. Sensitive exceptions/responses are contained rather than logged.

## Local and Hostinger setup

Private environment settings (never frontend/Vite):

```dotenv
IPROGSMS_API_TOKEN=
SMS_VERIFICATION_AVAILABLE=true
SMS_VERIFICATION_ENFORCED=true
GROOMING_COMPLETION_SMS_ENABLED=false
SMS_USER_DAILY_LIMIT=20
SMS_PHONE_DAILY_LIMIT=20
SMS_GLOBAL_DAILY_LIMIT=500
```

Missing flags default false. Enable grooming completion explicitly only after acceptance. Available/enforced combinations retain their established behavior: both false preserves optional historical access; available true/enforced false offers verification at entry; both true blocks unverified backend submission; available false/enforced true continues blocking unverified submission while verified users retain access.

Local: the new migration is already applied. Keep a fake provider for automation; only the owner should conduct real OTP/phone-change testing. To manually exercise completion SMS, configure the flag privately and run the existing database queue locally; no worker or real send was launched by the implementation agent.

Hostinger release, through the existing deployment process:

1. Deploy backend changes, raw `pages/`, `scripts/`, existing verification CSS, rebuilt `css/output.css`, complete `public/build/` manifest/assets and normal generated vendor assets together. Keep existing routes, Vite paths, private credentials, sessions and HTTPS settings.
2. Back up through the normal process and run, with the verified CLI PHP binary from the deployed Laravel root:

   ```sh
   php artisan migrate --force
   php artisan config:cache
   php artisan queue:restart
   ```

3. Preserve the established database queue on the application database, its jobs/failed_jobs tables and shared cache/cache_locks tables. Completion jobs use its configured default queue; the existing worker must consume this queue in addition to any `AUTH_MAIL_QUEUE` used for security email.
4. Repository deployment guidance in `docs/hostinger-email-auth.md` specifies the following every-minute hPanel worker cron. Verify the actual path/runtime and existing worker before adding or changing anything; do not duplicate a supervised worker:

   ```sh
   /usr/bin/php /home/ACCOUNT/domains/DOMAIN/APP/artisan queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --sleep=1
   ```

5. Ensure the existing Laravel scheduler runs every minute for outbox recovery and other application schedules. If no scheduler cron exists, configure it through the normal deployment process using the same verified PHP/project path:

   ```sh
   /usr/bin/php /home/ACCOUNT/domains/DOMAIN/APP/artisan schedule:run
   ```

6. Verify actual worker/scheduler execution, outbound provider HTTPS and known-ID status visibility. Do not expose HTTP cron endpoints or dump queue payloads/provider responses. Then perform owner-controlled handset/header/footer/network/credit acceptance and enable `GROOMING_COMPLETION_SMS_ENABLED=true` privately followed by `config:cache` and normal worker restart.

To stop future completion SMS, disable its independent flag and refresh configuration. Existing phone enforcement remains independent. Do not blindly retry uncertain/accepted events or bulk-retry SMS jobs. Keep the outbox during rollback so its uniqueness history remains intact.

## Files and verification

Key existing files: `CustomerPhoneVerificationService`, `IprogSmsOtpService`, phone/auth/admin-booking controllers, API/config/console routes, `api.js`, the shared modal/entry handler, Settings component/phone header and service-selection handler. New formatter, shared budget, outbox service/job, security-email notification and forward migration have narrow responsibilities. Existing lifecycle tests share a fixture rather than duplicating the grooming schema.

Removed obsolete files: `GroomingFinishedNotification.php`, `emails/grooming-finished.blade.php`, `emails/grooming-finished-text.blade.php`, and `GroomingFinishedEmailNotificationTest.php`. Existing notification assertions now preserve in-app notices and reject the obsolete email. `GroomingCompletionSmsTest.php` replaces external delivery coverage.

Executed with PHP 8.4.26, fake HTTP providers, isolated SQLite and explicit non-sending flags:

- Final focused PHP run: **143 tests, 859 assertions; no failures; one optional MySQL case skipped**. Includes phone replacement/recovery/budgets/concurrent OTP processes, grooming event/queue/eligibility/overlapping worker claims, lifecycle/cancellation, preregistration access, customer visit summary and testing configuration.
- Separately opted into the guarded local disposable MySQL migration test: **1 test, 52 assertions, passed**. Both migrations and actual MySQL duplicate-completion rejection were verified; the owned temporary database was removed by the harness.
- Full PHP suite snapshot: **1,772 tests, 23,182 assertions, one failure, eight opt-in skips**. The existing `EmailVerificationTest::test_resend_does_not_extend_retention_and_expired_rows_are_not_mailed` invoked an in-process worker with its default 128 MB ceiling after the suite had grown beyond that size; Laravel exit code 12 denotes memory exhaustion. The isolated rerun passed **1 test, 9 assertions**. No unrelated production behavior or assertion was weakened. This is a full-suite infrastructure limitation, not a claim of a fully green full suite.
- Final complete JavaScript run: **80/81 regression scripts passed**. The remaining unrelated `admin-settings-intake-regression.cjs` failure is `window.clearTimeout is not a function` in its existing mock. It was reproduced with the baseline HEAD API source and unchanged admin settings code. The affected Settings, dashboard/modal, cache, selection-flow and auth/session scripts pass; obsolete cache expectations were updated for pending-change metadata. The final run includes the cooldown-metadata correction and all updated Settings/modal/cache regressions.
- `npm run build`: passed (existing outdated Browserslist-data notice). `git diff --check`: passed.
- ChatGPT browser visual review: existing customer Settings card and the shared Change Phone Number modal inspected. No other role/tab, real data entry, OTP submission, password reset or real SMS functional test was performed.

Remaining owner acceptance: real provider template/header/footer and network behavior, actual handset receipt/credits, manual add/verify/change/cancel/resume flows, mobile/keyboard interaction, and deployed Hostinger queue/scheduler/configuration. No live delivery or Core Web Vitals result is claimed.
