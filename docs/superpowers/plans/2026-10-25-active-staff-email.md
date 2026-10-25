# Active staff self-service email change

Goal: retain the registered staff email until current-password reauthentication and separate current/new mailbox codes succeed, then revoke that account's sessions.

The user authorizes implementation in the current checkout. Hallmark, writing-plans and TDD support the existing project conventions; no commit, push, deployment or manual browser testing is included.

## Architecture and files
- Add `StaffEmailChange` model, one additive migration and `StaffEmailChangeService`: a unique per-user pending request with session binding, credential/role fingerprint, stage, hashed code, expiration, attempts, cooldown and cancelled state. Preserve attempts/cooldown across replacement/cancellation until the original request expires. Use existing security OTP limits (5 attempts, 60 seconds, configured 10-minute code TTL); overall request lasts two code TTLs.
- Extract `AccountEmail` from Phase 1 and `SecurityCode` from privileged credential changes for current real consumers; keep existing public Phase 1 methods compatible.
- Add a focused controller and staff-only start/confirm/resend/cancel routes. Include pending state in the existing own-account response. Bind requests to real current tokens and recheck eligibility on mutation; lock account → token → pending state, matching session middleware and login completion.
- Add current/new verification and previous-mailbox informational notifications using existing encrypted durable delivery. Queue codes inside transactions; queue the informational notification after commit without undoing the completed change.
- Extend existing settings component/API/HTML with one stage-switching modal, contextual action, compact Resume/Cancel state and explicit Email Updated/sign-in state. Reuse OTP handlers/styles and session clearing/cross-tab logout; reject stale identity reads. No new polling.
- Extend existing account/security/frontend/cache/session tests and fixtures, plus compiled CSS/version references and a release report.

## Execution
1. [x] Add failing backend happy-path/negative tests; implement persistent state and transitions; verify all three roles, unchanged identity, stage/code separation, expiry, resend/cancel/restore, queue rollback and session revocation.
2. [x] Add queue, race and stale-session coverage; verify final uniqueness and superseded queued-code suppression, old-address login/recovery rejection and new-address OTP delivery. Preserve Phase 1 tests.
3. [x] Add failing frontend behavior cases; implement modal/OTP/state restoration/sign-out, focus, loading and stale-response guards; verify actual API/cache/session boundaries.
4. [x] Run affected PHP/frontend suites, production build, migration up/down and isolated route-cache checks. Record exact results and any MySQL, browser or real delivery verification not performed.

## Review focus
- A failed stage transition/resend must preserve the last valid state/code and registered email.
- Duplicate confirmation must not reuse a current-stage code in the new stage or issue another session.
- Cancel/replacement must invalidate queued work without resetting attempt budgets.
- Session takeover, role/password/current-email changes must invalidate pending authority.
- Final API success must reach the success modal before any redirect; other accounts and operational data must remain intact.

Results and release steps: `docs/active-staff-email-change.md`. The additive migration was applied only to the confirmed local development MySQL database; real MySQL races used an isolated disposable database.
