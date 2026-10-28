# Philippine Mobile Presentation Implementation Plan

**Goal:** Show mobile inputs and recognized customer contacts as `0917 123 4567`, retaining canonical API/search/SMS values and existing validation/security policies.

**Architecture:** Extend `scripts/utils/philippine-mobile.js` outside its generated prefix block with shared grouping, recognized-number display, local unformatting, and selection-aware editing. Existing component wrappers delegate to the shared display formatter; Alpine/raw templates format at rendering boundaries. Load the same cacheable utility before classic consumers and through existing lazy module paths. Do not rewrite API responses or historical records.

## Tasks

1. Extend original signup/modal/walk-in regression scripts with progressive typing, space-adjacent deletion, selection replacement, paste/autofill/composition, 13 visible characters, canonical submissions and preserved validation errors. Confirm missing formatting fails first.
2. Extend the existing utility and adapt signup, Admin/Staff Add Customer, walk-in and OTP modal boundaries. Keep 11 actual digits, `type=tel`, numeric keyboard, exact `09XX XXX XXXX` placeholder and existing required/optional rules. Preserve masked OTP destinations/password/cooldown handling.
3. Consolidate Settings, Customers, grooming/dashboard, archives, confirmation and invoice formatters. Update clinic templates and search suggestions to use the same formatter. Preserve empty states and unrecognized legacy contact text. Invoices and print clones use the rendered formatted snapshot.
4. Trace phone searches through existing API paths; canonicalize only phone-shaped queries without changing ordinary names/references, caching or response objects. Update relevant existing regression tests and asset/import versions.
5. Run focused and complete available JavaScript/PHP tests, production build and diff checks. Review canonical payloads, unchanged SMS/provider/backend policy/data, all phone-rendering boundaries and deployment assets. Record any browser verification limitations honestly.

## Constraints

- Preserve latest local implementation; no branch, commit, push, deploy, migrations, credentials or production data changes.
- Keep policy JSON/generated data and backend business logic unchanged.
- No new dependencies, per-keystroke requests, observers, broad DOM scans, profile refetches or cache invalidations.
- Current Admin/Staff customer management has Add and read-only details; no phone Edit endpoint is introduced. Generic clinic contact/landline placeholders and OTP/reference inputs are excluded.

## Outcome

Implemented all five tasks using the existing utility and consumers. Progressive input formatting, separator editing, canonical payloads, display/print bindings and search-cache equivalence pass the updated original regressions. Complete JavaScript: 85/85 scripts; complete PHP: 1,960 tests, 25,445 assertions, eight existing environment-dependent skips; production build and whitespace checks pass. No backend/policy/provider/data changes or new dependencies. Real browser/mobile keyboard and paper-print acceptance remain unverified. Detailed file responsibilities and deployment notes are in `docs/customer-phone-verification.md`.
