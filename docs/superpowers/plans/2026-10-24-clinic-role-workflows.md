# Clinic role workflows implementation plan

**Goal:** Separate receptionist intake/payment work from clinical work while preserving veterinarian and admin workflows.

**Architecture:** Extend existing Laravel permissions and locked transitions; keep the existing Clinic Alpine components, data caching, deferred records, and payment settlement services. Reuse paid_by_user_id and gateway initiated_by without a migration.

**Scope:** User's attached Clinic workflow requirements; no commits, pushes, deployment, production configuration changes, or manual browser acceptance testing.

## Tasks

- [x] Update existing ClinicAdministrationAuthorizationTest regressions: walk-ins start checked_in with sequential queues and null consultation time; duplicate active visits roll back; receptionist cancellation checks locked status; direct patient edits are forbidden while intake remains available. Preserve vet/admin transitions, reads, and payment tests.
- [x] Update existing clinic-subrole-workflow regression: receptionist defaults to Expected, valid permitted URLs remain usable, Confined is hidden, no clinical/profile editing actions, check-in becomes status, cancellation only checked_in with stale refresh. Verify vets/admin retain actions and tabs.
- [x] Fix ClinicWalkinController intake using existing DailyPetQueue and ClinicAppointmentSequence; reject blocking cases before assigning another queue. Enforce locked receptionist cancellation in AdminClinicController and patient editing in PetController.
- [x] Update admin-clinic.js and clinic.html in place; guard methods as well as visibility; preserve profile history, existing modals, request sharing, debounce, and polling. Pass checked_in_at to existing walk-in confirmation. Version changed scripts at their entry pages.
- [x] Centralize Clinic processor labels on User and consume from AdminClinicController, OnlinePaymentController, and PaymentController. Verify cash role labels, webhook initiator attribution, renamed/inactive/missing users, immediate receipt and historical invoice consistency. Keep shared print template unchanged.
- [x] Run relevant frontend and PHPUnit suites, then production build. Review diff and document deployment prerequisites: upload raw pages/scripts plus generated CSS/public build assets; use existing environment; no new migration. See docs/clinic-role-workflows-deployment.md for passing checks and the independently reproduced pre-existing Grooming archive query-budget failure.

## Review focus

- A consultation starts after a receptionist opens cancellation: reject without changing the case; refresh UI.
- An active or future pre-registration exists for a saved pet: reject repeated walk-in and roll back intake side effects.
- Receptionist uses a Confined URL or calls edit/open methods directly: hide/guard frontend; reject prohibited backend writes.
- A webhook completes under a different or absent browser identity: retain gateway initiator as processor.
- Staff is renamed, inactive, removed, or has incomplete identity: use available linked identity, never invent a replacement.

## Verification

Run focused Node regressions and SQLite in-memory PHPUnit tests before/after fixes. Run npm test and npm run build for final integration. User owns browser navigation/data entry/OTP/password-reset/payment acceptance. No external provider requests in automated tests.
