# Remember Me Implementation Plan

**Goal:** Honor the checkbox for every role and restore customer sessions using a separate, revocable device credential.

**Architecture:** Keep the existing Sanctum cookie/public-reference transport, OTP flow, account row lock, session gate and cross-tab synchronization. Customer device credentials have a fixed 14-day deadline and 7-day idle limit. Hash-only credential generations permit rotation, bounded concurrent retries, and confirmed replay revocation without retaining recoverable secrets.

**Scope:** The Remember Me task in this chat; AGENTS.md governs execution. Implement directly on the current branch. No deployment, commits, external infrastructure, or design changes are required.

## Constraints and review focus

- Customer sessions: 30-minute idle / 24-hour absolute. Admin/staff: 30-minute password lock / 12-hour absolute.
- Preserve OTP, password/email throttles, permissions and single-active-session replacement.
- Credentials are HttpOnly, host-only, Secure in production, SameSite=Lax, restricted to the API path; never returned in JSON.
- Serialize account changes before session/device changes. Concurrent restoration retries must return the same new session and cookie, never replace one another.
- Reject stale browser responses, isolate caches by session, gate protected content before verification, and never retry sensitive mutations.
- Offline logout must hide private content immediately and retain a nonsecret revocation-pending marker that blocks restoration until revocation succeeds.

## 1. Backend policy and credential lifecycle

Files: AccountSessionService, EnsureSessionIsActive, UseAccountSessionCookie, SessionController, AuthController, LoginEmailChallengeController, User, API routes/provider; new device model/service/migration.

- [x] Extend IndependentAuthenticationSessionsTest with checkbox/OTP/cookie expectations and restoration/expiry/replay/logout/revocation cases. Run failing focused cases first.
- [x] Centralize idle/absolute calculations; make persistence explicit and default off.
- [x] Add indexed device and credential-generation tables, rotation and revocation under account transactions, bounded duplicate-response derivation, origin/header checks and a separate restore limiter.
- [x] Issue customer devices only after OTP; revoke on full login replacement, logout and account security changes. Allow cookie-bound logout even after session expiry without restoring access.
- [x] Verify existing session lock, OTP, recovery, password cooldown and account management suites.

## 2. Frontend initialization, races and logout

Files: scripts/api.js, directly affected customer initialization callers, protected customer HTML head gates, existing session CSS and sign-in feedback; tests/frontend-auth-session-regression.cjs and existing session/cache regression tests.

- [x] Add failing behavior coverage for role persistence, restoration deduplication, retryable failure, logout pending, delayed responses and account switching.
- [x] Honor the server-confirmed checkbox for every role; retain only public session references in storage.
- [x] Reuse session initialization to verify then restore once; coordinate tabs using Web Locks when available, with authoritative backend concurrency fallback.
- [x] Queue safe reads behind verification; reject mutations during restoration. Gate protected content and cached presentation until authorization, including BFCache resumes.
- [x] Preserve immediate logout and retry unconfirmed revocation before any restoration. Update direct initialization guards to await the shared gate.
- [x] Run focused frontend auth/session/cache regressions and syntax checks.

## 3. Production verification and documentation

- [x] Document migration, HTTPS/origin/API path settings, cleanup, staged rollout and safe rollback in docs/customer-authentication.md.
- [x] Verify migration up/down and production cookie attributes in tests; run related backend/frontend suites and review the final diff for obsolete assumptions and unrelated changes.
