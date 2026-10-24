# Staff Profile Editing Implementation Plan

**Goal:** Let active staff edit their own names and username inline in Settings → Security.
**Architecture:** Extend the existing security controller and Alpine component. Validate username changes on submission, then require the current password for an atomic update; reuse the existing identity cache and sidebar presentation.
**Tech Stack:** Laravel/Sanctum, Alpine, Tailwind, PHPUnit, Node regression harnesses.
**Spec:** User attachment `Pasted text.txt`, staff profile editing task (October 24, 2026).

## Constraints

- Preserve administrator credential changes and staff password email OTP.
- Only first_name, last_name, username may change; authenticated staff owns the target.
- No polling, operational cache invalidation, full staff fetch, or page reload.
- Preserve existing card styles, role permissions, session, permanent user_id and entered data on errors.
- Browser interaction is limited to visual inspection; user performs functional browser testing.

## Implementation and verification

1. Extend `tests/Feature/AdminCredentialChangeTest.php` with profile authorization, validation, name-only update, password-gated atomic username update, case-only changes, conflicts and no-op coverage. Run with PHP 8.4; confirm missing routes fail first.
2. Extend `AdminSecurityController.php` and `routes/api.php`. Consolidate common username rules/checks in `app/Support/AccountUsername.php` for profile editing, `PasswordResetService.php` staff setup and `PrivilegedCredentialChangeService.php`, preserving the administrator reservation policy. POST `/settings/security/profile/validate` validates without saving; PATCH `/settings/security/profile` saves the authenticated account. Reject unknown fields, lock and recheck the account and uniqueness, map unique-key conflicts to username errors, preserve tokens.
3. Extend `admin-settings.js` and `settings.html` with read/edit/confirm states, field errors, disabled/no-op/busy handling, cancellation and password clearing. Extend the existing Node regression harness to exercise behavior before implementation.
4. Extend `api.js` and `admin-sidebar.js` to apply returned identity, persist it, supersede stale identity requests and update sidebar without fetching operational data. Extend the cache harness to cover navigation, session races and stale responses.
5. Run the focused PHP security/credential/validation tests and Node security/sidebar/cache regressions, JavaScript/PHP syntax checks and CSS compilation. Review the final diff; leave browser submissions to the user.

## Review focus

- Case-only username changes require password; duplicate checks exclude the current account.
- Confirmation cancellation retains edits and clears password; full cancellation restores saved values.
- Failed confirmation cannot save names or username; changing edited values invalidates confirmation.
- A late GET /me cannot restore old sidebar identity; session switches cannot accept another account's update.
- Concurrent username claims use existing unique indexes and locked revalidation; conflicts leave all fields unchanged.

## Outcome

- Implemented all steps in the current checkout. Browser inspection covered the read-only and editing card without entering data or submitting changes.
- Shared validation preserves the staff setup exception for legacy reservations under the same email and keeps administrator behavior intact.
- Confirmed local MySQL `users.username` and `pending_customer_registrations.username` use `utf8mb4_unicode_ci`; `users_username_unique` is a unique B-tree index. No migration is needed.
- Added coverage for name-only updates under all three staff roles, password-gated/case-only username changes, preflight, no-op/cancel/duplicate-submit behavior, existing/pending reservations, protected fields, session/cache races, and real unique-constraint rollback.
- Final verification: 114 focused PHPUnit tests / 1,693 assertions passed; nine frontend regression scripts passed; JavaScript/PHP syntax checks and `git diff --check` passed. `npm run build:css` passed with the existing outdated Browserslist data warning. No full-suite or production Vite build was needed for this scope.
- Deployment: include the new support class and updated PHP/HTML/JS/CSS in the normal deployment; rebuild route/autoload caches if enabled. No new dependencies, configuration or database migration.
