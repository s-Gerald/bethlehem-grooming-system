# On-site FCFS Registration Implementation Plan

**Goal:** Remove customer arrival selection for Grooming and Clinic while preserving on-site queue assignment and existing workflows.

**Architecture:** Reuse DailyPetQueue and ClinicAppointmentSequence for serialized intake. Share automatic service-day resolution and a minimal queue-information presentation across both customer flows. Keep nullable historical window relationships readable.

**Tech Stack:** Laravel 12 / PHP, existing HTML / Tailwind / JavaScript, PHPUnit and Node regressions.

**Spec:** User attachment, “Remove Arrival Window Selection and Implement On-Site FCFS Queue Registration”.

## Constraints and decisions

- Preserve consent, pricing, staff authorization, multi-pet ordering, and capacity at check-in.
- Use Asia/Manila; before opening and during lunch retain today's valid service day, after closing or a closure move to the next eligible day. Lunch blocks physical intake. No artificial arrival time.
- Keep existing date/window APIs for historical consumers; disable customer window rescheduling and remove its UI.
- Use server created_at for registration and existing check-in timestamps for arrival.
- No manual browser submissions or functional navigation; user owns those checks.
- No production deployment, credentials changes, or destructive database commands.

## Implementation

1. Backend: add focused failing cases in existing queue and submission tests; implement automatic date resolution, aggregate service-specific waiting counts, null-window submissions, deterministic Incoming order and safe retries. Verify focused PHPUnit cases and existing MySQL harness.
2. Frontend: update existing Grooming navigation/drafts/consent and Clinic entry/reason/review; retain per-pet pricing renderer. Share supplemental Queue Information, remove calendar initialization/polling and obsolete rescheduling. Update existing Node regression coverage.
3. Presentation: align both review headings, progress, confirmation, staff timestamps and customer status/queue displays. Preserve staff walk-in confirmation and historical records. Inspect the running UI without entering data.
4. Verification: run affected regressions, full frontend/PHP suites for this cross-cutting change, production asset build, and isolated MySQL concurrency checks where available. Document actual results and deployment steps.

## Review focus

- Closing, lunch, temporary closures and multi-day closures resolve coherently without bypassing intake rules.
- Online order never controls check-in order; retries preserve timestamps and numbers.
- Multi-pet summaries preserve independent packages, extras and price ranges.
- Legacy saved drafts and browser history cannot return to a removed calendar.
- Future-day registrations show validity without suggesting today's queue predicts tomorrow.

## Completion

Implemented and verified. Full frontend: 80/80 scripts. Full PHPUnit: 1,730 tests, 23,416 assertions, 8 expected MySQL-only skips, no failures. Separate real MariaDB concurrency: 1 test / 69 assertions. Production build passed. See `docs/onsite-fcfs-registration.md` for file inventory, compatibility decisions, release steps and remaining manual checks.
