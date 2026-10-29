# Authentication Security Implementation Plan

**Goal:** Isolate login challenges, restrict abusive sources, share purpose-aware mail quotas, and let account owners review private security incidents.

**Architecture:** Extend the existing Laravel challenge, RateLimiter, encrypted database mail queue and activity recorder. Keep private incidents separate from operational notifications and reuse Settings > Security. No deployment or live infrastructure access.

**Tech Stack:** Laravel 12, PHP 8.2-compatible application code (PHP 8.4 tests), MySQL/SQLite, persistent database/Redis cache, vanilla JavaScript and Alpine.

**Spec:** User attachment `Pasted text.txt`, authentication hardening priorities 1, 3, 4 and 5; user reserves manual localhost navigation and form testing.

## Constraints and review focus

- Preserve password-change OTP, current password proof, role authorization, session replacement and remember-me behavior.
- Never send actual test email or log OTPs. Tests use isolated SQLite and fake/array mail.
- Existing valid challenges survive other browsers' issuance, resend, failure and storage limits.
- Account/source and IP counters must serialize; new challenges/resends cannot reset them.
- Purpose limits remain subordinate to aggregate destination/source limits; public responses must hide destination history.
- Deduplicated private incidents must survive notification failure without revoking sessions or granting privileges.
- Owner-scoped activity and acknowledgment require a fully authenticated, usable session.

## Tasks

- [x] Extend existing EmailVerificationTest and AuthenticationEmailPolicyTest with failing behavior regressions. Run the smallest new cases before production edits.
- [x] Add forward-only indexed challenge migration, persistent per-challenge failure counts, secure token-based resume, independent issuance/resend and bounded cleanup. Preserve transactional queue insertion and delivery guards.
- [x] Implement configurable challenge 5, account/source 5, source 25 and account 20 failures per 900 seconds; serialize shared counters, keep accurate 429 deadlines, clear only account/current-source counters on success.
- [x] Extend AuthenticationEmailPolicy with registration, login, recovery, privileged-change and warning purposes; apply to all directly related mail issuers and restore reservations on queue failures.
- [x] Add minimal private incident model/migration/service and queued warning notification. Aggregate daily incidents, detect repeated failures and corroborated multiple sources, cap warning attempts to one per rolling day, retain incidents 90 days with bounded pruning.
- [x] Extend owner Security endpoints and existing Settings component/HTML. Add one private post-login inline alert through the existing authenticated identity load; no polling. Review focuses an incident; Secure account focuses the existing password form.
- [x] Update existing schema fixtures and obsolete quota/attempt expectations. Verify replay, contention, queue failure, privacy, credential OTP, remember-me and frontend behavior with focused PHP/Node suites; run build and diff checks.
- [x] Document exact local results, safe local configuration, pending manual browser checks, forward migrations, cache/queue requirements and future Hostinger/CDN/WAF steps with primary-source references. Do not deploy.

The user has already authorized local implementation; this plan is executed in the current workspace without a new worktree or commit.
