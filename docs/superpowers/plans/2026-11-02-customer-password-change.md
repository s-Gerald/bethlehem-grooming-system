# Customer Password Change Implementation Plan

**Goal:** Implement the requested authenticated customer password change, including complete credential revocation and accessible sign-in feedback.

**Architecture:** Reuse the protected API, account-first session locks, Laravel hashing, User remembered-device revocation hook, durable security notification, and shared frontend sign-out/cache machinery. Preserve bcrypt (effective local configuration: driver bcrypt, cost 12, verification enabled, no configured byte limit); explicitly reject new values over 72 UTF-8 bytes. Screen locally predictable values and padded HIBP ranges with bounded timeouts and fail closed.

**Scope:** User's secure customer password-change specification in this chat. No branches, commits, deployment, migrations, global hashing changes, or unrelated policy rewrites.

## Implementation and verification

- [x] Add `tests/Feature/CustomerPasswordChangeTest.php`: actual cookie sessions, positive/negative validation, role/account/session authorization, targeting rejection, account/IP limits, replay, recovery/login challenge invalidation, device restoration rejection, sign-in and queue failures. First confirm missing endpoint fails.
- [x] Add `CustomerController::changeOwnPassword`, protected `POST /customer/password`, named throttles, and `app/Rules/CustomerPassword.php`. Recheck persisted account/token under account-first lock; verify current password before network screening; persist hash, revoke tokens via bulk deletion, use existing model device hook, delete recovery and login challenges; queue security notice after commit with safe failure logging.
- [x] Guard login challenge issuance against an obsolete pre-change password verification under its existing account lock; verify stale snapshot cannot issue a challenge.
- [x] Extend existing frontend behavioral harnesses for submission/loading/errors, no mutation retries, cache clearance, restoration blocking, cross-tab sign-out, delayed/background responses and browser Back behavior. Confirm missing API/form submission fails before implementation.
- [x] Connect existing Settings form; synchronize 15-character/72-byte rules, field errors and accessible progress. Reuse sign-in feedback with exact requested success copy and Sign in again action. Version changed shared assets and rebuild existing CSS only if needed.
- [x] Run focused PHP and JavaScript authentication, session, password recovery, customer Settings and phone suites; inspect diff and document deployment checks and remaining policies.

## Review focus

1. Overlapping requests must recheck a persisted usable token after the account lock, so at most one succeeds.
2. Late reads/restore responses must not repopulate private caches or override sign-out/newer login.
3. Whitespace and Unicode must survive request preprocessing; reject bytes beyond bcrypt's capacity without truncation.
4. HIBP failure must not mutate credentials or expose lookup values in logs.
5. Queue failure after commit must preserve successful change and log only a safe event/account identifier.

Verification: 276 PHP tests passed; one existing MySQL phone migration case skipped; 3,867 assertions. Six focused frontend regression scripts and CSS compilation passed. Visual browser acceptance and live Hostinger checks remain unverified as recorded in docs/customer-authentication.md.
