# Pet session exclusivity implementation plan

Goal: enforce actual pet identity across services while preserving independent account eligibility.

1. Replace obsolete same-pet success tests and verify failures; implement one shared policy for nonterminal conflicts and pending-online replacement.
2. Apply the policy inside DailyPetQueue in customer submissions, both walk-ins, Clinic createCase and both check-in actions. Preserve existing validations and return behavior; make all replacement writes transactional.
3. Reuse per-pet cancellation audit fields and common overall-booking cancellation, preserving untouched pets. Attribute cancellation notifications to the persisted pet and cancelled service.
4. Batch pet availability in the owner-scoped pet endpoint and staff customer lookup. Refresh the customer pet step without polling, disable blocked selections, discard invalid saved selections and fail closed on lookup errors. Extend existing staff contextual states.
5. Verify partial/last-pet cancellation, operational conflicts, rollback, notification retries and concurrent submissions. Run affected PHPUnit/frontend checks, production build, version raw assets and document deployment.

Existing skills are advisory under AGENTS.md. No commits, pushes, migrations or deployment; manual browser navigation/input belongs to the user. Modify the named intake/controllers, existing pet/notification modules and their focused tests; add only the shared PetServiceSessionPolicy and task documentation. No unrelated layout changes.
