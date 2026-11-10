# History Navigation and Customer Payment Implementation Plan

**Goal:** Link eligible Pet Profile grooming records to the existing History dialog and show recorded customer payments in History details.

**Architecture:** Reuse `Booking::groomingHistory()` for eligibility in the existing profile query. Resolve URL targets inside normal cached Admin History loading. Keep transaction-return state independent. Extend the existing clinic response with a small payment object and render both customer payment sections using existing portal styles.

**Tech Stack:** Laravel/Eloquent, Alpine, vanilla JavaScript, Tailwind, PHPUnit and Node regression tests.

**Spec:** User attachment `a0829c42-7c72-47fd-a3d5-0176f15b9600/Pasted text.txt`.

## Constraints

- Preserve existing uncommitted profile changes and all lifecycle, eligibility, ownership, snapshot, cache and consent behavior.
- No new endpoints, per-record requests, dependencies, modal duplication or History redesign.
- Customer payments use recorded final totals; omit missing/malformed payments and internal metadata.

## Execution

1. Extend `tests/Feature/AdminPetProfileTest.php` for stable IDs, eligibility across siblings and constant query count; verify failure. Add IDs and query-derived eligibility in `PetProfileHistoryService.php`; generate native URLs in `admin-customers.js` and anchors in `clients.html`; verify focused PHP and customer regressions.
2. Extend `tests/admin-grooming-history-regression.cjs` for direct/old-year/multi-pet/stale targets, cached refreshes, URL cleanup, permissions and transaction return; verify failure. Update `admin-archive.js` initialization and existing dialog rendering in `archive.html`; verify regression coverage.
3. Extend `ClientPetMedicalRecordsTest.php` and `customer-health-history-regression.mjs` for paid cash/noncash, unpaid/malformed, ownership, safe response fields and single/multi-pet totals; verify failure. Extend `PetMedicalRecordController.php` and existing customer History detail builders only; verify backend/frontend tests.
4. Run directly relevant History, dashboard, customer and profile regressions, syntax checks, and production asset build for changed markup. Inspect final diff and report actual verification limits.

## Review Focus

- All siblings determine booking eligibility, including a paid visit before pickup.
- Cached data may render before revalidation; open once without consent blocking or duplicate requests.
- Invalid IDs never choose a sibling; modal scrolling stays inside the dialog.
- Returning from Transactions restores filters, modal and scroll before applying URL targeting.
- Clinic tendered cash and current catalogue prices never replace historical payment totals.

The repository makes planning advisory and authorizes direct execution; no worktree, commit or approval checkpoint is needed.
