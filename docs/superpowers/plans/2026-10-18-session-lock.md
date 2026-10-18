# Role-specific Session Management Implementation Plan

**Goal:** Admin/staff lock after 30 idle minutes, unlock with their password, and reauthenticate with password/email OTP after 12 hours; customers retain 30-minute logout and a 24-hour maximum.

**Architecture:** Keep existing Sanctum bearer tokens and login challenges. Store interaction/lock timestamps on tokens; shared middleware guards every protected API. Dedicated session endpoints report authoritative state, accept interaction reports, and unlock. The existing browser session manager owns a concealing card without navigating or remounting the dashboard.

**Tech stack:** Laravel 12, Sanctum 4, vanilla JavaScript, existing portal CSS, PHPUnit and Node VM regression tests.

**Spec:** User request in this chat, 2026-10-18.

## Constraints and review focus

- Preserve OTP for all full logins, Remember Me storage, role authorization, manual token revocation, and existing design tokens.
- Never use API traffic or client timestamps as server activity. Late reports cannot revive sessions.
- Serialize competing token state changes; retain original creation time through unlock.
- Conceal dashboards during initial checks, locks, refreshes, and browser restoration; storage messages cannot unlock them.
- Preserve mounted workflow and focus; distinguish incorrect password (422) from expired authentication (401).

## Tasks

1. Add `tests/Feature/SessionLockTest.php`: real tokens, both privileged roles, blocked reads/writes while locked, successful/wrong/rate-limited unlock, logout, background reads, activity forgery, 12/24-hour limits, customer inactivity and independent tokens. Run failing tests before implementation.
2. Add token timestamp migration, `EnsureSessionIsActive` middleware and `SessionController`; register middleware on the entire protected API group. Add cleanup for tokens Sanctum already considers invalid. Update six explicit authentication test schemas to include the migration. Verify focused PHP tests and existing OTP/security tests.
3. Update `scripts/api.js` inactivity manager and add its lock card CSS using portal variables. Add authoritative status/activity/unlock calls, cross-tab notifications, visibility/page restoration checks, trusted input filtering and bounded polling without activity refresh. Update `tests/session-inactivity-regression.cjs` with behavior tests before implementation; preserve customer coverage and run existing frontend authentication tests.
4. Check card keyboard/show-hide/loading/error states, narrow layouts and mounted workflow. Run syntax checks, related PHP/Node regressions, inspect final diff, document migration and verification limits. Do not run migrations against the user's configured database.

## Implementation and verification

All four tasks are complete. Server regression tests use real Sanctum bearer tokens and an isolated SQLite database. The existing OTP, customer Remember Me, password reset, credential change and independent-token tests remain included in the focused authentication suite.

Final results: 145 backend tests passed with 1,792 assertions; session inactivity, frontend authentication and customer-cache Node regressions passed; PHP/JavaScript syntax, focused Pint formatting and Git whitespace checks passed. The delayed-status-after-unlock race has focused regression coverage. Temporary browser fixtures were removed after verification; the configured clinic database remains unchanged.

Browser verification used the real shared JavaScript/CSS with an isolated mock session API, without clinic data: wrong password, loading, show/hide, keyboard focus loop, refresh while locked, two-tab unlock, and preservation of unsaved textarea content all passed. Layouts at 320, 375, 414 and 768 pixels showed no horizontal overflow. Node VM regressions also cover suspended timers, forged activity hints, synthetic events, customer idle logout, absolute expiry, failed logout retries, deduplicated paused reads and blocked mutations.

Deploy the migration before the updated frontend assets: `php artisan migrate --path=database/migrations/2026_10_18_000001_add_token_session_state.php`. The configured clinic database has not been changed. Existing tokens use their original creation time as the initial activity baseline, so previously idle staff sessions lock on their next request and old tokens remain subject to their original absolute deadline.

Activity reports are sent only for trusted user-input events, at most once per ten seconds per tab, with one trailing report when needed. Server receipt time is authoritative; background requests and session status polling never update activity. Visible tabs check status once per minute; browser return checks immediately. Lock and absolute-expiry deadlines also have local timers. Cross-tab notifications only request authoritative server state and never directly unlock a page.
