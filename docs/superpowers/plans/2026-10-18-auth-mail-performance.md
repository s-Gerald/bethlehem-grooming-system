# Authentication mail performance and verification UI

Preserve Brevo, encrypted Laravel notification jobs, authentication security,
existing URLs, role redirects, and queued routine notifications.

1. Extend `EmailVerificationTest` with real database-queue/array-transport tests
   for immediate acceptance without a worker, fallback after transport failure,
   retry exhaustion, and suppression after replacement/expiry. Run red first.
2. Keep `AuthenticationMailDelivery` and its middleware as the shared delivery
   boundary. Add opt-in immediate delivery with encrypted database retry fallback,
   safe timing logs, issuance guards, and terminal failure callbacks. Route reset
   and staff setup mail through that boundary. Keep default queue compatibility.
3. Reuse `VerificationCode.bind` for registration and login. Remove the local SMS
   simulation. Mask email, show concise errors only, use absolute resend deadlines
   persisted across reloads, and honor server `Retry-After`/`resend_after` values.
4. Scope visual changes to the verification page and preserve other OTP consumers.
   Check desktop/mobile rendering, focus, paste, disabled controls, and redirects.
5. Run affected PHP and JS regressions and CSS generation if needed. Document
   Hostinger immediate-mode activation versus supervised-worker operation, safe
   rollout of old jobs, timing interpretation, and real-inbox testing limits.

No live hosting changes or real email sends are part of this implementation.

## Verification outcome

- 138 focused backend tests passed (1,784 assertions), using isolated in-memory
  SQLite and the array mail transport. Cached local configuration was bypassed.
- Authentication/session, signup verification, email delivery, and inactivity
  JavaScript regression scripts passed. Changed PHP files passed syntax checks;
  changed JavaScript parsed successfully; `git diff --check` passed.
- Immediate mode persists encrypted work, attempts mail after the response, and
  leaves a deduplicated cleanup/retry job for the existing cron. Authentication
  retry status, stale/expired issuance, lock contention, legacy links, role
  redirects, reset/setup flows, throttles, and sanitized timing logs are covered.
- Browser-based visual verification is still pending: the session browser could
  not reach the local preview and rejects workspace-file URLs. No screenshot
  comparison, rendered desktop/mobile QA, or real inbox latency is claimed.
