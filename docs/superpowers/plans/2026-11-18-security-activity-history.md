# Security activity history implementation plan

**Goal:** Preserve truthful admin/staff incident history with explicit recognition, password resolution, accessible compact rows, and five-record load more.

**Architecture:** Keep incident state and detection in AccountSecurityService/AccountSecurityIncident. Serialize detection, recognition, and password resolution on the account row. Use immutable first occurrence plus ID cursors; return a separately bounded owned focus incident. Preserve existing Security forms and buttons.

**Tech stack:** Laravel, MariaDB-compatible forward migrations, Alpine, Tailwind, PHPUnit, Node regression harness.

**Spec:** User's attached Pasted text.txt, November 18, 2026.

## Constraints and review focus

- Preserve legacy Reviewed records and all existing authentication rules, alert deduplication, retention, and session behavior.
- Never reuse terminal rows. Nullable recognized_at/resolved_at carry distinct meanings.
- Recognition locks the account then selected incident; repeated requests produce no additional audit event.
- Password confirmation updates only unresolved incidents at/before the shared successful timestamp, in the credential transaction.
- Cursor pages contain five entries; focus lookup is owned/retention-scoped and does not affect cursor order.
- Keep disclosure state and existing DOM nodes on append; deduplicate focus entries.

## Tasks

- [x] Add behavioral regressions for recognition, ownership/idempotency, legacy states, same-day history, pagination/focus, and password confirmation boundaries. Run them red against current code.
- [x] Add forward migration and model state/payload; update reduced-schema test setup. Replace daily uniqueness with indexed active-row lookup under account locking. Implement recognition/audits and cursor/focus API. Verify focused PHPUnit tests.
- [x] Integrate resolution in PrivilegedCredentialChangeService::confirm after password persistence, with one shared timestamp and bounded audit metadata. Return the successful resolution timestamp to the retained staff session. Verify failed OTP, username-only, multi-incident, foreign, recognized, and later-incident cases.
- [x] Replace activity rendering with keyed accessible disclosures, concise state timestamps and secondary/primary action group. Load five then append cursor pages; merge focused incident chronologically. Preserve expanded state and focus. Update API methods and obsolete regression assertions. Verify Node regressions.
- [x] Generate required Tailwind CSS, run affected authentication/security suites, inspect desktop/mobile rendering, and review the final diff for scope, security, pagination and deployment risks.

Execution stays in this session under the user's repository guidance; no worktree, commit, deployment, or plan approval step is required.

## Verification

- Latest `backend/gerald-frontend` matched HEAD before implementation.
- A recognition regression failed against the previous endpoint before production changes; the frontend regression failed on missing disclosure state before its implementation.
- Eight affected PHP feature suites: 365 tests, 6,395 assertions passed on PHP 8.4/SQLite.
- New incident-history suite: 10 tests, 124 assertions passed on a generated local MariaDB database, removed by the existing isolated runner. The migration preserves legacy records and can resume after partial DDL.
- Frontend security settings, General settings, intake settings, staff email recovery, activity log, sidebar cache, and credential-policy regressions passed.
- Tailwind CSS regenerated; only relevant utility changes. Existing outdated Browserslist notice remains.
- Actual activity markup/component inspected with synthetic records in the browser at desktop and 320px widths: five initial rows, centered Load more, appended rows, preserved disclosure, Enter/Space activation, recognized/resolved timestamps, action removal and focus after recognition, no mobile overflow.
- Preview server and temporary test files removed. Deployment must apply the forward Laravel migration before serving the changed API. No production data was changed.
