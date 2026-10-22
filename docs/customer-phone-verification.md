# Customer phone verification

## Configuration and activation

Laravel alone calls iProgSMS. Set these values privately in the local or hosting environment; both switches default to false:

```dotenv
IPROGSMS_API_TOKEN=
SMS_VERIFICATION_AVAILABLE=false
SMS_VERIFICATION_ENFORCED=false
```

| Available | Enforced | Behavior |
| --- | --- | --- |
| false | false | Existing customer pre-registration remains available under its usual eligibility rules. SMS routes return an unavailable response. |
| true | false | Eligible unverified customers open real verification through Pre-register. Direct backend bookings retain their existing permissions. |
| true | true | Unverified customers must verify before submitting Grooming or Clinic pre-registration. |
| false | true | Unverified submissions remain forbidden; entry feedback explains that SMS is unavailable. Already verified customers retain access. |

Missing provider credentials also fail safely. Browser flags, cached profile data and URL parameters cannot authorize a submission. Staff/admin walk-ins retain their existing authorization and workflows.

Run the new forward migration before enabling availability:

```sh
php artisan migrate
php artisan config:clear
```

The migration creates only `phone_verification_attempts`. It does not recreate phone columns or modify historical migrations. The existing nullable phone column, unique non-null phone index and `phone_verified_at` remain authoritative. No application `.env` values or live database migrations were changed as part of implementation.

## Provider contract

Reference: [official iProgSMS API documentation](https://www.iprogsms.com/api/v1/documentation), checked October 22, 2026.

- `POST https://www.iprogsms.com/api/v1/otp/send_otp` receives JSON `api_token`, `phone_number`, `expires_in_minutes: 5`.
- `POST https://www.iprogsms.com/api/v1/otp/verify_otp` receives JSON `api_token`, `phone_number`, `otp`.
- Sending requires a successful HTTP response, `status: "success"`, a matching destination, an unconfirmed six-digit provider code and a valid future provider expiry. Expiry is capped at the application's five-minute window. The documented provider range is 1–60 minutes.
- Verification requires a successful HTTP response, `status: "success"` and the documented `message: "OTP verified successfully"`. HTTP 200 alone is insufficient.
- The generic SMS endpoint is not used. TLS checks remain enabled. Redirects are disabled to protect the request body. Connection timeout is three seconds; total timeout is eight seconds. There are no automatic provider retries.

Canonical application/provider destinations are `09XXXXXXXXX`. The send route accepts ordinary phone punctuation and `+63` input, validates it, and normalizes to the existing local format. OTP entry is exactly six ASCII digits. Provider-generated codes, SMS bodies, raw responses and credentials never appear in application responses, browser storage or application logs. Request exceptions are contained at the provider boundary without reporting their sensitive text. Do not enable HTTP request/response recording for these endpoints in external debugging or observability tools.

The provider does not document OTP-specific error bodies, resend invalidation semantics, or verification idempotency. These are not assumed. Malformed responses, errors, outages and uncertain outcomes never verify an account. Complete live delivery/lifecycle checks privately before enforcement.

## Authenticated endpoints and lifecycle

All three endpoints retain Sanctum authentication, usable-account checks, active-session restrictions and customer-only authorization:

| Application endpoint | Body |
| --- | --- |
| `POST /api/phone-verification/send` | `{ "phone": "09XXXXXXXXX" }` |
| `POST /api/phone-verification/resend` | `{ "challenge_id": "server-issued UUID" }` |
| `POST /api/phone-verification/verify` | `{ "challenge_id": "server-issued UUID", "code": "six digits" }` |

Send/resend return only safe metadata: `success`, `message`, `challenge_id`, `expires_in`, and `resend_after`. These durations are remaining server-side time, so network latency can make them shorter than 300 and 60 seconds. Failed send requests do not claim that a code was sent. HTTP 429 includes remaining cooldown when applicable.

There is one persistent attempt per customer. It binds the exact destination, UUID generation, original saved phone/timestamp, expiry, resend eligibility, state and failed-attempt budget. States are `sending`, `active`, `verifying`, `confirmed`, `consumed`, `uncertain`, or `superseded`. Neither sending nor resending saves the candidate phone to the user.

A keyed HMAC fingerprint of the provider-issued code binds that code to its generation. No plaintext OTP is persisted. A code from a superseded generation is rejected even if iProgSMS still accepts it. Laravel never generates its own OTP, and a matching fingerprint alone cannot verify a phone: explicit iProgSMS success is required.

On verification, short database transactions lock the current user and attempt, recheck generation, expiry, original phone/timestamp, account eligibility and uniqueness, save phone plus `phone_verified_at`, and consume the attempt atomically. Another account cannot claim a unique user phone. Pending signup phone conflicts are checked on send and finalization. Changing a phone through the User model clears verification and supersedes outstanding proof, including a change followed by a change back. Query-builder writes bypass model events and must explicitly perform the same invalidation if a future account-management workflow uses them.

## Limits and concurrency

Defaults in `config/services.php`:

- Five-minute OTP lifetime, capped by the provider's actual expiry.
- Sixty-second resend cooldown, enforced for sends as well as resends and number changes.
- Five failed/local or provider verification attempts per customer budget. Resends and destination changes do not reset that budget; it resets after one hour.
- Five sends per customer/hour and destination/hour, twenty per IP/hour, one hundred globally/hour and five hundred globally/day.
- Twenty requests/minute per authenticated customer on each verification route, in addition to the cost budgets.

Provider attempts and uncertain sends consume cost/attempt budgets conservatively. Invalid format, ownership conflicts, exhausted limits and concurrent operations do not contact iProgSMS.

Customer and destination cache locks serialize send, resend and verification across provider calls. A separate short cache lock atomically reserves send budgets. No database transaction or row lock is held over provider HTTP. Generation/state comparisons reject late replies after a newer attempt or account change. User phone uniqueness remains protected by the existing database constraint.

Use a shared cache with atomic locks and persistent rate counters. The established database cache is supported; its cache/cache_locks tables must exist. Redis is suitable across application servers. A file store is suitable only when all workers share the same filesystem. Process-local array/null caches are rejected outside isolated tests. Keep a common application key/cache store across workers; clearing rate-limit caches discards cost protections.

## Failure and recovery

A timeout or malformed send leaves an `uncertain` generation and preserves cooldown/cost limits. The customer may explicitly send again after cooldown. The UI never automatically retries a send. A process interruption in `sending` or `verifying` also requires a fresh explicit send after cooldown; no stale proof can be applied.

If the provider confirms verification but the local account transaction fails, a `confirmed` proof remains bound to the same customer, UUID, original account state and code fingerprint until its original expiry. Retrying that same code retries only the atomic local commit; it does not call the provider again. This also works when the provider call used the last allowed attempt. A changed account, conflicting phone, consumed proof or expired generation still cannot commit. If persisting the provider confirmation itself fails, start a new send after cooldown; do not infer verification from an uncertain response or manually mark the phone verified.

## Existing frontend and cache behavior

The original native dialog now uses real send/resend/verify operations. Missing numbers show Add Phone Number; saved unverified numbers remain editable before sending. OTP entry appears only after confirmed sending. It reuses the email OTP utility, six visual cells, one accessible numeric input, paste handling, countdown formatting and shared blue styling. Code entry, sending, verification and resend controls prevent duplicate submissions. Close and Cancel remain available; backdrop clicks and Escape do not dismiss the modal. Native dialog focus containment blocks background interaction and closing restores trigger focus. Late responses after dismissal cannot reopen the dialog or navigate.

There is no unverified Continue action. Successful server verification invalidates the existing profile/access caches, makes one shared `/api/me` refresh, updates the existing Settings fields/badge if present, and automatically continues to the intended registration page. A profile refresh outage does not undo committed verification; the destination page retries its normal profile read. Normal dashboard rendering adds no requests or polling. The modal/OTP utility/CSS remain lazy; its one local countdown timer stops on closing. The existing eligibility polling is retained.

`/api/me` retains `phone_verification_status`. `/api/me` and the existing access response expose server feature settings; the access response also exposes the phone requirement. Direct Grooming/Clinic form entry redirects to the dashboard when enforcement requires verification; direct service selection disables its links with an actionable message. Ongoing registrations still take precedence. Both actual submission routes enforce the stored requirement before validation and again on the account already locked inside their booking transaction. Rescheduling retains its existing workflow; it cannot create a new registration through these routes.

## Automated verification

```sh
php vendor/bin/phpunit tests/Feature/PhoneVerificationTest.php
php vendor/bin/phpunit --filter 'PhoneVerificationTest|EmailVerificationTest|CustomerPreRegistrationAccess|SessionLockTest|IndependentAuthenticationSessionsTest|PasswordResetTest|CustomerPageLoadingPerformanceInterfaceTest|CustomerGroomingVisitSummaryTest|GroomingAdministrationAuthorizationTest|ClinicAdministrationAuthorizationTest' --display-skipped
npm test
npm run build
```

PHPUnit and the Node regression runner force empty iProgSMS credentials and disabled feature flags; phone tests explicitly use a synthetic token and HTTP fakes with stray requests blocked. They never send live SMS. The phone suite includes actual two-process send/resend/verify contention using owned disposable SQLite files and a shared database cache, plus generation races, replay, expiry, number conflicts and provider-success/local-commit-failure recovery. This validates shared lock behavior; it does not substitute for production MySQL engine/load testing.

The Node regressions cover entry eligibility/flags, editable and missing numbers, failed/confirmed sends, six-digit paste, duplicate submission, resend timing, expiry, loading/errors, close/Cancel, Escape/backdrop policy, stale responses, focus restoration, confirmed navigation, Settings refresh and targeted cache invalidation. Native browser accessibility and responsive rendering still require the user's visual/manual acceptance testing.

Recorded implementation checks on October 22, 2026: the combined PHP 8.2 run completed 597 tests / 7,495 assertions with zero failures and two existing skips. Email's IMMEDIATE-transaction concurrency test was unsupported by that PDO runtime; the isolated MySQL grooming-capacity test requires its dedicated runner. Using the installed PHP 8.4 runtime, the phone suite plus that email concurrency test completed 40 tests / 286 assertions with no skips or failures. The supplemental command was `C:/Users/Gerald/AppData/Local/Programs/BethlehemTestTools/php84/php.exe vendor/bin/phpunit --filter 'test_overlapping_verification_requests_consume_one_pending_registration_on_sqlite|PhoneVerificationTest'`. `npm test` passed all 76 scripts; `npm run build` passed with an existing outdated Browserslist-data notice. MySQL concurrency/load behavior and real SMS delivery remain unverified.

## Private local acceptance testing

1. Apply the migration, privately set your token, set availability true and leave enforcement false. Clear configuration cache. Ensure the cache driver supports shared locks.
2. On an eligible customer, select Pre-register. Check both missing and saved/correctable phone numbers. Send to a number you control, receive the real code, verify it, and confirm Settings shows Verified. Sending, canceling and failed codes must not change the account number/status.
3. Check cooldown, resend, expiry, older codes, wrong codes and failure feedback. Confirm actual provider delivery and response behavior. No live provider behavior was tested by the implementation agent.
4. Only after successful live tests, set both flags true and clear configuration cache. Verify unverified customers are blocked through both the frontend and direct Grooming/Clinic API submissions, while verified customers can submit and existing ongoing-registration restrictions/staff walk-ins still work.

All manual page navigation, actual input, OTP submission and password-reset testing are reserved for the user. Browser inspection by the agent is visual only.

## Hostinger deployment preparation and incident rollback

No deployment or production configuration changes were performed. When ready to deploy reviewed code:

1. Securely set `IPROGSMS_API_TOKEN` in the hosting environment. Start with `SMS_VERIFICATION_AVAILABLE=true`, `SMS_VERIFICATION_ENFORCED=false`; never place the token in public frontend assets.
2. Back up the database and run the normal forward migration process:

   ```sh
   php artisan migrate --force
   php artisan config:cache
   ```

3. Verify the shared cache tables/driver, outbound HTTPS connectivity and the deployed asset versions. This implementation is synchronous; it introduces no SMS queues or worker requirements. Queue restarts are unnecessary for SMS alone; follow the normal deployment policy for any existing workers using cached configuration.
4. Privately complete live delivery, status refresh, resend/expiry and staff-isolation checks. Only then set enforcement true and rerun `php artisan config:cache`.
5. To stop new SMS while retaining the verification requirement, set availability false and retain enforcement true: unverified customers are intentionally blocked. For a deliberate return to the previous unrestricted SMS behavior, set both flags false. Refresh configuration cache after either change. These are distinct operational choices; disabling enforcement restores the old booking permissions.
6. Prefer disabling flags and retaining the attempt table during code rollback. Roll back the attempt migration only after no deployed code uses it and outstanding attempts can be discarded. Do not roll back the nullable-phone migration or erase verified user timestamps as part of this integration rollback.
