# Clinic Cases Status Separation Implementation Plan

**Goal:** Separate expected arrivals from clinical cases without changing intake or lifecycle rules.

**Architecture:** Keep `blocking()` for duplicate prevention. Restrict `operational()` to checked-in, consultation, and billing cases; expose today's pending pre-registrations through `arrivals` on the existing cases endpoint. Return a pending pet registration as `arrival`, separately from `case`.

**Tech Stack:** Laravel/Eloquent, Alpine.js, existing Tailwind styles, PHPUnit with in-memory SQLite, Node regression harnesses.

**Spec:** User request in this chat. Local `gerald-frontend` only; no branches, commits, pushes, deployments, or production database access. No migrations needed. Preserve today's arrival filtering and the default Active view.

## Implementation and verification

1. Add failing behavioral regressions in `ClinicAdministrationAuthorizationTest.php` and `admin-clinic-patient-profile-regression.cjs` for disjoint collections, pending conflicts, profile lookup, immediate check-in counts, cancellation, and expiry. Run the focused PHPUnit and Node tests to confirm the missing separation.
2. Update `ClinicAppointment.php` scopes and `AdminClinicController.php` queries/responses. Keep blocking, queue assignment, capacity, and authorization rules intact. Verify active-case consumers never receive waiting patients; pending registrations still block duplicate intake with `pre_registration_exists` and `arrival`.
3. Update `admin-clinic.js` and `clinic.html`: Expected → Active → Confined → For Payment; reuse row and action styles, expose arrival window, consume separate arrivals, and update local state after successful check-in. Update affected existing regression expectations.
4. Run relevant backend lifecycle, confinement, capacity, authorization, billing and frontend clinic regressions. Check responsive layout locally where tooling allows; inspect the final diff and branch. Report manual browser checks and any limitations.

## Review focus

- Previous-day unfinished clinical cases remain visible; yesterday's unused registrations expire.
- Future pre-registrations stay outside today's Expected list but continue blocking duplicate intake.
- Failed check-in leaves the arrival and counts intact and assigns no queue number.
- Pending-registration races in the profile show Check In rather than opening a clinical workspace.
- Confined patients and payment cases retain their existing workflows and counts.

## Completed verification

- 295 clinic authorization/lifecycle, confinement, queue/capacity, service billing, and treatment tests passed (3,219 assertions).
- 30 dashboard, clinic reporting, and customer medical-history tests passed (280 assertions).
- Eight focused clinic frontend regression scripts passed; JavaScript syntax and `git diff --check` passed.
- Real row markup and component code checked in a localhost browser preview with synthetic data at desktop and 390px mobile widths, including check-in/count changes and overflow-menu cancellation.
- Final authenticated localhost testing with the user's own local data remains for the user. No migrations, commits, pushes, branches, deployment, or production access performed.
