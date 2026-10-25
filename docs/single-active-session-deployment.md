# Single active session security

Successful authentication now replaces earlier sessions for the same user ID across Admin, Customer, Grooming Receptionist, Clinic Receptionist, and Veterinarian accounts. Password checks and pending/failed/expired email codes do not replace sessions. Registration verification and staff password setup use the same session issuer.

## Enforcement and browser behavior

- Sanctum remains the authentication authority. `AccountSessionService` locks the existing account row, marks earlier token hashes as replaced, and issues a fresh random credential in one transaction. The account/token indexes already exist; no session-management table or new dependency is required.
- The existing session middleware checks replacement before protected controllers. Mutating requests hold the account lock through the controller, ordering their database changes against takeover. A write already accepted before takeover finishes before the new login commits; a stale write arriving afterward receives HTTP 401 and cannot execute its controller. Read-only controllers retain their existing short validation transaction.
- The optionally authenticated `/api/timeslots?booking_id=...` forecast now uses the same authentication, account and session middleware before fetching booking data. General public availability keeps its existing public route and response. Its former manual Sanctum-only check was removed.
- Replaced token hashes remain identifiable until normal expiry/cleanup; they cannot authorize protected actions. Existing Sanctum pruning and per-account cleanup during login bound retention. Session status does not renew idle or absolute deadlines.
- The credential travels only in the host-only `bethlehem_account_session` HttpOnly cookie, scoped to `/api`, SameSite=Lax, and Secure on HTTPS. The existing JSON `token` field now contains only `<row ID>|cookie`, a public identifier that cannot authenticate without the cookie. This preserves cache ownership and frontend API compatibility without exposing the secret.
- Cookie requests bind the identifier to the cookie and require the existing shared client's custom request header and trusted origin. Public browser mutations also require this header, preventing login CSRF. Webhook authentication remains unchanged. Raw bearer compatibility is retained for existing sessions and non-browser clients, with the same replacement enforcement.
- Old frontend credentials are removed from browser storage on initialization. The existing read-only session check upgrades them to the HttpOnly cookie without issuing another session or extending deadlines. Legacy Remember Me metadata is sent only during this upgrade.
- Protected HTTP failures, tab focus, visibility changes, BFCache restoration, and the existing visible-page 60-second session check detect replacement. No new polling loop or full-account fetch was added. Network errors do not produce replacement notifications.
- A server-confirmed replacement stores a non-sensitive reason in the affected tab's sessionStorage before redirecting. The existing sign-in page displays the supplied security message in a persistent Phosphor alert. It survives refresh and is removed by dismissal or another login. Matching cross-tab events propagate the reason; stale events cannot erase a newer login or OTP challenge. Manual logout and ordinary expiry do not show this alert.

No new medical, account, or unsaved-form data is persisted. Existing drafts and authentication caches follow their existing sign-out cleanup.

## Migration and configuration

Required migration: `2026_10_25_000001_add_session_replacement.php` adds nullable `personal_access_tokens.replaced_at`. It preserves every existing token row and each account's newest session, marking older sessions as replaced. Account and business data are unchanged. Its down migration removes the column without deleting rows. Deploy code and schema together during maintenance so old application workers cannot issue sessions outside the new policy during rollout.

Local configuration was inspected: MySQL and Laravel's database session driver are configured. This feature uses the existing persistent Sanctum token database, independently of Laravel's web-session driver. On Hostinger, account/token tables must use a transactional engine with row locking (normally InnoDB); all application workers must share that database. No Redis, WebSockets, worker, or scheduler change is needed.

For the existing same-origin HTTPS production site, no new environment variable is required when `APP_URL` already matches the canonical HTTPS origin. Cookie domain remains host-only; changing `SESSION_DOMAIN` is unnecessary. If frontend and API use different origins or the site serves additional origins, set `FRONTEND_ORIGINS` to their exact comma-separated trusted origins and rebuild the config cache. Credentialed CORS no longer permits wildcard origins. Cross-site frontend/API domains are not supported by the SameSite=Lax cookie; use the existing same-site deployment. A separate local frontend port should use the same hostname as the API and be explicitly allowlisted. Serve the frontend over HTTP, not `file:`.

## Additional release steps

Use the site's existing release procedure and verified Hostinger PHP CLI, from the Laravel project root. In addition to publishing the changed PHP, raw scripts/styles, versioned HTML, and normal build assets:

1. Enter maintenance before activating the new code: `php artisan down --retry=60`.
2. Activate/upload this release using the normal deployment process.
3. Apply the schema and existing-session normalization: `php artisan migrate --force`.
4. Refresh cached configuration/bootstrap using the normal production command: `php artisan optimize`.
5. Refresh PHP opcode caches through the existing Hostinger deployment procedure, then run `php artisan up`.
6. Reload already-open browser pages. Confirm with two separate browser profiles: the second complete OTP login remains authenticated; the first receives the alert and cannot submit a protected change. Confirm a different account remains signed in.

Do not run a migration rollback to restore a session that was replaced; the account can authenticate normally again through the existing login and OTP flow. Unapplied migrations and same-origin/cookie behavior should be checked in staging before the production release. No production environment values, database, or hosting settings were accessed or modified for this implementation.

## Verification

- Full PHP suite, PHP 8.4.26: **1,627 passed, 8 existing environment-dependent skips, zero failures; 21,567 assertions**.
- After final review added protection to the optional booking forecast, its affected booking, session and authorization set passed: **268 passed, 1 existing environment-dependent skip; 4,530 assertions**. The full-suite numbers above describe the preceding full run.
- Frontend suite: **79/79 regression scripts passed**. Shared authentication, inactivity, and navigation-performance checks were rerun after final frontend adjustments.
- `npm run build`: passed. Existing Browserslist data-age warning remains; dependencies were not changed.
- Overlapping OTP confirmations ran in separate PHP processes against an owned disposable SQLite database. Both authentication requests succeeded; only the second session remained active. Failed/numeric incorrect/expired OTP, isolation across accounts, all five roles, rejected mutations, cookie binding/CSRF, migration preservation, HTTPS cookie attributes, and unchanged inactivity are covered.
- Existing multi-session expectations in the authentication tests were updated to replacement semantics. Existing registration, staff setup/reset, permissions, lock/unlock, logout and account-state coverage was preserved. The inventory concurrency fixture now sends the same CSRF header as the real client; its contention, stock and ledger assertions are unchanged.
- Edge visual inspection: alert visible with no horizontal overflow at 320, 375, 414 and 768px; persists across refresh; keyboard dismissal returns focus to the identifier field and stays dismissed after refresh; typing begins another login and clears the notice.

Hostinger HTTPS, proxy behavior, storage engine, and MySQL concurrency were not exercised against the live environment. The concurrency evidence is SQLite plus inspection of the account-row locking and MySQL-compatible migration. Core Web Vitals were not measured; existing fetching/cache/request-budget regression tests passed. No commits, pushes or deployment were performed.
