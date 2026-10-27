# Online Registration implementation plan

**Goal:** Compact accessible service selection, authoritative today/tomorrow registration, and safe closing-time No Show processing.
**Architecture:** Extend RegistrationServiceDay and the existing queue-info endpoint. Reuse access/phone guards and one review-day client service. Reuse PreRegistrationExpiry with bounded locked transactions; preserve historical expired records.
**Stack:** Laravel, Carbon, existing JavaScript modules, Tailwind and Phosphor.
**Spec:** User attachment `Pasted text.txt`, sections 1–13.

## Constraints

- Asia/Manila; no appointment slots, reserved cages or advance queue numbers.
- Only today/tomorrow; no silent change to the reviewed visit day.
- Keep auth, phone verification, pets, consent and staff intake behavior.
- No manual browser functional testing, commits, pushes, deployment or private environment edits.
- Execute in this session; repository AGENTS.md overrides optional skill handoff conventions.

## Tasks

1. Update QueuePreRegistrationModelTest: before/during/at/after closing, midnight, independent closures, both days blocked. Confirm failures; centralize availability payload and reviewed-day validation in RegistrationServiceDay. Extend queue-info while preserving existing fields.
2. Add saved closing deadlines with an additive nullable migration. Test closing-time No Show, future visits, valid arrival evidence, terminal statuses, shortened/extended hours, administrative closures, repeat/concurrent processing. Change the existing expiry processor, scheduler description and audit action; leave historical expired data intact.
3. Require reviewed service_day in both existing submission endpoints; save deadline from configured hours. Test stale/missing day rejection before any writes; preserve guards. Share client review-day loading/storage and submission-error handling so drafts survive rollover.
4. Replace side-by-side links with equal-width accessible tabs and one natural-height information panel. Keep original blue tokens, focus, arrow/Home/End keyboard behavior, 180ms fade and reduced-motion. Load both service payloads once; switching needs no requests and never clears drafts. Preserve phone guard on Continue. Test via DOM harness without localhost interaction.
5. Update affected customer terminology/status mappings and obsolete tests. Verify PHP regression groups, frontend regressions, production build and visual-only inspection of the same browser account.
6. Document additive migration and Hostinger minute cron for schedule:run. Report actual results and unverified manual/deployment checks.

## Review focus

- Stale form submission after close or administrative closure: 409 plus explicit re-review, no writes.
- Closure history after reopening: no retroactive customer penalty for interrupted intake.
- Hours shortened after registration: persist protection for interrupted registrations, including after restoration; extended current hours extend the deadline.
- Concurrent check-in: common settings lock precedes registration locks; recheck status and arrival evidence under lock.
- Network failure / unavailable service: selector remains usable, Continue blocked, retry available; request responses never replace selected service.
