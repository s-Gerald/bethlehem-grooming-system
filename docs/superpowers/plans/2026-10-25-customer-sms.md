# Customer phone management and grooming SMS

Implement locally on `gerald-frontend`; no branch, commit, push, deployment, live SMS, or manual browser functional testing.

## Decisions and file ownership

- Extend `CustomerPhoneVerificationService`, `PhoneVerificationController`, `routes/api.php` and `AuthController::me`: safe authenticated state/cancel endpoints, password authorization for replacing a verified number, persisted recovery, existing atomic ownership proof. Keep the old number until provider proof commits. Reuse existing attempt row and cooldown; cancellation clears proof without resetting budgets.
- Extend `IprogSmsOtpService`; add `ClinicSmsMessage` for the two fixed full messages and GSM-7 unit validation. Add ordinary SMS submission at the same provider boundary. Never log provider payloads. Dashboard template automatic behavior is undocumented; handset acceptance must verify no duplicated header.
- Share existing locked send budgets in `SmsSendBudget` between OTP and grooming. Preserve defaults; expose configurable daily limits rather than arbitrarily reducing clinic capacity.
- Add one forward `grooming_completion_sms` outbox migration, `GroomingCompletionSmsService` and `SendGroomingCompletionSms` database job. Unique booking event, post-commit dispatch, pending-event scheduler recovery, durable pre-network claim, no automatic resend after ambiguous acceptance. Store event IDs and safe states, not phone/code/message payloads. Recheck authoritative linked customer and verified phone at delivery.
- Record the SMS event inside `AdminBookingController::completeGroomingBooking`, the common bulk/per-pet/canceled-remainder transition. Remove obsolete grooming email class/templates and update its consumers/tests. Keep existing in-app notifications.
- Extend lazy `customer-phone-verification.js`, `api.js`, `client-settings.js`, Settings phone header and existing verification CSS. Recover on explicit open only; no polling or implicit SMS. Settings stays in place; preregistration continues its original destination. Reuse one profile refresh and relevant cache invalidation.

## Execution and verification

1. Capture relevant baseline tests. Add failing PHP cases for state recovery, cancellation, replacement password/proof, branded OTP and message encoding. Implement until green.
2. Replace obsolete grooming email coverage with whole-visit SMS tests: actual dynamic names, multi-pet final transition, canceled remainder, authoritative walk-in links, verified eligibility, duplicate workers/events, transaction rollback, provider failure/uncertainty and durable database queue.
3. Add frontend behavior regressions for shared modal recovery, canceled late responses, Settings actions, successful profile updates and original navigation. Update obsolete assertions in existing suites.
4. Run affected PHP/JS suites, relevant lifecycle/cache/auth regressions and production frontend build. Inspect Settings visually in the existing customer browser only. Document exact queue/scheduler setup from repository deployment docs, forward migration, flags, final GSM-7 counts and manual handset acceptance.

## Review focus

- Replacement resend must use the prior password authorization only while original authoritative phone ownership remains unchanged.
- Cancel/expire/timeout must retain old ownership and account attempt/cost limits.
- Worker crash after submission must never cause another automatic paid submission.
- Last outstanding pet canceled after others finish must produce the same single completion event.
- GSM extension characters cost two units; Unicode, control characters and overlong names need the fixed fallback.

## Completion

Implemented all four steps. See `docs/customer-phone-verification.md` for final checks, local migration evidence, baseline test limitations, provider acceptance and Hostinger release requirements. User-owned manual functional and real-handset acceptance remains pending.
