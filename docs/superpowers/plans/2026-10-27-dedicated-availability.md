# Dedicated Availability Implementation Plan

**Goal:** Move operational availability out of Settings, independently control service intake, and persist Grooming cage limits.

**Architecture:** Extend the existing ClinicSetting singleton and ClinicClosure model. Keep DailyPetQueue's shared intake transaction as the serialization boundary for admissions, closures, and capacity edits. Move Availability presentation and state into their own page/component; retain General and Security in Settings.

**Tech Stack:** Laravel, MySQL, Alpine, Tailwind, existing API caches.

**Spec:** User attachment: Codex Implementation Prompt — Dedicated Availability Page, Service-Specific Intake & Dynamic Grooming Cage Capacity.

## Constraints

- Preserve Bethlehem styling, customer registration, physical occupancy, payment/pickup, service hours and midday break.
- Legacy null-service closures apply to both services; reopening one splits the remaining closure while preserving attribution/history.
- Capacity range: 1–500 whole pets; default 20. Reject a limit below physical occupancy under the shared admission lock.
- Availability view/manage permissions belong in User::staffPermissions and existing staff middleware.
- No production deployment, push, environment edits, or manual functional browser testing.

## Tasks

- [x] Extend existing queue/capacity and authorization tests, run them red, then add migration, service filtering, dynamic capacity and Availability permissions.
  Files: ClinicSetting, ClinicClosure, OperationalCapacity, RegistrationServiceDay, AvailabilityTimeWindowService, related controllers, routes/api.php and web.php, existing feature tests.
  Verify: independent stop/reopen, legacy/global closures, next-day behavior, capacity persistence/validation/physical occupancy, all role permissions.
- [x] Extract Availability state/UI; install shared sidebar link; redirect legacy Settings links; update API cache dependencies.
  Files: pages/admin/settings.html, new Availability page/component, admin-settings.js, admin-sidebar.js, api.js, affected frontend regression sources.
  Verify: no operational reads from Settings; explicit single-service confirmation; view-only veterinarian; stale-response/duplicate-submit protection; targeted invalidation.
- [x] Run affected PHP/JavaScript suites, isolated MySQL concurrency checks, production asset build, and inspect admin page visually.
  Verify: existing workflows, query budgets, deployed asset references, mobile stacking, deployment migration instructions.

## Review Focus

- Concurrent capacity updates and admissions use the same MySQL advisory lock and singleton row lock.
- A legacy global stop remains global until a selected service is reopened; history is retained.
- Refresh responses cannot overwrite a newer mutation or expose controls after identity invalidation.
- Incoming pets and Clinic patients never consume Grooming cages; paid/finished pets remain until pickup.
- Old Settings deep links reach the new workspace without breaking Security/account links.
