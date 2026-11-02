# Multi-browser timeout investigation — 2 November 2026

## Outcome

The underlying cause of the reported approximately 09:00 Philippine-time incident is **not established**. No application fix, authentication-policy change, timeout increase, hosting change, commit, push, or deployment was made.

A fresh-connection failure was reproduced from this PC during the investigation, without signing in or running six accounts. DNS succeeded, but TCP connection establishment did not complete within the probe's five-second connection budget. TLS and HTTP were never established in those probes. This identifies a failure before Laravel for those particular attempts; it does not prove that every reported API timeout had the same cause.

The observations do not distinguish endpoint filtering, PC/network software, router/ISP routing, or CDN-edge connectivity. They do not prove an authentication, database, IPv6, VPN, or Hostinger-capacity defect.

## Revision and scope

- Local branch: `gerald-frontend`.
- Local HEAD and the backend remote's branch tip both matched `7c44634fbd91b3b063ab5a1b4eb2fc82cda34973` (`remember me`). Verified using read-only `git ls-remote` against `rij-summit/bethlehem-grooming-system`.
- Production: `https://lightblue-cheetah-456500.hostingersite.com`.
- Production's deployed backend revision was not independently verified.
- The user supplied an approximate incident time of 09:00 PHT on 2 November: approximately 01:00 UTC. Treat this as an approximate window, not an exact request timestamp.
- Production activity was limited to read-only settings/file listings and a small number of unauthenticated GET probes. No OTP emails, sign-ins, activity mutations, account creation, or production load test was initiated.

## Connection measurements

Windows curl 8.21.0 was run outside the restricted shell sandbox. Initial sandbox DNS failures were excluded from the network findings. Each production probe used `--connect-timeout 5 --max-time 12`, certificate validation enabled, no credentials, and no automatic retries. Five seconds is this diagnostic's connection budget, not a change to the frontend's twelve-second timeout.

| Probe | DNS | TCP/TLS | HTTP result | Total |
| --- | ---: | --- | --- | ---: |
| Initial homepage GET | 51.017 ms | Not established | None; curl 28 | 5.014893 s |
| IPv4 `/api/session` | 8.186 ms | Not established | None; curl 28 | 5.008140 s |
| Homepage pinned to DNS answer `88.223.91.61` | Bypassed | Not established | None; curl 28 | 5.009944 s |
| Homepage pinned to DNS answer `91.108.119.93` | Bypassed | Not established | None; curl 28 | 5.015679 s |
| `/api/session` pinned to `153.92.12.218`, 11:40:24.296 PHT | Bypassed | Not established | None; curl 28 | 5.012206 s |
| `/api/session` pinned to `88.223.91.34`, 11:40:29.369 PHT | Bypassed | Not established | None; curl 28 | 5.007935 s |
| Final homepage GET, 11:43:27.675 PHT | 121.757 ms | Not established | None; curl 28 | 5.009797 s |
| Control: `https://github.com/` | 16.831 ms | TCP 88.982 ms; TLS complete 320.212 ms | 200; first byte 397.045 ms | 5.274639 s |

Pinned probes preserved the production hostname for TLS SNI and certificate validation using curl `--resolve`; they did not connect to a guessed origin or bypass the CDN. The CDN DNS answers changed between measurements. Both earlier and later returned IPv4 addresses failed in these bounded attempts, so a single stale DNS answer does not explain the measurements.

The control's total includes downloading its homepage; its first-byte timing is the relevant comparison with connection establishment. Curl's `time_connect` and `time_appconnect` were zero on failed attempts. Its nonzero `time_starttransfer` field on those failed attempts must not be treated as evidence of an HTTP response.

An IPv6-only curl attempt failed with exit 6 before a usable connection. Windows DNS separately returned AAAA records, and no IPv6 default route was returned by the route check. This is an inconclusive IPv6 test, not proof that IPv6 caused the browser incidents. IPv4-only probes also failed.

### Browser evidence and limitations

The existing Edge sign-in document retained an earlier successful navigation: HTTP/2, 254 ms navigation duration, request start 16.6 ms and response start 157.2 ms relative to navigation. This is historical evidence from that document, not a fresh successful API request or server execution timing.

A fresh Edge navigation and an instrumented reload encountered browser-automation timeouts and a paused document response. These attempts are **inconclusive** and are excluded from the network-failure measurements above. Computer Use then stopped because it could not establish the current browser URL confidently. No further UI input was sent. Browser cleanup could not be verified; an empty diagnostic tab or pending reload may remain. No credentials were entered.

### PC observations

- WinHTTP reported direct access. User proxy enablement and automatic proxy-script presence were false; no proxy environment variable names were returned.
- Ethernet and a Radmin VPN virtual adapter were up. IPv4 default routes existed through both, with Ethernet's lower combined route/interface metric. Adapter presence alone does not prove VPN traffic interception or causation. No adapter, route, proxy, VPN, security-software, DNS, or browser setting was changed.
- No packet trace or browser NetLog from the original failure was available. Browser-specific proxy behavior, filtering drivers, connection reuse failures, and endpoint security remain unverified.

## Application tracing

The requested files and their immediate request, middleware, controller, service, route, and regression-test dependencies were inspected.

### Request timeout and concurrency

- `scripts/api.js` uses a 12,000 ms AbortController timeout around `fetch`. It does not automatically retry OTP confirmation.
- The timeout is cleared after fetch resolves response headers; response JSON is parsed afterward. Consequently, the existing timeout does not cover a stalled response body. This is a code-path limitation to investigate if a failing trace shows headers arriving and the body stalling; no such production trace was obtained, and it was not changed speculatively.
- A queued session check is shared through `sessionSync`; ordinary focus and visibility events reuse it. Polling schedules its next 60-second check after completion and skips hidden documents.
- Admin GETs share outstanding endpoint requests through `adminRequests`. Notification counts are fresh for 30 seconds, share the admin cache, and are reused from fuller notification responses.
- `admin-notification-service.js` coordinates subscribers through one 30-second count timer, skips hidden/locked contexts, and removes its listeners/timer when its last subscriber leaves.
- `admin-sidebar.js` shares its outstanding appointment-count request and skips hidden/locked polling. This is a separate functional poll, not another notification count implementation.
- Explicit activity reports are throttled to ten seconds between starts. The timer is not an in-flight request guard, so a slow activity request can overlap its successor. This observation does not establish an abnormal production burst or explain pre-TCP failures. No activity behavior was changed.
- `withAuthTransition` uses the browser profile's Web Locks API. Independent browsers/private profiles do not share that JavaScript lock; it is not a PC-wide authentication mutex.

### Server transactions and authentication limits

- `EnsureSessionIsActive` briefly locks the requesting token; mutations also lock the requesting account and retain the transaction through their controller. Normal safe GET controller work runs after the middleware transaction. This preserves ordering against session replacement, and independent accounts do not deliberately share an account-row lock.
- `/api/session` and `/api/session/activity` return existing session state. Sidebar revision hashing uses loaded user attributes.
- Notification `mode=count` performs a scoped unread COUNT rather than loading notification history and relationships.
- OTP confirmation uses an immediate `Cache::lock(...)->get()` keyed by source IP. Failure to acquire returns `429 login_code_busy` with a one-second retry hint; it does not call a blocking lock wait. This does not exclude delays inside the production cache/database driver, which require server evidence.
- Incorrect OTP counters include account and IP budgets. Successful verification does not clear the shared IP failure budget. The route also has a ten-per-minute confirmation throttle. These can produce legitimate 429 responses; none is evidence that a TCP timeout is an application throttle.
- Challenge consumption and account-session issuance are inside one transaction. `AccountSessionService::issue()` locks the account and marks previous sessions replaced before issuing the new session. This implementation was preserved.
- No intentional sleep, external mail delivery call, or remote HTTP request was found in the inspected confirmation transaction. Production lock waits, cache-store latency, database execution times, and PHP worker queue delays were not measurable with the available tools.

### Interrupted OTP confirmation

The transaction can commit before its HTTP response reaches the browser. A timeout therefore cannot tell the user whether confirmation committed. Existing single-use challenge consumption prevents replay from issuing another session; a repeated confirmation receives an invalid/consumed challenge result. The frontend does not automatically resend confirmation, and a failed confirmation retains its pending flow except for existing terminal responses.

This investigation did not establish whether any reported OTP timeout committed. Determining that requires correlating the affected account's challenge consumption, token creation/replacement, transaction outcome, and response delivery at the incident timestamp. Do not log OTP codes, raw poll tokens, passwords, session cookies, or bearer secrets. No recovery endpoint, automatic retry, extra session issuance, or authentication-policy change was added.

## Controlled local checks

Existing regression harnesses were used with mocked responses and fake time. A temporary, non-persisted diagnostic invoked the existing inactivity harness with one, two, and six independent contexts. These are lifecycle simulations, not real Brave/Opera/Edge sessions or Hostinger performance measurements.

| Context count | Initial session checks | Checks over two visible idle minutes | Checks over ten hidden minutes | Checks when focus + visibility return together |
| ---: | ---: | ---: | ---: | ---: |
| 1 | 1 | 2 | 0 | 1 |
| 2 | 2 | 4 | 0 | 2 |
| 6 | 6 | 12 | 0 | 6 |

The six contexts represented admin, three staff contexts, and two customers with separate storage and token identifiers. These checks measured session lifecycle requests only. They do not include full dashboard loading, notification polls, real socket concurrency, database contention, browser throttling during real sleep, or OTP with other live sessions active.

### Regression results

- `node tests/session-inactivity-regression.cjs`: passed.
- `node tests/frontend-auth-session-regression.cjs`: passed.
- `node tests/admin-notification-center-regression.cjs`: all 14 named cases passed, including shared polling/lifecycle and count reuse.
- PHP 8.4.26: `vendor/bin/phpunit tests/Feature/IndependentAuthenticationSessionsTest.php tests/Feature/SessionLockTest.php tests/Feature/EmailVerificationTest.php tests/Feature/AdminNotificationCenterTest.php`: **270 tests, 4,007 assertions passed**, 59.087 seconds.
- PHPUnit uses its forced testing configuration: isolated SQLite, array session/cache, non-delivering mail configuration. Existing explicit overlap tests use disposable temporary SQLite databases. These results do not reproduce production MySQL row/gap locks, production cache locks, or PHP worker queuing.

The restricted shell initially prevented portable PHP from reading the checkout; the successful run used approved access outside that sandbox. That initial bootstrap error was environmental, not an application test failure.

## Hostinger diagnostics

Read-only Hostinger settings reported SSL active, HTTPS redirect enabled, CDN enabled, and server cache disabled. These settings do not establish CDN health or rule out edge filtering.

The available connector could list website settings and document-root entries, but searches did not expose shared-hosting PHP access/error logs, PHP slow logs, request metrics, or database lock diagnostics. A `storage/logs` listing relative to the reported document root failed because that directory was absent there; this does not prove Laravel has no logs elsewhere. The connector's directory-listing HTTP 500 is a management API error, not a production website 5xx response. No files, production configuration, or data were modified.

The earlier user-reported low resource utilization and lack of origin 5xx responses are consistent with a request failing before origin, but are not proof. No incident-time access log, PHP execution trace, database lock trace, or CDN-edge log was available to correlate with the new probes. An independent web-fetch service could not retrieve the URL either, but returned only a generic tool error; that result is not counted as a measured network failure or a mobile-network comparison.

## Missing evidence and next steps

1. Run the same small GET timing probe while comparing this PC on its current network, this PC on a phone hotspot, a phone on the same Wi-Fi/router, and a phone on mobile data, close together in time. Record exact timestamps and current DNS answers. This separates device-specific behavior from network-path behavior. The earlier mobile results were not contemporaneous with these probes, so they cannot identify why the PC behaved differently.
2. Capture DevTools Network Timing or a browser NetLog during one actual failure in each relevant browser. Establish whether time is spent queued/stalled, resolving DNS, opening TCP/TLS, waiting for headers, or downloading the body. Record protocol, remote address, and exact time. A sanitized HAR can omit sensitive content, but verify it contains no authentication material before sharing.
3. Have Hostinger inspect CDN-edge connection/filtering and origin logs for approximately **09:00 PHT / 01:00 UTC on 2 November**, plus the exact probe times **03:40:24.296 UTC**, **03:40:29.369 UTC**, and **03:43:27.675 UTC**. Supply the affected public client IP privately to Hostinger so they can correlate connection-level failures that have no HTTP request ID. Request findings for the listed destination addresses, TCP acceptance, edge filtering, edge-to-origin routing, origin arrival/completion, PHP queue/execution time, and database lock waits.
4. If an API request reached Laravel, compare origin arrival and completion with browser timing. If an OTP request reached Laravel, separately establish transaction commit and response delivery. Obtain only narrowly scoped, redacted records for the test account and timestamps.
5. Once connectivity is measurable and test accounts are available, repeat one account, two independent normal/private contexts, all six intended contexts, return after inactivity, and OTP confirmation with the other sessions active. Measure complete API counts, durations, connection timings, and server execution timings; retain the existing session-replacement/security regression checks.

No production change is currently justified by a verified root cause. Do not disable the CDN, weaken protections, alter DNS/VPN/security settings, increase request timeouts, or change authentication locking on the strength of these observations alone. Any proposed hosting change still requires the user's explicit approval.

## Acceptance status and before/after

Existing local authentication and notification regressions pass. No production correction was made, so there is **no before/after improvement measurement**. Successful use of all six real authenticated browser contexts, production wake-up behavior, and OTP response-loss recovery remain **unverified**. The user's mobile-versus-PC difference remains unexplained pending the contemporaneous network comparison and correlated edge/origin evidence.

## Reference material

- [Chrome DevTools Network timing reference](https://developer.chrome.com/docs/devtools/network/reference): distinguishes queueing/stalling, DNS, initial connection, SSL, waiting, and content download; origin execution is only part of end-to-end timing.
- [Hostinger CDN and DNS records](https://www.hostinger.com/support/hostinger-cdn-and-your-dns-records/): describes how CDN enablement changes the addresses through which visitors reach hosting.
- [Hostinger website accessibility troubleshooting](https://www.hostinger.com/support/1583286-what-to-do-if-you-cannot-access-your-website/): includes comparing another device and another network. No suggested setting changes were applied here.
