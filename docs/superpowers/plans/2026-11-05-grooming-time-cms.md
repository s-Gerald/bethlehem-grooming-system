# Grooming Time CMS Implementation Plan

**Goal:** Persist optional size-specific grooming times and package-scoped haircuts throughout Services, registration, and forecasts.

**Architecture:** Extend the existing service catalogue with JSON time estimates, haircut options, and explicit legacy complete estimates. GroomingDuration remains the only calculator; booking pets retain a haircut name snapshot. The current Services modal gains a second panel and reuses the shared forecast formatter.

**Tech Stack:** Laravel 12, PHP 8.4 tests, existing Alpine/vanilla JavaScript, Tailwind and component CSS.

**Spec:** User attachment `Pasted text.txt` (System-Wide Grooming Time CMS and Haircut Management).

## Constraints
- Preserve prices, inclusions, actual timestamps, named groomers, queue order, and service-day rules.
- Admin-only writes; staff catalogue remains read-only.
- No commits, branches, deployment, simulations, or manual browser data entry.
- No invented haircut types or durations. Unknown required components yield no estimate.

## Review focus
- Legacy complete times never receive an additional haircut duration.
- Explicitly unset estimates cannot revive configuration defaults.
- Historical names survive option rename/deactivation.
- Cat checkbox clears selection while preserving instructions.
- Invalid time components and foreign/inactive haircut IDs fail server validation.

## Tasks
1. **Persist catalogue configuration** — add a forward-compatible migration, Service JSON casts, a focused GroomingTimeConfiguration validator, and BookingPet name snapshots. Verify migration preserves legacy ranges and existing data; verify time validation and admin permissions through API tests.
2. **Unify duration and haircut consumers** — update GroomingDuration, GroomingServiceSelection, GroomingPricingController, booking/walk-in creation, admin reassessment, and GroomingQueueForecast. Verify fixed/range addition, missing components, inclusions, active option validation, historical names, and forecast uncertainty/closing risk.
3. **Extend the existing editor** — modify admin-grooming-services.js, grooming.html, and admin-services.css; use a shared duration editor component for package and haircut size rows. Verify two-digit whole-number controls, optional fixed/range times, tab state, and one save action with Node regressions.
4. **Update shared booking presentation** — modify grooming-service.js, grooming-service-selection.js, grooming-estimates.js and forecast presentation. Verify cat A/B/C conditions, package switching, retained notes, draft persistence, and error-versus-unset copy.
5. **Verify and document** — run relevant PHPUnit/Node regressions and production build; inspect the modal in the single admin browser tab without submitting data. Document migration and Hostinger release commands, without deploying.

## Progress
- [x] Read requirements and inspected current shared catalogue/calculator.
- [x] Persist configuration and preserve legacy values.
- [x] Integrate registration and forecasts.
- [x] Extend editor and shared selection UI.
- [x] Verify and document deployment.
