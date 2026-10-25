# Single Active Session Implementation Plan

**Goal:** The newest fully verified authentication replaces earlier sessions for the same account across all five roles.

**Architecture:** Keep Sanctum, its existing token table, inactivity middleware, and shared frontend request/session manager. Serialize session issuance on the account row, retain replaced token hashes until their original expiry to identify the reason, and reject replaced sessions before controllers. Deliver credentials only through an HttpOnly cookie; browser storage contains a public row identifier used for cache ownership and request binding.

**Constraints:** Work on `gerald-frontend`; no commits, pushes, deployment, live database access, production environment changes, new dependencies, or changes to OTP/permissions.

1. Extend existing authentication regression tests with all five roles, failed/pending OTP, replacement, account isolation, cookie security, and rejected modifications. Run before implementation to prove missing behavior.
2. Add a reversible token replacement migration, central successful-login issuance, and cookie transport. Extend existing middleware with replacement enforcement and serialize protected mutations against successful login. Verify login, registration, staff setup, expiration, and logout.
3. Extend the shared frontend handler and existing login screen with one persistent accessible alert. Reuse existing polling, focus, visibility and cross-tab events. Verify network failures, stale responses, redirect deduplication, cookie-only credentials, and unchanged inactivity.
4. Run affected PHP and browser regression suites, inspect build output and diff, and document Hostinger migration/deployment steps and unverified production assumptions.

**Review focus:** Concurrent issuance; mutation/takeover ordering; expired versus replaced reasons; cookie CSRF and stale-tab binding; no polling renewal of inactivity; no raw credentials in JSON/storage; migration compatibility for existing sessions.
