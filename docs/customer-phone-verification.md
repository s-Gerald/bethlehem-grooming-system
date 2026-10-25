# Customer phone management and grooming completion SMS

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
