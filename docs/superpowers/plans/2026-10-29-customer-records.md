# Customer Records Implementation Plan

**Goal:** Correct customer archive/restore access state and make customer and pet profiles consistent, compact, and accessible.

**Architecture:** Preserve Laravel endpoints/internal statuses and Alpine components. Add one nullable portal access snapshot, restore manual records in place, and move existing actions into a single header menu. Preserve existing cache invalidation, lazy pet loading, activity observers, and historical relationships.

**Tech Stack:** Laravel 12, PHP 8.4 test runtime, Alpine.js, Tailwind 3 generated CSS, Node regression harnesses.

**Spec:** User attachment `943d0b0f-0ac3-4c95-b842-e1894da83afa/Pasted text.txt`.

## Constraints

- Keep internal API statuses and portal unarchive route compatible.
- Preserve admin-only portal actions; manual archive/restore remains available to admin/staff.
- Unknown legacy archive access restores disabled; deleted records are excluded.
- No production data changes, commit, push, or deployment. User owns manual browser functional tests.
- Preserve Bethlehem colors, typography, visit tiers, individual pet status, search and caches.

## Tasks

1. Add failing archive/restore API regressions alongside existing manual customer tests; cover portal enabled/deactivated/legacy states, authorization, deleted records, token revocation, list transitions, and unchanged linked records. Add nullable `pre_archive_is_active` migration and model cast, transactionally lock portal mutations, and add manual restore route.
2. Add component behavior regressions for allowed actions, repeated-submit protection, failure feedback, nested close/focus/scroll locking, and unsaved edits. Replace placeholder reset UI and bottom action sections with one accessible header menu; retain existing API caches.
3. Rename category labels and short descriptions, distinguish archived record types, move owner/pet headers outside scrolling regions, and reuse visible confirmation styles. Update obsolete interface tests in place.
4. Run focused PHPUnit and Node regressions, PHP/JS checks, production build, and read-only browser visual inspection. Review diff and document migration/deployment requirements.

## Review focus

- Repeated archive/restore cannot overwrite the saved access state.
- Concurrent mutation uses row locks and transactions.
- Restoring customers never changes pet archives or historical transactions.
- Escape affects only the top modal; cancelling and closing restores focus and preserves owner scrolling.
- Long names, narrow screens, and menu focus remain usable without header clipping.

## Completed changes and verification

- Backend: `app/Http/Controllers/CustomerController.php`, `app/Models/User.php`, `routes/api.php`, and `database/migrations/2026_10_29_000001_preserve_customer_archive_access_state.php`.
- Frontend: `pages/admin/clients.html`, `scripts/components/admin-customers.js`, `scripts/api.js`, and `css/custom.css`. Existing Tailwind output regenerated without a content diff; Vite production build passes.
- Tests: existing `AdminUnregisteredCustomerTest.php`, `AdminCustomersCardTypographyInterfaceTest.php`, and `CustomerAccountDeletionMigrationTest.php` updated; `tests/admin-customer-actions-regression.cjs` added for observable component behavior.
- Focused PHPUnit run: 96 tests, 1,597 assertions passed, including adjacent authentication, audit, search, and asset dependencies. Customer list query count remains five for both three and thirteen records.
- Node action, navigation/cache, universal-loading, and clinic-subrole regressions passed. Changed PHP and JavaScript syntax checks passed.
- Read-only visual inspection in the single existing admin tab: desktop page, owner with ten pets, header menu, pet profile, and 375px mobile layouts. Main page overflow corrected; pet tabs retain horizontal scrolling. Nested focus restoration uses `preventScroll` to preserve the owner viewport.
- Build emits the existing non-failing outdated Browserslist database notice. No dependency update is included.

## Migration and deployment

The migration was tested on isolated SQLite fixtures; it has **not** been applied to the running app or production. Apply it before manually testing portal archive/restore, and before making the updated application available during the normal deployment procedure:

```sh
php artisan migrate --path=database/migrations/2026_10_29_000001_preserve_customer_archive_access_state.php --force
```

Use the project's configured PHP runtime (local tests used PHP 8.4.26). Ship the modified static HTML/JS/CSS with the normal frontend assets, and refresh route caches if the deployment uses them. Asset version strings are updated on Customer Records. Legacy archived accounts restore deactivated because their former state is unknown. No production deployment was performed or verified; no commit or push was made. Manual functional testing remains with the user.
